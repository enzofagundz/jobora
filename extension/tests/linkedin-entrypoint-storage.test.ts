import { afterEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';

describe('content-script storage imports', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('imports real content entrypoints with local storage available and no session access', async () => {
    vi.resetModules();
    fakeBrowser.reset();

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
      await import('@/entrypoints/linkedin.content');
      await import('@/entrypoints/indeed.content');
      await new Promise((resolve) => setTimeout(resolve, 0));

      expect(localGet).toHaveBeenCalled();
      expect(sessionGet).not.toHaveBeenCalled();
      expect(unhandledRejections).toEqual([]);
    } finally {
      process.off('unhandledRejection', recordUnhandledRejection);
    }
  });
});
