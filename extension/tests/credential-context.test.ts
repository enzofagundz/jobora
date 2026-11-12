// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import {
  createSessionCredentialContextStore,
  type CredentialContext,
} from '@/utils/credential-context';

function context(index: number): CredentialContext {
  return {
    origin: 'http://localhost:8000',
    credentialFingerprint: `fingerprint-${index}`,
    ownerUserId: 1,
    executionId: `exec-${index}`,
    source: 'linkedin',
    surface: 'linkedin_publications',
    createdAt: 1_700_000_000_000 + index,
  };
}

describe('contexto de credencial em session storage', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  afterEach(() => {
    fakeBrowser.reset();
  });

  it('puts concorrentes preservam todos os contextos', async () => {
    const store = createSessionCredentialContextStore();

    await Promise.all(
      Array.from({ length: 20 }, (_, index) => store.put(`ctx-${index}`, context(index))),
    );

    for (let index = 0; index < 20; index++) {
      await expect(store.get(`ctx-${index}`)).resolves.toMatchObject({
        executionId: `exec-${index}`,
      });
    }
  });
});
