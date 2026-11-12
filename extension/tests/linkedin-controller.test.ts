// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureMessage } from '@/types/capture';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { OBSERVER_DEBOUNCE_MS } from '@/sites/linkedin/extract';

/**
 * Regressão do cenário 11→14 sob o contrato de envelope: scan inicial,
 * callbacks repetidos do observer, recriação de cards (novas refs DOM),
 * scroll/atributos e saída de rota no meio do scan. O browser do WXT é o
 * fakeBrowser — o mock é nele.
 */

const CARD_COUNT = 11;
const SEARCH_URL = 'https://www.linkedin.com/search/results/content/?keywords=vaga';

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

async function advance(ms = OBSERVER_DEBOUNCE_MS): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

function setupMessaging() {
  const envelopes: CaptureEnvelope[] = [];
  const sendMessageMock = vi
    .spyOn(fakeBrowser.runtime, 'sendMessage')
    .mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: 'exec-1',
            source: 'linkedin',
            surface: 'linkedin_publications',
            initialUrl: SEARCH_URL,
            expiresAt: null,
          },
          context: 'ctx-test',
        };
      }
      if (message.type === 'capture:submit-envelope') {
        envelopes.push(message.envelope);
        return { ok: true, duplicate: false, status: 201, coverage: 'complete' };
      }
      return { ok: true, duplicate: false };
    });
  return { sendMessageMock, envelopes };
}

async function activateController(): Promise<{
  controller: ReturnType<typeof createLinkedInController>;
  deactivate: () => void;
}> {
  const ctx = new ContentScriptContext(`test-linkedin-${Math.random()}`);
  const controller = createLinkedInController();
  const active = controller.activate(ctx);
  await advance(0);
  await active;
  return { controller, deactivate: () => controller.deactivate() };
}

describe('linkedin controller — integridade observer/SPA', () => {
  let envelopes: CaptureEnvelope[];

  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL(SEARCH_URL);
    vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
    vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});
    ({ envelopes } = setupMessaging());
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('11 cards → um envelope com 11 blocos; recriação/atributo não duplicam', async () => {
    mountFeed();
    const { controller, deactivate } = await activateController();
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toHaveLength(CARD_COUNT);
    expect(new Set(envelopes[0]!.blocks.map((block) => block.platform_id)).size).toBe(CARD_COUNT);

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

    expect(envelopes).toHaveLength(1);
    expect(controller.getCaptureState()).toMatchObject({ captured: true, blocks: CARD_COUNT });
    deactivate();
  });

  it('item novo durante o scroll entra no mesmo envelope consolidado', async () => {
    mountFeed();
    const { deactivate } = await activateController();
    await advance(1000);

    const feed = document.querySelector('.feed') as HTMLElement;
    feed.insertAdjacentHTML('beforeend', cardHtml(999, 'Vaga nova após scroll.'));
    await advance();

    await advance(1200 * 8);
    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toHaveLength(CARD_COUNT + 1);
    expect(envelopes[0]!.blocks.at(-1)!.platform_id).toBe('urn:li:activity:999');
    deactivate();
  });

  it('deactivate durante o scroll envia parcial navigation_interrupted uma única vez', async () => {
    mountFeed();
    const { deactivate } = await activateController();
    await advance(1000);

    deactivate();
    await advance(OBSERVER_DEBOUNCE_MS * 2);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.capture.coverage).toEqual({
      status: 'partial',
      reason: 'navigation_interrupted',
      blocks_expected: null,
      blocks_captured: CARD_COUNT,
    });

    // Nenhum rescan/finalização posterior cria segundo envelope.
    await advance(OBSERVER_DEBOUNCE_MS * 4);
    deactivate();
    await advance(0);
    expect(envelopes).toHaveLength(1);
  });

  it('múltiplos ciclos activate/deactivate não acumulam observers', async () => {
    mountFeed();
    const observeSpy = vi.spyOn(MutationObserver.prototype, 'observe');

    for (let cycle = 0; cycle < 3; cycle++) {
      window.happyDOM.setURL(SEARCH_URL);
      const { deactivate } = await activateController();
      deactivate();
      await advance(0);
      window.happyDOM.setURL('https://www.linkedin.com/feed/');
    }

    // Um observe por ciclo — sem acúmulo entre entradas/saídas SPA.
    expect(observeSpy).toHaveBeenCalledTimes(3);
  });

  it('preserva texto sem identidade e não inventa ID/permalink', async () => {
    document.body.innerHTML = `<div class="feed">
      <article role="listitem">
        <div data-testid="expandable-text-box"><p>Só texto, sem urn nem url.</p></div>
      </article>
    </div>`;
    const { controller, deactivate } = await activateController();
    await advance(1200 * 8);

    expect(envelopes).toHaveLength(1);
    expect(envelopes[0]!.blocks).toMatchObject([
      { kind: 'post', platform_id: null, permalink: null },
    ]);
    expect(envelopes[0]!.capture.html).toContain('Só texto, sem urn nem url.');
    expect(envelopes[0]!.capture.html).not.toMatch(/data-platform-id|data-permalink/);
    expect(envelopes[0]!.capture.coverage).toMatchObject({
      status: 'complete',
      reason: null,
      blocks_captured: 1,
    });
    expect(controller.getCaptureState()).toMatchObject({ captured: true, blocks: 1 });
    deactivate();
  });
});
