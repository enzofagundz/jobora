// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';

describe('content-script storage imports', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('roda o main dos content scripts só com storage local, sem tocar sessão', async () => {
    vi.resetModules();
    fakeBrowser.reset();
    // Fora das rotas de captura: `main` lê a configuração sem ativar controller.
    window.happyDOM.setURL('https://www.example.com/');

    const localGet = vi.spyOn(fakeBrowser.storage.local, 'get');
    const sessionGet = vi
      .spyOn(fakeBrowser.storage.session, 'get')
      .mockRejectedValue(new Error('Access to storage is not allowed from this context.'));
    const unhandledRejections: unknown[] = [];
    const recordUnhandledRejection = (reason: unknown): void => {
      unhandledRejections.push(reason);
    };
    process.on('unhandledRejection', recordUnhandledRejection);

    try {
      const linkedin = (await import('@/entrypoints/linkedin.content')).default;
      const indeed = (await import('@/entrypoints/indeed.content')).default;
      const readsBeforeMain = localGet.mock.calls.length;

      await linkedin.main?.(new ContentScriptContext('test-linkedin-content'));
      await indeed.main?.(new ContentScriptContext('test-indeed-content'));
      await new Promise((resolve) => setTimeout(resolve, 0));

      // O main real lê a configuração para decidir se captura.
      expect(localGet.mock.calls.length).toBeGreaterThan(readsBeforeMain);
      expect(sessionGet).not.toHaveBeenCalled();
      expect(unhandledRejections).toEqual([]);
    } finally {
      process.off('unhandledRejection', recordUnhandledRejection);
    }
  });
});
