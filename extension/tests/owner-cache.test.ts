// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { createSessionOwnerCache } from '@/utils/owner-cache';

const MAX_OWNERS = 100;
const ORIGIN = 'http://localhost:8000';

describe('cache de dono da credencial', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  afterEach(() => {
    vi.useRealTimers();
    fakeBrowser.reset();
  });

  it('reescrever uma chave existente não despeja outro dono', async () => {
    const cache = createSessionOwnerCache();

    for (let index = 0; index < MAX_OWNERS; index++) {
      vi.setSystemTime(1_700_000_000_000 + index);
      await cache.put(ORIGIN, `fingerprint-${index}`, index + 1);
    }

    // Revalidar uma chave do meio não pode expulsar a mais antiga das outras.
    await cache.put(ORIGIN, 'fingerprint-50', 51);

    for (let index = 0; index < MAX_OWNERS; index++) {
      await expect(cache.get(ORIGIN, `fingerprint-${index}`)).resolves.toMatchObject({
        ownerUserId: index === 50 ? 51 : index + 1,
      });
    }
  });
});
