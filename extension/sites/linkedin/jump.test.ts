// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  JUMP_MAX_CYCLES,
  JUMP_OVERSCROLL_PX,
  JUMP_SETTLE_MS,
  JUMP_STABLE_CYCLES,
  countPosts,
  getLastPost,
  runJumpToEnd,
} from './jump';

function postHtml(urn: number): string {
  return `
    <article role="listitem">
      <div data-testid="expandable-text-box"><p>Post ${urn}</p></div>
      <a href="https://www.linkedin.com/feed/update/urn:li:activity:${urn}">Ver</a>
    </article>`;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('jump — contagem pura', () => {
  it('constantes do adendo 2026-09-14', () => {
    expect(JUMP_STABLE_CYCLES).toBe(6);
    expect(JUMP_SETTLE_MS).toBe(1200);
    expect(JUMP_MAX_CYCLES).toBe(50);
    expect(JUMP_OVERSCROLL_PX).toBe(200);
  });

  it('conta apenas isPost e ignora skeletons', () => {
    document.body.innerHTML = `<div class="feed">
      ${postHtml(1)}
      ${postHtml(2)}
      <div role="listitem" class="skeleton"><p>carregando</p></div>
    </div>`;
    expect(countPosts()).toBe(2);
  });

  it('getLastPost retorna o último isPost válido', () => {
    document.body.innerHTML = `<div class="feed">
      ${postHtml(1)}
      ${postHtml(2)}
    </div>
    <div role="listitem" class="skeleton"><p>carregando</p></div>`;
    const last = getLastPost();
    expect(last?.querySelector('a[href*="urn:li:activity:2"]')).not.toBeNull();
  });
});

describe('jump — loop até o fim', () => {
  it('para após 6 ciclos estáveis com scrollIntoView por ciclo', async () => {
    vi.useFakeTimers();
    try {
      document.body.innerHTML = `<div class="feed">${postHtml(1)}${postHtml(2)}</div>`;
      const scrollBy = vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
      const intoView = vi
        .spyOn(Element.prototype, 'scrollIntoView')
        .mockImplementation(() => {});

      const promise = runJumpToEnd({ isActive: () => true });
      await vi.runAllTimersAsync();
      const result = await promise;

      expect(result).toBe('completed');
      expect(JUMP_SETTLE_MS).toBe(1200);
      expect(scrollBy).toHaveBeenCalledTimes(6);
      expect(scrollBy).toHaveBeenCalledWith(0, 200);
      // Por ciclo + ajuste final.
      expect(intoView.mock.calls.length).toBeGreaterThanOrEqual(6);
    } finally {
      vi.useRealTimers();
      vi.restoreAllMocks();
    }
  });

  it('post novo no meio zera a contagem de estabilidade', async () => {
    vi.useFakeTimers();
    try {
      document.body.innerHTML = `<div class="feed">${postHtml(1)}${postHtml(2)}</div>`;
      let scrollCalls = 0;
      const scrollBy = vi.spyOn(window, 'scrollBy').mockImplementation(() => {
        scrollCalls++;
        if (scrollCalls === 1) {
          document.querySelector('.feed')?.insertAdjacentHTML('beforeend', postHtml(3));
        }
      });
      vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});

      const promise = runJumpToEnd({
        isActive: () => true,
        stableCycles: 2,
        settleMs: 500,
      });
      await vi.runAllTimersAsync();
      const result = await promise;

      expect(result).toBe('completed');
      expect(scrollBy.mock.calls.length).toBeGreaterThan(2);
      expect(countPosts()).toBe(3);
    } finally {
      vi.useRealTimers();
      vi.restoreAllMocks();
    }
  });

  it('lote que chega em 900ms dentro do settle reseta estabilidade', async () => {
    vi.useFakeTimers();
    try {
      document.body.innerHTML = `<div class="feed">${postHtml(1)}${postHtml(2)}</div>`;
      const scrollBy = vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
      vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});
      setTimeout(() => {
        document.querySelector('.feed')?.insertAdjacentHTML('beforeend', postHtml(3));
      }, 900);

      const promise = runJumpToEnd({ isActive: () => true });
      await vi.runAllTimersAsync();
      expect(await promise).toBe('completed');
      expect(countPosts()).toBe(3);
      expect(scrollBy.mock.calls.length).toBeGreaterThan(6);
    } finally {
      vi.useRealTimers();
      vi.restoreAllMocks();
    }
  });

  it('altura nova sem post novo também reseta estabilidade', async () => {
    vi.useFakeTimers();
    try {
      document.body.innerHTML = `<div class="feed" style="height:1000px">${postHtml(1)}</div>`;
      const heights = [1000, 1800, 1800, 1800];
      let hi = 0;
      const getHeight = (): number => heights[Math.min(hi++, heights.length - 1)] ?? 1800;
      vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
      vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});

      const promise = runJumpToEnd({
        isActive: () => true,
        stableCycles: 2,
        settleMs: 200,
        getHeight,
      });
      await vi.runAllTimersAsync();
      expect(await promise).toBe('completed');
      expect(window.scrollBy).toHaveBeenCalledTimes(3);
    } finally {
      vi.useRealTimers();
      vi.restoreAllMocks();
    }
  });

  it('teto maxCycles interrompe lista infinita', async () => {
    vi.useFakeTimers();
    try {
      document.body.innerHTML = `<div class="feed">${postHtml(1)}</div>`;
      vi.spyOn(window, 'scrollBy').mockImplementation(() => {
        document.querySelector('.feed')?.insertAdjacentHTML(
          'beforeend',
          postHtml(Math.floor(Math.random() * 100000) + 100),
        );
      });
      vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});

      const promise = runJumpToEnd({
        isActive: () => true,
        stableCycles: 6,
        settleMs: 50,
        maxCycles: 5,
      });
      await vi.runAllTimersAsync();
      expect(await promise).toBe('completed');
      expect(window.scrollBy).toHaveBeenCalledTimes(5);
    } finally {
      vi.useRealTimers();
      vi.restoreAllMocks();
    }
  });

  it('sem posts retorna empty sem rolar', async () => {
    document.body.innerHTML = `<div class="feed"></div>`;
    const scrollBy = vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
    const result = await runJumpToEnd({ isActive: () => true, settleMs: 10 });
    expect(result).toBe('empty');
    expect(scrollBy).not.toHaveBeenCalled();
    vi.restoreAllMocks();
  });

  it('isActive falso retorna aborted sem ajuste final', async () => {
    vi.useFakeTimers();
    try {
      document.body.innerHTML = `<div class="feed">${postHtml(1)}</div>`;
      const intoView = vi
        .spyOn(Element.prototype, 'scrollIntoView')
        .mockImplementation(() => {});
      let first = true;
      const promise = runJumpToEnd({
        isActive: () => {
          if (first) {
            first = false;
            return true;
          }
          return false;
        },
        settleMs: 500,
      });
      await vi.runAllTimersAsync();
      expect(await promise).toBe('aborted');
      expect(intoView).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
      vi.restoreAllMocks();
    }
  });

  it('signal abortado retorna cancelled', async () => {
    vi.useFakeTimers();
    try {
      document.body.innerHTML = `<div class="feed">${postHtml(1)}</div>`;
      vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
      const ac = new AbortController();
      const promise = runJumpToEnd({
        isActive: () => true,
        signal: ac.signal,
        settleMs: 500,
      });
      ac.abort();
      await vi.runAllTimersAsync();
      expect(await promise).toBe('cancelled');
    } finally {
      vi.useRealTimers();
      vi.restoreAllMocks();
    }
  });
});
