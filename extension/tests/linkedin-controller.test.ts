// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureMessage } from '@/types/capture';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { OBSERVER_DEBOUNCE_MS } from '@/sites/linkedin/extract';

/**
 * Regressão do cenário 11→14: scan inicial, callbacks repetidos do observer,
 * recriação de cards (novas refs DOM), scroll/atributos e saída de rota no
 * meio do scan. O `browser` do WXT é o fakeBrowser — o mock é nele.
 */

const CARD_COUNT = 11;

function cardHtml(urn: number, description: string): string {
  return `
    <article role="listitem">
      <div class="author"><a href="https://www.linkedin.com/in/autor${urn}">Autor ${urn}</a></div>
      <div data-testid="expandable-text-box"><p>${description}</p></div>
      <a href="https://www.linkedin.com/feed/update/urn:li:activity:${urn}?lipi=x">Ver publicação</a>
    </article>`;
}

function mountFeed(count = CARD_COUNT): HTMLElement {
  document.body.innerHTML = `<div class="feed">${Array.from(
    { length: count },
    (_, i) => cardHtml(i + 1, `Vaga ${i + 1} para desenvolvedor.`),
  ).join('')}</div>`;
  return document.querySelector('.feed') as HTMLElement;
}

/** Avança os debounce timers do controller (400 ms) e microtasks pendentes. */
async function advance(ms = OBSERVER_DEBOUNCE_MS): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

async function activateController(): Promise<{ deactivate: () => void }> {
  const ctx = new ContentScriptContext('test-linkedin');
  const controller = createLinkedInController();
  const active = controller.activate(ctx);
  await advance(0);
  await active;
  return { deactivate: () => controller.deactivate() };
}

describe('linkedin controller — integridade observer/SPA', () => {
  let sendMessageMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL('https://www.linkedin.com/search/results/content/?keywords=vaga');
    sendMessageMock = vi
      .spyOn(fakeBrowser.runtime, 'sendMessage')
      .mockImplementation(async () => ({ ok: true, duplicate: false }));
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  function sentExternalIds(): Array<string | null> {
    return sendMessageMock.mock.calls.map((call) => {
      const message = call[0] as CaptureMessage;
      return message.payload.external_id;
    });
  }

  it('11 cards → exatamente 11 requests; observer repetido/recriação/atributo não reenviam', async () => {
    mountFeed();
    const { deactivate } = await activateController();

    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);

    // Três rodadas de mutações: recriação (mesmo HTML → novas refs DOM) e
    // mudança de atributo style (scroll).
    const feed = document.querySelector('.feed') as HTMLElement;
    for (let round = 0; round < 3; round++) {
      feed.innerHTML = feed.innerHTML;
      feed.setAttribute('style', `top:${round}px`);
      await advance();
    }
    // Detalhe abre/fecha (aria-expanded muda).
    const btn = document.createElement('button');
    btn.setAttribute('aria-expanded', 'false');
    feed.appendChild(btn);
    await advance();
    btn.setAttribute('aria-expanded', 'true');
    await advance();

    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);
    expect(new Set(sentExternalIds()).size).toBe(CARD_COUNT);
    deactivate();
  });

  it('item novo pós-scroll gera exatamente 1 request adicional', async () => {
    mountFeed();
    const { deactivate } = await activateController();
    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT);

    const feed = document.querySelector('.feed') as HTMLElement;
    feed.insertAdjacentHTML('beforeend', cardHtml(999, 'Vaga nova após scroll.'));
    await advance();

    expect(sendMessageMock).toHaveBeenCalledTimes(CARD_COUNT + 1);
    expect(sentExternalIds().at(-1)).toBe('urn:li:activity:999');
    deactivate();
  });

  it('deactivate durante scan travado em await: geração antiga não envia nem agenda rescan', async () => {
    let release!: (value: unknown) => void;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    let calls = 0;
    sendMessageMock.mockImplementation(async () => {
      calls++;
      if (calls === 1) await gate;
      return { ok: true, duplicate: false };
    });

    mountFeed();

    // Activate NÃO é aguardado: o scan inicial trava no primeiro request.
    const ctx = new ContentScriptContext('test-linkedin');
    const controller = createLinkedInController();
    void controller.activate(ctx).catch(() => {});
    await advance(0);

    // Scan inicial parou no primeiro sendMessage (gate fechado).
    expect(calls).toBe(1);

    // Observer dispara e enfileira rescan enquanto o lote está travado.
    document.body.setAttribute('data-x', '1');
    await advance();

    // Saída de rota no meio do scan; depois solta o request travado.
    window.happyDOM.setURL('https://www.linkedin.com/feed/');
    controller.deactivate();
    release(undefined);
    await advance(OBSERVER_DEBOUNCE_MS * 2);

    // Nenhum envio além do que já estava em voo; nenhum rescan agendado.
    expect(calls).toBe(1);
  });

  it('múltiplos ciclos activate/deactivate não acumulam observers', async () => {
    mountFeed();
    const observeSpy = vi.spyOn(MutationObserver.prototype, 'observe');

    for (let cycle = 0; cycle < 3; cycle++) {
      window.happyDOM.setURL('https://www.linkedin.com/search/results/content/?keywords=vaga');
      const { deactivate } = await activateController();
      deactivate();
      window.happyDOM.setURL('https://www.linkedin.com/feed/');
    }

    // Um observe por ciclo — sem acúmulo entre entradas/saídas SPA.
    expect(observeSpy).toHaveBeenCalledTimes(3);
  });

  it('card sem identidade nenhuma vira erro sem enviar', async () => {
    document.body.innerHTML = `<div class="feed">
      <article role="listitem">
        <div data-testid="expandable-text-box"><p>Só texto, sem urn nem url.</p></div>
      </article>
    </div>`;
    const { deactivate } = await activateController();
    // Fingerprint composto é derivado e enviado uma vez.
    expect(sendMessageMock).toHaveBeenCalledTimes(1);
    const message = sendMessageMock.mock.calls[0][0] as CaptureMessage;
    expect(message.payload.external_id).toBeNull();
    expect(message.payload.url).toBeNull();
    deactivate();

    // Recriação do mesmo card: mesma identidade (fingerprint), não reenvia.
    document.querySelector('.feed')?.remove();
    document.body.insertAdjacentHTML(
      'beforeend',
      `<div class="feed">
        <article role="listitem">
          <div data-testid="expandable-text-box"><p>Só texto, sem urn nem url.</p></div>
        </article>
      </div>`,
    );
    await advance();
    expect(sendMessageMock).toHaveBeenCalledTimes(1);
  });
});
