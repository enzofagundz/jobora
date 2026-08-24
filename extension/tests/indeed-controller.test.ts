// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureMessage } from '@/types/capture';
import { createIndeedController } from '@/sites/indeed/controller';

/**
 * Regressão do Indeed: claim ANTES do clique, waitForPane morre na troca de
 * geração/rota, fila serial. O clique é simulado como SPA (vjk na URL + painel
 * com o título do card), como no Indeed real.
 */

const CARD_COUNT = 11;

function mountList(count = CARD_COUNT): HTMLElement {
  const cards = Array.from({ length: count }, (_, i) =>
    `<a data-jk="jk${i + 1}" href="/rc/clk?jk=jk${i + 1}"><h2><span title="Vaga ${i + 1}">Vaga ${i + 1}</span></h2></a>`,
  ).join('');
  document.body.innerHTML = `<ul class="jobsearch-ResultsList">${cards}</ul>`;
  return document.querySelector('.jobsearch-ResultsList') as HTMLElement;
}

/** Painel lateral da vaga clicada: título = cardTitle (comportamento SPA). */
function openPane(title: string): void {
  document.getElementById('jobsearch-ViewjobPaneWrapper')?.remove();
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div id="jobsearch-ViewjobPaneWrapper">
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

    sendMessageMock = vi
      .spyOn(fakeBrowser.runtime, 'sendMessage')
      .mockImplementation(async () => ({ ok: true, duplicate: false }));

    // Clique no card = SPA: URL ganha ?vjk=<jk> e o painel abre com o título.
    spaClickHandler = (e) => {
      const target = e.target instanceof Element ? e.target : null;
      const anchor = target?.closest('a[data-jk]');
      if (!anchor) return;
      const jk = anchor.getAttribute('data-jk');
      const title = anchor.querySelector('[title]')?.getAttribute('title') ?? null;
      window.happyDOM.setURL(`https://br.indeed.com/jobs?q=php&vjk=${jk}`);
      if (title) openPane(title);
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
    return sendMessageMock.mock.calls.map((call) => {
      const message = call[0] as CaptureMessage;
      return message.payload.external_id ?? '';
    });
  }

  /** Roda a ativação até resolver sem exceder tempo simulado generoso. */
  async function activateUntilDone(): Promise<{ deactivate: () => void }> {
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
    return { deactivate: () => controller.deactivate() };
  }

  it('11 cards → exatamente 11 requests; recriação não reclica', async () => {
    mountList();
    const { deactivate } = await activateUntilDone();

    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);
    expect(new Set(sentJks()).size).toBe(CARD_COUNT);

    // Recriação da lista (novas refs DOM, mesmos jks) → nada novo.
    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.innerHTML = list.innerHTML;
    await advance(OBSERVER_DEBOUNCE);
    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);

    deactivate();
  });

  it('item novo pós-scan gera exatamente 1 request adicional', async () => {
    mountList();
    const { deactivate } = await activateUntilDone();
    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);

    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.insertAdjacentHTML(
      'beforeend',
      '<a data-jk="jknovo" href="/rc/clk?jk=jknovo"><h2><span title="Vaga nova">Vaga nova</span></h2></a>',
    );
    let done = false;
    let resolveDone!: () => void;
    const settled = new Promise<void>((r) => {
      resolveDone = r;
    });
    sendMessageMock.mockImplementationOnce(async () => {
      done = true;
      resolveDone();
      return { ok: true, duplicate: false };
    });
    // O observer dispara o rescan; espera o request novo sair.
    for (let i = 0; i < 50 && !done; i++) await advance(200);
    await settled;

    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT + 1);
    expect(sentJks().at(-1)).toBe('jknovo');
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

    // Nenhum request saiu (painel nunca ficou pronto antes da saída).
    expect(sendMessageMock).not.toHaveBeenCalled();
  });

  it('card sem data-jk é erro sem enviar e não reconta em recriação', async () => {
    document.body.innerHTML =
      '<ul class="jobsearch-ResultsList"><a class="jcs-JobTitle"><h2>Sem jk</h2></a></ul>';
    const ctx = new ContentScriptContext('test-indeed');
    const controller = createIndeedController();
    const active = controller.activate(ctx);
    await advance(OBSERVER_DEBOUNCE);
    await active;

    expect(sendMessageMock).not.toHaveBeenCalled();

    // Recriação do card inválido: WeakSet evita recontagem.
    const list = document.querySelector('.jobsearch-ResultsList') as HTMLElement;
    list.innerHTML = list.innerHTML;
    await advance(OBSERVER_DEBOUNCE);
    expect(sendMessageMock).not.toHaveBeenCalled();
    controller.deactivate();
  });
});

const OBSERVER_DEBOUNCE = 400;
