// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureMessage } from '@/types/capture';
import { createLinkedInJobsController } from '@/sites/linkedin-jobs/controller';
import { OBSERVER_DEBOUNCE_MS } from '@/sites/linkedin-jobs/extract';
import { createCaptureMessageHandler } from '@/api/capture-message-handler';
import { createCaptureQueue } from '@/utils/capture-queue';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';
import { lastApiStatus } from '@/utils/session-storage';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import {
  createMemoryCredentialContextStore,
  seedCredentialContext,
} from './helpers/memory-credential-context';

const CARD_COUNT = 5;
const SEARCH_URL = 'https://www.linkedin.com/jobs/search/?keywords=Laravel';

function jobCardHtml(id: number, title: string): string {
  return `
    <li data-occludable-job-id="${id}" class="scaffold-layout__list-item">
      <div data-job-id="${id}" class="job-card-container">
        <a href="/jobs/view/${id}/" class="job-card-container__link">${title}</a>
        <div class="artdeco-entity-lockup__subtitle">Empresa ${id}</div>
        <ul class="job-card-container__metadata-wrapper"><li><span>Local ${id}</span></li></ul>
      </div>
    </li>`;
}

function mountJobsList(count = CARD_COUNT): HTMLElement {
  document.body.innerHTML = `<ul class="scaffold-layout__list-container">${Array.from(
    { length: count },
    (_, i) => jobCardHtml(i + 1, `Vaga ${i + 1}`),
  ).join('')}</ul>`;
  return document.querySelector('.scaffold-layout__list-container') as HTMLElement;
}

function openJobsPane(jobId: string | number, title: string): void {
  document.querySelector('.scaffold-layout__detail.jobs-search__job-details')?.remove();
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div class="scaffold-layout__detail jobs-search__job-details">
      <div class="jobs-search__job-details--container" data-job-id="${jobId}">
        <h1 class="job-details-jobs-unified-top-card__job-title"><a href="/jobs/view/${jobId}/">${title}</a></h1>
        <div class="job-details-jobs-unified-top-card__company-name">Empresa ${jobId}</div>
        <div id="job-details" class="jobs-description-content__text--stretch"><p>Descrição completa de ${title}.</p><p>Stack: PHP, Laravel.</p></div>
      </div>
    </div>`,
  );
}

function setupMessaging() {
  const envelopes: CaptureEnvelope[] = [];
  const contexts: string[] = [];
  const kinds: string[] = [];
  const sendMessageMock = vi
    .spyOn(fakeBrowser.runtime, 'sendMessage')
    .mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      kinds.push(message.type);

      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: 'exec-jobs-1',
            source: 'linkedin',
            surface: 'linkedin_jobs',
            initialUrl: SEARCH_URL,
            expiresAt: null,
          },
          context: 'ctx-test',
        };
      }

      if (message.type === 'capture:submit-envelope') {
        envelopes.push(message.envelope);
        contexts.push(message.context);
        return { ok: true, duplicate: false, status: 201, coverage: 'complete' };
      }

      return { ok: true, duplicate: false };
    });
  return { sendMessageMock, envelopes, contexts, kinds };
}

async function advance(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

async function activateJobs(): Promise<{
  controller: ReturnType<typeof createLinkedInJobsController>;
  envelopes: CaptureEnvelope[];
  contexts: string[];
  kinds: string[];
  deactivate: () => void;
}> {
  const messaging = setupMessaging();
  const ctx = new ContentScriptContext(`test-linkedin-jobs-${Math.random()}`);
  const controller = createLinkedInJobsController();
  let done = false;
  const active = controller.activate(ctx).then(() => {
    done = true;
  });
  for (let i = 0; i < 300 && !done; i++) await advance(200);
  await active;
  return {
    controller,
    envelopes: messaging.envelopes,
    contexts: messaging.contexts,
    kinds: messaging.kinds,
    deactivate: () => controller.deactivate(),
  };
}

describe('linkedin-jobs controller — envelope consolidado', () => {
  let spaClickHandler: (event: Event) => void;

  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL(SEARCH_URL);
    const settings = window.happyDOM.settings;
    settings.navigation.disableMainFrameNavigation = true;
    settings.navigation.disableFallbackToSetURL = true;

    spaClickHandler = (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const card = target?.closest('div[data-job-id]');
      if (!card) return;
      const jobId = card.getAttribute('data-job-id');
      const title = card.querySelector('a.job-card-container__link')?.textContent?.trim() ?? null;
      if (!jobId || !title) return;
      openJobsPane(jobId, title);
      window.happyDOM.setURL(`${SEARCH_URL}&currentJobId=${jobId}`);
    };
    document.addEventListener('click', spaClickHandler, true);
  });

  afterEach(() => {
    document.removeEventListener('click', spaClickHandler, true);
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('5 cards → um envelope com 5 cards e 5 detalhes; sem requests legados', async () => {
    mountJobsList();
    const { controller, envelopes, contexts, kinds, deactivate } = await activateJobs();
    await advance(5000);

    expect(kinds.filter((kind) => kind === 'capture:submit')).toEqual([]);
    expect(envelopes).toHaveLength(1);
    expect(contexts).toEqual(['ctx-test']);
    const envelope = envelopes[0]!;
    expect(envelope.capture).toMatchObject({
      execution_id: 'exec-jobs-1',
      source: 'linkedin',
      surface: 'linkedin_jobs',
      coverage: {
        status: 'complete',
        reason: null,
        blocks_expected: 10,
        blocks_captured: 10,
      },
    });
    expect(envelope.blocks.map((block) => block.kind)).toEqual(
      Array.from({ length: CARD_COUNT }, () => ['card', 'detail']).flat(),
    );
    for (let index = 0; index < CARD_COUNT; index++) {
      const card = envelope.blocks[index * 2]!;
      const detail = envelope.blocks[index * 2 + 1]!;
      expect(card).toMatchObject({
        kind: 'card',
        platform_id: String(index + 1),
        permalink: `https://www.linkedin.com/jobs/view/${index + 1}`,
        detail_block_id: detail.block_id,
      });
      expect(detail).toMatchObject({
        kind: 'detail',
        platform_id: String(index + 1),
        permalink: `https://www.linkedin.com/jobs/view/${index + 1}`,
        card_block_id: card.block_id,
      });
    }
    expect(envelope.capture.html).toContain('<article data-block-id="card-1"');
    expect(envelope.capture.html).toContain('<section data-block-id="detail-1"');
    expect(envelope.capture.html).toContain('data-detail-block-id="detail-1"');
    expect(envelope.capture.html).toContain('data-card-block-id="card-1"');
    expect(controller.getCaptureState()).toMatchObject({
      captured: true,
      coverage: 'complete',
      cards: CARD_COUNT,
      details: CARD_COUNT,
    });

    const list = document.querySelector('.scaffold-layout__list-container') as HTMLElement;
    list.innerHTML = list.innerHTML;
    await advance(OBSERVER_DEBOUNCE_MS);
    expect(envelopes).toHaveLength(1);
    deactivate();
  });

  it('card novo durante a coleta entra no mesmo envelope', async () => {
    mountJobsList(2);
    const { envelopes, deactivate } = await activateJobs();
    const list = document.querySelector('.scaffold-layout__list-container') as HTMLElement;
    list.insertAdjacentHTML('beforeend', jobCardHtml(999, 'Vaga nova'));
    await advance(OBSERVER_DEBOUNCE_MS + 100);
    await advance(5000);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks.filter((block) => block.kind === 'card').map((block) => block.platform_id)).toEqual([
      '1',
      '2',
      '999',
    ]);
    expect(envelopes[0]!.blocks.filter((block) => block.kind === 'detail')).toHaveLength(3);
    deactivate();
  });

  it('card hidratado só por atributos entra no mesmo envelope', async () => {
    mountJobsList(1);
    const { envelopes, deactivate } = await activateJobs();
    const list = document.querySelector('.scaffold-layout__list-container') as HTMLElement;
    list.insertAdjacentHTML(
      'beforeend',
      `<li class="scaffold-layout__list-item">
        <div class="job-card-container">
          <a class="job-card-container__link">Vaga tardia</a>
          <div class="artdeco-entity-lockup__subtitle">Empresa 777</div>
        </div>
      </li>`,
    );
    await advance(OBSERVER_DEBOUNCE_MS + 100);

    // Item já está no DOM: só os atributos chegam depois.
    const shell = list.querySelector('li:last-child .job-card-container') as HTMLElement;
    shell.setAttribute('data-job-id', '777');
    shell.querySelector('a')!.setAttribute('href', '/jobs/view/777/');
    await advance(OBSERVER_DEBOUNCE_MS + 100);
    await advance(5000);

    expect(envelopes).toHaveLength(1);
    expect(
      envelopes[0]!.blocks
        .filter((block) => block.kind === 'card')
        .map((block) => block.platform_id),
    ).toEqual(['1', '777']);
    expect(
      envelopes[0]!.blocks.filter((block) => block.kind === 'detail'),
    ).toHaveLength(2);
    deactivate();
  });

  it('detalhe antigo nunca é associado ao card novo, mesmo com título igual', async () => {
    document.removeEventListener('click', spaClickHandler, true);
    const delayedHandler = (event: Event) => {
      const target = event.target instanceof Element ? event.target : null;
      const card = target?.closest('div[data-job-id]');
      if (!card) return;
      const jobId = card.getAttribute('data-job-id');
      if (!jobId) return;
      window.happyDOM.setURL(`${SEARCH_URL}&currentJobId=${jobId}`);
      if (jobId === '2') {
        // Mantém o painel antigo (id 1) por 2 s antes de trocar para id 2.
        window.setTimeout(() => openJobsPane('2', 'Vaga igual'), 2000);
      } else {
        openJobsPane(jobId, 'Vaga igual');
      }
    };
    document.addEventListener('click', delayedHandler, true);
    mountJobsList(2);

    const { envelopes, deactivate } = await activateJobs();
    await advance(5000);

    expect(envelopes).toHaveLength(1);
    const details = envelopes[0]!.blocks.filter((block) => block.kind === 'detail');
    expect(details).toHaveLength(2);
    expect(envelopes[0]!.capture.html).toContain('Descrição completa de Vaga igual.');
    expect(envelopes[0]!.capture.html).toContain('data-platform-id="2"');
    // O detalhe 2 só pode existir depois de o painel declarar id 2.
    const htmlDetail2 = envelopes[0]!.capture.html.match(
      /<section data-block-id="detail-2"[^>]*>[\s\S]*?<\/section>/,
    )?.[0];
    expect(htmlDetail2).toBeDefined();
    expect(htmlDetail2).toContain('data-platform-id="2"');

    document.removeEventListener('click', delayedHandler, true);
    deactivate();
  });

  it('Esc cancela a coleta e envia parcial cancelled', async () => {
    mountJobsList(3);
    document.removeEventListener('click', spaClickHandler, true);
    const noPaneHandler = vi.fn();
    document.addEventListener('click', noPaneHandler, true);

    const ctx = new ContentScriptContext('test-linkedin-jobs-cancel');
    const controller = createLinkedInJobsController();
    const { envelopes } = setupMessaging();
    const active = controller.activate(ctx).catch(() => {});
    await advance(400);

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await advance(1000);
    await active;

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.capture.coverage).toMatchObject({
      status: 'partial',
      reason: 'cancelled',
    });
    expect(envelopes[0]!.blocks.filter((block) => block.kind === 'card').length).toBeGreaterThan(0);
    expect(controller.getCaptureState().captured).toBe(true);

    document.removeEventListener('click', noPaneHandler, true);
    controller.deactivate();
  });

  it('deactivate no meio do carregamento envia parcial navigation_interrupted', async () => {
    mountJobsList();
    document.removeEventListener('click', spaClickHandler, true);
    const noPaneHandler = vi.fn();
    document.addEventListener('click', noPaneHandler, true);

    const ctx = new ContentScriptContext('test-linkedin-jobs-nav');
    const controller = createLinkedInJobsController();
    const { envelopes } = setupMessaging();
    const active = controller.activate(ctx).catch(() => {});
    await advance(400);

    controller.deactivate();
    await advance(OBSERVER_DEBOUNCE_MS * 2);
    await active;

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.capture.coverage).toMatchObject({
      status: 'partial',
      reason: 'navigation_interrupted',
    });
    expect(envelopes[0]!.blocks.filter((block) => block.kind === 'card')).toHaveLength(1);

    document.removeEventListener('click', noPaneHandler, true);
  });

  it('preserva card removido do DOM sem inventar detalhe', async () => {
    mountJobsList(1);
    const { controller, envelopes, deactivate } = await activateJobs();
    const list = document.querySelector('.scaffold-layout__list-container') as HTMLElement;
    list.insertAdjacentHTML('beforeend', jobCardHtml(777, 'Vaga removida'));
    const transient = list.lastElementChild as HTMLElement;
    transient.remove();

    await advance(OBSERVER_DEBOUNCE_MS + 100);
    await advance(5000);

    expect(envelopes).toHaveLength(1);
    const card777 = envelopes[0]!.blocks.find((block) => block.platform_id === '777');
    expect(card777).toMatchObject({ kind: 'card', detail_block_id: null });
    expect(envelopes[0]!.blocks.some((block) => block.block_id === 'detail-2')).toBe(false);
    const card777Html = envelopes[0]!.capture.html.match(
      /<article data-block-id="[^"]+"[^>]*data-platform-id="777"[^>]*>/,
    )?.[0];
    expect(card777Html).toBeDefined();
    expect(card777Html).not.toContain('data-detail-block-id');
    expect(envelopes[0]!.capture.coverage).toMatchObject({
      status: 'partial',
      reason: 'timeout',
    });
    expect(controller.getCaptureState().captured).toBe(true);
    deactivate();
  });

  it('lista mista div + li-only + href-only → todos entram no envelope', async () => {
    document.body.innerHTML = `
      <ul class="scaffold-layout__list-container">
        <li data-occludable-job-id="111" class="scaffold-layout__list-item">
          <div data-job-id="111" class="job-card-container">
            <a href="/jobs/view/111/" class="job-card-container__link">Vaga Div</a>
          </div>
        </li>
        <li data-occludable-job-id="222" class="scaffold-layout__list-item">
          <div class="job-card-container">
            <a href="/jobs/view/222/" class="job-card-container__link">Vaga Li</a>
          </div>
        </li>
        <li class="scaffold-layout__list-item">
          <div class="job-card-container">
            <a href="/jobs/view/333/" class="job-card-container__link">Vaga Href</a>
          </div>
        </li>
      </ul>`;

    document.removeEventListener('click', spaClickHandler, true);
    const mixedHandler = (event: Event) => {
      const target = event.target instanceof Element ? event.target : null;
      const card = target?.closest('div[data-job-id], li[data-occludable-job-id], div.job-card-container');
      if (!card) return;
      const href = card.querySelector('a[href*="/jobs/view/"]')?.getAttribute('href') ?? '';
      const jobId = href.match(/\/jobs\/view\/(\d+)/)?.[1]
        ?? card.getAttribute('data-job-id')
        ?? card.getAttribute('data-occludable-job-id');
      const title = card.querySelector('a.job-card-container__link')?.textContent?.trim() ?? null;
      if (!jobId || !title) return;
      openJobsPane(jobId, title);
      window.happyDOM.setURL(`${SEARCH_URL}&currentJobId=${jobId}`);
    };
    document.addEventListener('click', mixedHandler, true);

    const { envelopes, deactivate } = await activateJobs();
    await advance(5000);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks.filter((block) => block.kind === 'card').map((block) => block.platform_id).sort()).toEqual([
      '111',
      '222',
      '333',
    ]);
    expect(envelopes[0]!.blocks.filter((block) => block.kind === 'detail')).toHaveLength(3);

    document.removeEventListener('click', mixedHandler, true);
    deactivate();
  });

  it('SPA troca de busca usa nova execução e não mistura a anterior', async () => {
    mountJobsList(1);
    const envelopeCalls: CaptureEnvelope[] = [];
    let run = 0;
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(
      async (raw: unknown): Promise<any> => {
        const message = raw as CaptureMessage;
        if (message.type === 'capture:resolve-run') {
          run++;
          return {
            ok: true,
            run: {
              executionId: `exec-jobs-${run}`,
              source: 'linkedin',
              surface: 'linkedin_jobs',
              initialUrl: message.url,
              expiresAt: null,
            },
            context: 'ctx-test',
          };
        }
        if (message.type === 'capture:submit-envelope') {
          envelopeCalls.push(message.envelope);
          return { ok: true, status: 201, coverage: 'complete' };
        }
        return { ok: true };
      },
    );

    const ctx = new ContentScriptContext('test-linkedin-jobs-spa');
    const controller = createLinkedInJobsController();
    let done = false;
    void controller.activate(ctx).then(() => { done = true; });
    for (let i = 0; i < 100 && !done; i++) await advance(200);
    await advance(5000);
    controller.deactivate();
    await advance(0);

    const secondSearch = 'https://www.linkedin.com/jobs/search/?keywords=QA';
    window.happyDOM.setURL(secondSearch);
    document.body.innerHTML = `<ul class="scaffold-layout__list-container">${jobCardHtml(9, 'Vaga QA')}</ul>`;
    done = false;
    void controller.activate(ctx).then(() => { done = true; });
    for (let i = 0; i < 100 && !done; i++) await advance(200);
    await advance(5000);

    expect(envelopeCalls).toHaveLength(2);
    expect(envelopeCalls[0]!.capture.execution_id).toBe('exec-jobs-1');
    expect(envelopeCalls[1]!.capture).toMatchObject({
      execution_id: 'exec-jobs-2',
      initial_url: secondSearch,
    });
    expect(envelopeCalls[1]!.blocks.map((block) => block.platform_id)).toEqual(['9', '9']);
    controller.deactivate();
  });

  it('envelope de vagas passa pela fila: troca de credencial não envia e replay limpa', async () => {
    mountJobsList(1);
    const { envelopes, deactivate } = await activateJobs();
    await advance(5000);
    const envelope = envelopes[0]!;
    expect(envelope.capture.surface).toBe('linkedin_jobs');

    await captureEnabled.setValue(true);
    await apiToken.setValue('token-a');
    await apiBaseUrl.setValue('http://localhost:8000');

    const store = createMemoryQueueStore();
    const contexts = createMemoryCredentialContextStore();
    const handlerA = createCaptureMessageHandler(createCaptureQueue(store), contexts);
    const context = await seedCredentialContext(contexts, {
      token: 'token-a',
      executionId: 'exec-jobs-1',
      source: 'linkedin',
      surface: 'linkedin_jobs',
    });
    let fetchCalls = 0;
    const bodies: CaptureEnvelope[] = [];
    vi.spyOn(globalThis, 'fetch').mockImplementation(
      async (_url: string | URL | Request, init?: RequestInit) => {
        fetchCalls++;
        bodies.push(JSON.parse((init?.body as string) ?? '{}') as CaptureEnvelope);
        if (fetchCalls === 1) throw new TypeError('offline depois do persist');
        return new Response(JSON.stringify({ id: 31, replay: true, coverage: 'complete' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
      },
    );

    const first = (await handlerA({
      type: 'capture:submit-envelope',
      envelope,
      context,
    })) as { ok: boolean; queued?: boolean };
    expect(first).toMatchObject({ ok: false, queued: true });
    expect(store.records.size).toBe(1);
    const queued = [...store.records.values()][0]!;
    expect(queued.envelope.capture).toMatchObject({
      surface: 'linkedin_jobs',
      execution_id: 'exec-jobs-1',
    });
    expect(queued.envelope.blocks.map((block) => block.kind)).toEqual(['card', 'detail']);
    vi.clearAllTimers();

    // Credencial trocada não envia o material antigo, mesmo no mesmo backend.
    await apiToken.setValue('token-b');
    await handlerA({ type: 'capture:queue-drain' });
    expect(fetchCalls).toBe(1);
    expect(store.records.size).toBe(1);

    await apiToken.setValue('token-a');
    const restarted = createCaptureMessageHandler(createCaptureQueue(store), contexts);
    const drain = (await restarted({ type: 'capture:queue-drain' })) as {
      ok: true;
      drain: { captured: number };
    };
    expect(drain.drain.captured).toBe(1);
    expect(fetchCalls).toBe(2);
    expect(store.records.size).toBe(0);
    expect(bodies[0]!.capture.idempotency_key).toBe(bodies[1]!.capture.idempotency_key);
    expect(bodies[1]!.capture).toMatchObject({
      execution_id: 'exec-jobs-1',
      surface: 'linkedin_jobs',
    });
    expect(await lastApiStatus.getValue()).toMatchObject({ ok: true, status: 200 });

    deactivate();
  });
});
