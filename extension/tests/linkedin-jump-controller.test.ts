// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { OBSERVER_DEBOUNCE_MS } from '@/sites/linkedin/extract';

function cardHtml(urn: number): string {
  return `
    <article role="listitem">
      <div data-testid="expandable-text-box"><p>Post ${urn}</p></div>
      <a href="https://www.linkedin.com/feed/update/urn:li:activity:${urn}">Ver</a>
    </article>`;
}

function mountFeed(count: number): void {
  document.body.innerHTML = `<div class="feed">${Array.from(
    { length: count },
    (_, i) => cardHtml(i + 1),
  ).join('')}</div>`;
}

async function advance(ms = OBSERVER_DEBOUNCE_MS): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms);
}

describe('linkedin controller — jump ao fim', () => {
  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    window.happyDOM.setURL('https://www.linkedin.com/search/results/content/?keywords=vaga');
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(
      async () => ({ ok: true, duplicate: false }),
    );
    vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
    vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('sem interação prévia dispara jump e termina no último post', async () => {
    mountFeed(3);
    const ctx = new ContentScriptContext('test-jump');
    const controller = createLinkedInController();
    const active = controller.activate(ctx);
    await advance(0);
    await active;
    // Jump usa settle de 1200ms por ciclo × 6 ciclos estáveis.
    await vi.advanceTimersByTimeAsync(1200 * 7);
    // Deixa microtasks do scrollIntoView assentarem.
    await Promise.resolve();

    expect(window.scrollBy).toHaveBeenCalled();
    expect(window.scrollBy).toHaveBeenCalledWith(0, 200);
    expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
    controller.deactivate();
  });

  it('interação prévia veta o jump', async () => {
    mountFeed(3);
    const ctx = new ContentScriptContext('test-jump-veto');
    const controller = createLinkedInController();
    const pending = controller.activate(ctx);
    window.dispatchEvent(new WheelEvent('wheel', { bubbles: true }));
    await advance(0);
    await pending;
    await vi.advanceTimersByTimeAsync(1200 * 7);

    expect(window.scrollBy).not.toHaveBeenCalled();
    controller.deactivate();
  });

  it('Esc cancela o jump em andamento', async () => {
    mountFeed(3);
    const ctx = new ContentScriptContext('test-jump-esc');
    const controller = createLinkedInController();
    const pending = controller.activate(ctx);
    await advance(0);
    await pending;
    await vi.advanceTimersByTimeAsync(1200);
    const callsBefore = (window.scrollBy as ReturnType<typeof vi.fn>).mock.calls.length;
    expect(callsBefore).toBeGreaterThan(0);

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await vi.advanceTimersByTimeAsync(1200 * 7);

    const callsAfter = (window.scrollBy as ReturnType<typeof vi.fn>).mock.calls.length;
    // Nenhum passo novo após o Esc (no máximo o que já estava em voo).
    expect(callsAfter - callsBefore).toBeLessThanOrEqual(1);
    controller.deactivate();
  });

  it('zero posts não rola', async () => {
    document.body.innerHTML = `<div class="feed"></div>`;
    const ctx = new ContentScriptContext('test-jump-empty');
    const controller = createLinkedInController();
    const pending = controller.activate(ctx);
    await advance(0);
    await pending;
    await vi.advanceTimersByTimeAsync(1200 * 7);

    expect(window.scrollBy).not.toHaveBeenCalled();
    controller.deactivate();
  });

  it('deactivate aborta e remove listener de Esc', async () => {
    mountFeed(3);
    const removeSpy = vi.spyOn(window, 'removeEventListener');
    const ctx = new ContentScriptContext('test-jump-deact');
    const controller = createLinkedInController();
    const pending = controller.activate(ctx);
    await advance(0);
    await pending;
    await vi.advanceTimersByTimeAsync(1200);

    controller.deactivate();
    await vi.advanceTimersByTimeAsync(1200 * 7);

    expect(removeSpy).toHaveBeenCalledWith('keydown', expect.any(Function), {
      capture: true,
    });
  });
});
