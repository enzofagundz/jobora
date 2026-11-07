// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureMessage } from '@/types/capture';
import { createIndeedController } from '@/sites/indeed/controller';
import { createCaptureMessageHandler } from '@/api/capture-message-handler';
import { createCaptureQueue } from '@/utils/capture-queue';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import {
  createMemoryCredentialContextStore,
  seedCredentialContext,
} from './helpers/memory-credential-context';

/**
 * Regressão do Indeed: claim ANTES do clique, waitForPane morre na troca de
 * geração/rota, fila serial. O clique é simulado como SPA (vjk na URL + painel
 * com o título do card), como no Indeed real.
 */

const CARD_COUNT = 11;
const SEARCH_URL = 'https://br.indeed.com/jobs?q=php';

function mountList(count = CARD_COUNT): HTMLElement {
  const cards = Array.from({ length: count }, (_, i) =>
    `<a data-jk="jk${i + 1}" href="/rc/clk?jk=jk${i + 1}"><h2><span title="Vaga ${i + 1}">Vaga ${i + 1}</span></h2></a>`,
  ).join('');
  document.body.innerHTML = `<ul class="jobsearch-ResultsList">${cards}</ul>`;
  return document.querySelector('.jobsearch-ResultsList') as HTMLElement;
}

/** Painel lateral da vaga clicada: título = cardTitle (comportamento SPA). */
function openPane(title: string, jk?: string | null): void {
  document.getElementById('jobsearch-ViewjobPaneWrapper')?.remove();
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div id="jobsearch-ViewjobPaneWrapper"${jk ? ` data-jk="${jk}"` : ''}>
      <h1 data-testid="jobsearch-JobInfoHeader-title">${title}</h1>
      <div id="jobDescriptionText"><p>Descrição completa.</p></div>
    </div>`,
  );
}

async function advance(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

describe('indeed controller — integridade observer/SPA', () => {
  let sendMessageMock: ReturnType<typeof vi.fn>;
  let spaClickHandler: (e: Event) => void;

  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL('https://br.indeed.com/jobs?q=php');
    // happy-dom navegaria de verdade no clique; o Indeed real é SPA.
    const settings = window.happyDOM.settings;
    settings.navigation.disableMainFrameNavigation = true;
    settings.navigation.disableFallbackToSetURL = true;

    sendMessageMock = vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: 'exec-indeed-test',
            source: 'indeed',
            surface: 'indeed_jobs',
            initialUrl: message.url,
            expiresAt: null,
          },
          context: 'ctx-indeed-test',
        };
      }
      if (message.type === 'capture:submit-envelope') {
        return { ok: true, duplicate: false, status: 201, coverage: message.envelope.capture.coverage.status };
      }
      return { ok: true, duplicate: false };
    });

    // Clique no card = SPA: URL ganha ?vjk=<jk> e o painel abre com o título.
    spaClickHandler = (e) => {
      const target = e.target instanceof Element ? e.target : null;
      const anchor = target?.closest('a[data-jk]');
      if (!anchor) return;
      const jk = anchor.getAttribute('data-jk');
      const title = anchor.querySelector('[title]')?.getAttribute('title') ?? null;
      window.happyDOM.setURL(`https://br.indeed.com/jobs?q=php&vjk=${jk}`);
      if (title) openPane(title, jk);
    };
    document.addEventListener('click', spaClickHandler, true);
  });

  afterEach(() => {
    document.removeEventListener('click', spaClickHandler, true);
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  function sentJks(): string[] {
    return sendMessageMock.mock.calls.flatMap((call) => {
      const message = call[0] as CaptureMessage;
      return message.type === 'capture:submit-envelope'
        ? message.envelope.blocks
            .filter((block) => block.kind === 'card')
            .map((block) => block.platform_id)
        : [];
    });
  }

  /** Roda a ativação até resolver sem exceder tempo simulado generoso. */
  async function activateUntilDone(): Promise<{
    controller: ReturnType<typeof createIndeedController>;
    deactivate: () => void;
  }> {
    const ctx = new ContentScriptContext('test-indeed');
    const controller = createIndeedController();
    let done = false;
    const active = controller.activate(ctx).then(() => {
      done = true;
    });
    for (let i = 0; i < 300 && !done; i++) {
      await advance(200);
    }
    await active;
    return { controller, deactivate: () => controller.deactivate() };
  }

  it('11 cards → um envelope com cards e detalhes; recriação não duplica', async () => {
    mountList();
    const { controller, deactivate } = await activateUntilDone();
    await advance(3_000);

    expect(sendMessageMock.mock.calls.filter(([message]) => (message as CaptureMessage).type === 'capture:submit-envelope')).toHaveLength(1);
    expect(new Set(sentJks()).size).toBe(CARD_COUNT);

    // Recriação da lista (novas refs DOM, mesmos jks) → nada novo.
    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.innerHTML = list.innerHTML;
    await advance(OBSERVER_DEBOUNCE);
    expect(sendMessageMock.mock.calls.filter(([message]) => (message as CaptureMessage).type === 'capture:submit-envelope')).toHaveLength(1);

    deactivate();
  });

  it('resolve execução e envia um envelope Indeed v1 com card e detalhe correlacionados', async () => {
    mountList(2);
    window.happyDOM.setURL(SEARCH_URL);
    const messages: CaptureMessage[] = [];
    sendMessageMock.mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      messages.push(message);
      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: 'exec-indeed-1',
            source: 'indeed',
            surface: 'indeed_jobs',
            initialUrl: SEARCH_URL,
            expiresAt: null,
          },
          context: 'ctx-indeed',
        };
      }
      if (message.type === 'capture:submit-envelope') {
        return { ok: true, status: 201, coverage: message.envelope.capture.coverage.status };
      }
      return { ok: true };
    });

    const { controller, deactivate } = await activateUntilDone();
    await advance(3_000);

    const submissions = messages.filter(
      (message): message is Extract<CaptureMessage, { type: 'capture:submit-envelope' }> =>
        message.type === 'capture:submit-envelope',
    );
    expect(controller.getCaptureState()).toMatchObject({ cards: 2, executionId: 'exec-indeed-1', captured: true });
    expect(messages.map((message) => message.type)).toEqual([
      'capture:resolve-run',
      'capture:submit-envelope',
    ]);
    expect(sendMessageMock.mock.calls.map(([message]) => (message as CaptureMessage).type)).not.toContain('capture:submit');
    expect(submissions).toHaveLength(1);
    const envelope: CaptureEnvelope = submissions[0]!.envelope;
    expect(envelope).toMatchObject({
      format: 'jobora.capture-envelope',
      version: 1,
      capture: {
        execution_id: 'exec-indeed-1',
        source: 'indeed',
        surface: 'indeed_jobs',
        initial_url: SEARCH_URL,
        coverage: { status: 'complete', reason: null, blocks_expected: 4, blocks_captured: 4 },
      },
      blocks: [
        { block_id: 'card-1', kind: 'card', platform_id: 'jk1', permalink: 'https://br.indeed.com/viewjob?jk=jk1', detail_block_id: 'detail-1' },
        { block_id: 'detail-1', kind: 'detail', platform_id: 'jk1', permalink: 'https://br.indeed.com/viewjob?jk=jk1', card_block_id: 'card-1' },
        { block_id: 'card-2', kind: 'card', platform_id: 'jk2', permalink: 'https://br.indeed.com/viewjob?jk=jk2', detail_block_id: 'detail-2' },
        { block_id: 'detail-2', kind: 'detail', platform_id: 'jk2', permalink: 'https://br.indeed.com/viewjob?jk=jk2', card_block_id: 'card-2' },
      ],
    });
    expect(envelope.capture.final_url).toBe(`${SEARCH_URL}&vjk=jk2`);
    expect(envelope.capture.html).toContain('data-capture-surface="indeed_jobs"');
    expect(envelope.capture.html).toContain('Descrição completa.');
    expect(submissions[0]!.context).toBe('ctx-indeed');

    deactivate();
  });

  it('painel antigo sem jk não vira detalhe do card seguinte', async () => {
    mountList(2);
    document.removeEventListener('click', spaClickHandler, true);
    const stalePaneHandler = (event: Event) => {
      const target = event.target instanceof Element ? event.target : null;
      const card = target?.closest('a[data-jk]');
      const jk = card?.getAttribute('data-jk');
      if (!jk) return;
      window.happyDOM.setURL(`${SEARCH_URL}&vjk=${jk}`);
      if (jk === 'jk1') openPane('Vaga igual');
    };
    document.addEventListener('click', stalePaneHandler, true);

    const { deactivate } = await activateUntilDone();
    await advance(3_000);

    const envelope = sendMessageMock.mock.calls
      .map(([message]) => message as CaptureMessage)
      .find((message) => message.type === 'capture:submit-envelope');
    expect(envelope?.type).toBe('capture:submit-envelope');
    if (envelope?.type === 'capture:submit-envelope') {
      expect(envelope.envelope.blocks.map((block) => [block.kind, block.platform_id])).toEqual([
        ['card', 'jk1'],
        ['detail', 'jk1'],
        ['card', 'jk2'],
      ]);
      expect(envelope.envelope.capture.coverage).toMatchObject({
        status: 'partial',
        reason: 'timeout',
        blocks_expected: 4,
        blocks_captured: 3,
      });
    }

    document.removeEventListener('click', stalePaneHandler, true);
    deactivate();
  });

  it('descrição fora do painel não preenche detalhe ausente da vaga', async () => {
    mountList(1);
    document.removeEventListener('click', spaClickHandler, true);
    const missingDescriptionHandler = (event: Event) => {
      const target = event.target instanceof Element ? event.target : null;
      const card = target?.closest('a[data-jk]');
      const jk = card?.getAttribute('data-jk');
      if (!jk) return;
      window.happyDOM.setURL(`${SEARCH_URL}&vjk=${jk}`);
      document.body.insertAdjacentHTML(
        'beforeend',
        `<div id="jobsearch-ViewjobPaneWrapper" data-jk="${jk}"><h1 data-testid="jobsearch-JobInfoHeader-title">Vaga 1</h1></div><div id="jobDescriptionText">Descrição antiga fora do painel.</div>`,
      );
    };
    document.addEventListener('click', missingDescriptionHandler, true);

    const { deactivate } = await activateUntilDone();
    await advance(3_000);
    const submitted = sendMessageMock.mock.calls
      .map(([message]) => message as CaptureMessage)
      .find((message) => message.type === 'capture:submit-envelope');

    expect(submitted?.type).toBe('capture:submit-envelope');
    if (submitted?.type === 'capture:submit-envelope') {
      expect(submitted.envelope.blocks.map((block) => block.kind)).toEqual(['card']);
      expect(submitted.envelope.capture.coverage).toMatchObject({ status: 'partial', reason: 'timeout' });
      expect(submitted.envelope.capture.html).not.toContain('Descrição antiga fora do painel.');
    }
    document.removeEventListener('click', missingDescriptionHandler, true);
    deactivate();
  });

  it('envelope Indeed fica na fila após falha e replay reusa mesma chave e execução', async () => {
    mountList(1);
    await captureEnabled.setValue(true);
    await apiToken.setValue('token-indeed-a');
    await apiBaseUrl.setValue('http://localhost:8000');

    const store = createMemoryQueueStore();
    const contexts = createMemoryCredentialContextStore();
    const context = await seedCredentialContext(contexts, {
      token: 'token-indeed-a',
      executionId: 'exec-indeed-test',
      source: 'indeed',
      surface: 'indeed_jobs',
    });
    const handler = createCaptureMessageHandler(createCaptureQueue(store), contexts);
    const bodies: CaptureEnvelope[] = [];
    let fetchCalls = 0;
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (_url, init) => {
      fetchCalls++;
      bodies.push(JSON.parse((init?.body as string) ?? '{}') as CaptureEnvelope);
      if (fetchCalls === 1) throw new TypeError('offline depois do persist');
      return new Response(JSON.stringify({ id: 31, replay: true, coverage: 'complete' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    });
    sendMessageMock.mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: 'exec-indeed-test',
            source: 'indeed',
            surface: 'indeed_jobs',
            initialUrl: message.url,
            expiresAt: null,
          },
          context,
        };
      }
      return handler(message);
    });

    const { controller, deactivate } = await activateUntilDone();
    await advance(3_000);
    await vi.waitFor(() => {
      expect(controller.getCaptureState()).toMatchObject({ captured: false, pending: false });
      expect(store.records.size).toBe(1);
    });
    const queued = [...store.records.values()][0]!;
    expect(queued.envelope.capture).toMatchObject({
      source: 'indeed',
      surface: 'indeed_jobs',
      execution_id: 'exec-indeed-test',
    });

    await apiToken.setValue('token-indeed-b');
    await handler({ type: 'capture:queue-drain' });
    expect(fetchCalls).toBe(1);
    expect(store.records.size).toBe(1);

    await apiToken.setValue('token-indeed-a');
    const restarted = createCaptureMessageHandler(createCaptureQueue(store), contexts);
    const drain = await restarted({ type: 'capture:queue-drain' });
    expect(drain).toMatchObject({ ok: true, drain: { captured: 1 } });
    expect(fetchCalls).toBe(2);
    expect(store.records.size).toBe(0);
    expect(bodies[0]!.capture.idempotency_key).toBe(bodies[1]!.capture.idempotency_key);
    expect(bodies[1]!.capture.execution_id).toBe('exec-indeed-test');

    deactivate();
  });

  it('falha de acesso na resolução não envia captura nem associa execução', async () => {
    mountList(1);
    const messages: CaptureMessage[] = [];
    sendMessageMock.mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      messages.push(message);
      return message.type === 'capture:resolve-run'
        ? { ok: false, error: 'auth' }
        : { ok: true };
    });

    const { controller, deactivate } = await activateUntilDone();
    await advance(3_000);

    expect(messages.map((message) => message.type)).toEqual(['capture:resolve-run']);
    expect(controller.getCaptureState()).toMatchObject({
      captured: false,
      executionId: null,
      cards: 0,
      details: 0,
    });
    deactivate();
  });

  it('preserva card visto no DOM virtualizado mesmo removido antes do scan', async () => {
    mountList(1);
    const { deactivate } = await activateUntilDone();
    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.insertAdjacentHTML(
      'beforeend',
      '<a data-jk="jk-virtual" href="/rc/clk?jk=jk-virtual"><h2>Vaga virtual</h2></a>',
    );
    list.lastElementChild?.remove();
    await advance(OBSERVER_DEBOUNCE + 3_000);

    const submitted = sendMessageMock.mock.calls
      .map(([message]) => message as CaptureMessage)
      .find((message) => message.type === 'capture:submit-envelope');
    expect(submitted?.type).toBe('capture:submit-envelope');
    if (submitted?.type === 'capture:submit-envelope') {
      expect(submitted.envelope.blocks.filter((block) => block.kind === 'card').map((block) => block.platform_id)).toEqual([
        'jk1',
        'jk-virtual',
      ]);
      expect(submitted.envelope.blocks.find((block) => block.platform_id === 'jk-virtual')).toMatchObject({
        kind: 'card',
        detail_block_id: null,
      });
      expect(submitted.envelope.capture.coverage).toMatchObject({ status: 'partial', reason: 'timeout' });
    }
    deactivate();
  });

  it('espera resolver execução antes de capturar mutações da página', async () => {
    mountList(1);
    let releaseResolve!: (result: unknown) => void;
    const resolution = new Promise<unknown>((resolve) => {
      releaseResolve = resolve;
    });
    sendMessageMock.mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') return resolution;
      if (message.type === 'capture:submit-envelope') {
        return { ok: true, status: 201, coverage: message.envelope.capture.coverage.status };
      }
      return { ok: true };
    });
    const clickedJks: string[] = [];
    const observeClick = (event: Event) => {
      const target = event.target instanceof Element ? event.target : null;
      const card = target?.closest('a[data-jk]');
      const jk = card?.getAttribute('data-jk');
      if (jk) clickedJks.push(jk);
    };
    document.addEventListener('click', observeClick, true);

    const ctx = new ContentScriptContext('test-indeed-resolution-gate');
    const controller = createIndeedController();
    let activated = false;
    const active = controller.activate(ctx).then(() => {
      activated = true;
    });
    await advance(100);
    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.insertAdjacentHTML(
      'beforeend',
      '<a data-jk="jk-during-resolution" href="/rc/clk?jk=jk-during-resolution"><h2>Vaga durante resolução</h2></a>',
    );
    await advance(1_000);

    expect(clickedJks).toEqual([]);
    expect(activated).toBe(false);

    releaseResolve({
      ok: true,
      run: {
        executionId: 'exec-indeed-test',
        source: 'indeed',
        surface: 'indeed_jobs',
        initialUrl: SEARCH_URL,
        expiresAt: null,
      },
      context: 'ctx-indeed-test',
    });
    for (let index = 0; index < 300 && !activated; index++) await advance(200);
    await active;
    await advance(3_000);

    expect(clickedJks).toContain('jk-during-resolution');
    expect(sendMessageMock.mock.calls.some(([message]) => (message as CaptureMessage).type === 'capture:submit-envelope')).toBe(true);
    document.removeEventListener('click', observeClick, true);
    controller.deactivate();
  });

  it('item novo pós-scan gera exatamente 1 request adicional', async () => {
    mountList(2);
    const { deactivate } = await activateUntilDone();

    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.insertAdjacentHTML(
      'beforeend',
      '<a data-jk="jknovo" href="/rc/clk?jk=jknovo"><h2><span title="Vaga nova">Vaga nova</span></h2></a>',
    );
    await advance(OBSERVER_DEBOUNCE + 3_000);

    expect(sendMessageMock.mock.calls.filter(([message]) => (message as CaptureMessage).type === 'capture:submit-envelope')).toHaveLength(1);
    expect(sentJks()).toContain('jknovo');
    deactivate();
  });

  it('waitForPane resolve false quando a rota sai; erro terminal sem envio', async () => {
    mountList();
    // NENHUM painel montado e nenhum clique tratado: o waitForPane fica
    // travado no polling. Activate NÃO é aguardado.
    document.removeEventListener('click', spaClickHandler, true);
    const ctx = new ContentScriptContext('test-indeed');
    const controller = createIndeedController();
    const active = controller.activate(ctx).catch(() => {});
    await advance(400);

    // Sai da rota no meio do primeiro waitForPane.
    window.happyDOM.setURL('https://br.indeed.com/');
    controller.deactivate();
    // O polling do waitForPane resolve false na próxima checagem de geração.
    for (let i = 0; i < 20; i++) await advance(200);
    await active;

    const submitted = sendMessageMock.mock.calls
      .map(([message]) => message as CaptureMessage)
      .find((message) => message.type === 'capture:submit-envelope');
    expect(submitted?.type).toBe('capture:submit-envelope');
    if (submitted?.type === 'capture:submit-envelope') {
      expect(submitted.envelope.capture.coverage).toMatchObject({ status: 'partial', reason: 'navigation_interrupted' });
    }
  });

  it('card sem data-jk é erro sem enviar e não reconta em recriação', async () => {
    document.body.innerHTML =
      '<ul class="jobsearch-ResultsList"><a class="jcs-JobTitle"><h2>Sem jk</h2></a></ul>';
    const ctx = new ContentScriptContext('test-indeed');
    const controller = createIndeedController();
    const active = controller.activate(ctx);
    await advance(OBSERVER_DEBOUNCE);
    await active;

    await advance(1_600);
    const submitted = sendMessageMock.mock.calls
      .map(([message]) => message as CaptureMessage)
      .find((message) => message.type === 'capture:submit-envelope');
    expect(submitted?.type).toBe('capture:submit-envelope');
    if (submitted?.type === 'capture:submit-envelope') {
      expect(submitted.envelope.capture.coverage).toMatchObject({ status: 'partial', reason: 'no_content' });
      expect(submitted.envelope.blocks).toEqual([]);
    }

    // Recriação do card inválido: WeakSet evita recontagem.
    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.innerHTML = list.innerHTML;
    await advance(OBSERVER_DEBOUNCE);
    expect(sendMessageMock.mock.calls.filter(([message]) => (message as CaptureMessage).type === 'capture:submit-envelope')).toHaveLength(1);
    controller.deactivate();
  });
});

const OBSERVER_DEBOUNCE = 400;
