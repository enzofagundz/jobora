// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import type { CaptureEnvelope } from '@/types/capture';
import { createCaptureMessageHandler } from '@/api/capture-message-handler';
import { createCaptureQueue } from '@/utils/capture-queue';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import { createMemoryCredentialContextStore } from './helpers/memory-credential-context';
import { createMemoryOwnerCache } from './helpers/memory-owner-cache';

const TOKEN_A1 = 'token-owner-a-1';
const TOKEN_A2 = 'token-owner-a-2';
const TOKEN_B = 'token-owner-b';
const SEARCH_URL = 'https://www.linkedin.com/search/results/content/?keywords=php';

function envelope(executionId: string | null = 'exec-1'): CaptureEnvelope {
  return {
    format: 'jobora.capture-envelope',
    version: 1,
    capture: {
      idempotency_key: `capture-${executionId ?? 'spontaneous'}`,
      execution_id: executionId,
      source: 'linkedin',
      surface: 'linkedin_publications',
      initial_url: SEARCH_URL,
      final_url: SEARCH_URL,
      captured_at: '2026-08-18T10:00:00Z',
      coverage: {
        status: 'complete',
        reason: null,
        blocks_expected: 0,
        blocks_captured: 0,
      },
      html: '<main data-capture-surface="linkedin_publications" data-capture-version="1"></main>',
    },
    blocks: [],
  };
}

async function configure(token = TOKEN_A1): Promise<void> {
  await captureEnabled.setValue(true);
  await apiToken.setValue(token);
  await apiBaseUrl.setValue('http://localhost:8000');
}

function json(status: number, body: unknown = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function ownerForToken(token: string): number | null {
  if (token === TOKEN_A1 || token === TOKEN_A2) return 1;
  if (token === TOKEN_B) return 2;
  return null;
}

function bearer(init?: RequestInit): string {
  return ((init?.headers as Record<string, string> | undefined)?.Authorization ?? '').replace(
    /^Bearer\s+/,
    '',
  );
}

describe('contexto opaco de credencial', () => {
  beforeEach(async () => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    await configure();
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  function setup() {
    const store = createMemoryQueueStore();
    const contexts = createMemoryCredentialContextStore();
    const queue = createCaptureQueue(store);
    const owners = createMemoryOwnerCache();
    const handler = createCaptureMessageHandler(queue, contexts, undefined, owners);
    return { store, contexts, queue, owners, handler };
  }

  function mockBackend(options: {
    validate?: (init?: RequestInit) => Response | Promise<Response>;
    resolve?: () => Response | Promise<Response>;
    upload?: (call: number, init?: RequestInit) => Response | Promise<Response>;
  } = {}) {
    const uploads: Array<{ body: CaptureEnvelope; token: string }> = [];
    const validations = { count: 0 };
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
      const target = String(url);
      if (target.includes('/extension/auth/validate')) {
        validations.count += 1;
        return options.validate
          ? options.validate(init)
          : json(200, { valid: true, owner_user_id: ownerForToken(bearer(init)) ?? 1 });
      }
      if (target.includes('/discovery-runs/resolve')) {
        return options.resolve
          ? options.resolve()
          : json(200, {
              execution_id: 'exec-1',
              source: 'linkedin',
              surface: 'linkedin_publications',
              initial_url: SEARCH_URL,
              expires_at: null,
            });
      }

      uploads.push({
        body: JSON.parse((init?.body as string) ?? '{}') as CaptureEnvelope,
        token: bearer(init),
      });
      return options.upload
        ? options.upload(uploads.length, init)
        : json(201, { id: uploads.length });
    });
    return { fetchSpy, uploads, validations };
  }

  async function resolveContext(handler: ReturnType<typeof createCaptureMessageHandler>) {
    return (await handler({ type: 'capture:resolve-run', url: SEARCH_URL })) as {
      ok: boolean;
      run?: unknown;
      context?: string;
      error?: string;
      status?: number;
    };
  }

  it('contexto A1 durante coleta não envia com token B; A1 retoma', async () => {
    const { store, handler } = setup();
    const { fetchSpy, uploads } = mockBackend();

    const resolved = await resolveContext(handler);
    expect(resolved).toMatchObject({ ok: true, context: expect.any(String) });

    await apiToken.setValue(TOKEN_B);
    const captured = envelope('exec-1');
    const result = (await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    })) as { ok: boolean; error?: string; queued?: boolean };

    expect(result).toMatchObject({ ok: false, error: 'credential_changed', queued: true });
    expect(uploads).toHaveLength(0);
    expect(store.records.size).toBe(1);
    expect([...store.records.values()][0]!.ownerUserId).toBe(1);

    await apiToken.setValue(TOKEN_A1);
    const drain = (await handler({ type: 'capture:queue-drain' })) as {
      ok: true;
      drain: { captured: number };
    };
    expect(drain.drain.captured).toBe(1);
    expect(uploads).toHaveLength(1);
    expect(uploads[0]!.token).toBe(TOKEN_A1);
    expect(store.records.size).toBe(0);
    expect(fetchSpy).toHaveBeenCalled();
  });

  it('captura espontânea (execution_id null) também fica presa ao contexto A1', async () => {
    const { store, handler } = setup();
    const { uploads } = mockBackend({ resolve: () => json(404) });

    const resolved = await resolveContext(handler);
    expect(resolved).toMatchObject({ ok: true, run: null, context: expect.any(String) });

    await apiToken.setValue(TOKEN_B);
    const result = (await handler({
      type: 'capture:submit-envelope',
      envelope: envelope(null),
      context: resolved.context!,
    })) as { ok: boolean; error?: string };

    expect(result).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(uploads).toHaveLength(0);
    expect(store.records.size).toBe(1);
  });

  it('contexto ausente ou desconhecido não enfileira nem envia', async () => {
    const { store, handler } = setup();
    const { fetchSpy } = mockBackend();

    expect(await handler({ type: 'capture:submit-envelope', envelope: envelope() })).toMatchObject({
      ok: false,
      error: 'invalid',
    });
    expect(
      await handler({
        type: 'capture:submit-envelope',
        envelope: envelope(),
        context: 'ctx-inexistente',
      }),
    ).toMatchObject({ ok: false, error: 'invalid' });

    expect(fetchSpy).not.toHaveBeenCalled();
    expect(store.records.size).toBe(0);
  });

  it('contexto de execução A não envia envelope de execução B', async () => {
    const { handler } = setup();
    const { uploads } = mockBackend();
    const resolved = await resolveContext(handler);

    expect(
      await handler({
        type: 'capture:submit-envelope',
        envelope: envelope('exec-2'),
        context: resolved.context!,
      }),
    ).toMatchObject({ ok: false, error: 'invalid' });
    expect(uploads).toHaveLength(0);
  });

  it('401 com A1, A2 do MESMO owner lista, resume explícito e envia payload original sob A2', async () => {
    const { store, handler } = setup();
    const { uploads, validations } = mockBackend({
      upload: (call) => (call === 1 ? json(401) : json(201, { id: 10 })),
    });

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    });
    expect([...store.records.values()][0]!.blockedReason).toBe('auth');
    expect(store.records.values().next().value!.ownerUserId).toBe(1);
    expect(uploads).toHaveLength(1);

    await apiToken.setValue(TOKEN_A2);

    const status = (await handler({ type: 'capture:queue-status' })) as {
      ok: true;
      status: { items: Array<{ idempotency_key: string; blocked_reason?: string }> };
    };
    expect(status.ok).toBe(true);
    expect(status.status.items).toEqual([
      expect.objectContaining({
        idempotency_key: captured.capture.idempotency_key,
        blocked_reason: 'auth',
      }),
    ]);

    const resumed = (await handler({
      type: 'capture:queue-resume',
      idempotency_key: captured.capture.idempotency_key,
    })) as { ok: true; status: { pending: number; blocked: number } };
    expect(resumed).toMatchObject({ ok: true, status: { pending: 0, blocked: 0 } });
    expect(uploads).toHaveLength(2);
    expect(uploads[1]!.token).toBe(TOKEN_A2);
    expect(uploads[1]!.body).toEqual(captured);
    // resolve + status A2 + resume A2: resume sempre valida online, não usa só cache.
    expect(validations.count).toBeGreaterThanOrEqual(3);
    expect(store.records.size).toBe(0);
  });

  it('token B de owner diferente não lista, não resume e não descarta material de A', async () => {
    const { store, handler } = setup();
    const { uploads } = mockBackend();

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await apiToken.setValue(TOKEN_B);
    expect(
      await handler({
        type: 'capture:submit-envelope',
        envelope: captured,
        context: resolved.context!,
      }),
    ).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(uploads).toHaveLength(0);
    expect(store.records.size).toBe(1);
    expect(store.records.values().next().value!.ownerUserId).toBe(1);

    const status = (await handler({ type: 'capture:queue-status' })) as {
      ok: true;
      status: { items: unknown[] };
    };
    expect(status.status.items).toEqual([]);

    expect(
      await handler({
        type: 'capture:queue-resume',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(
      await handler({
        type: 'capture:queue-discard',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(store.records.size).toBe(1);
    expect(uploads).toHaveLength(0);
  });

  it('422 permanece bloqueado para correção/descarte e não reenvia', async () => {
    const { store, handler } = setup();
    const { uploads } = mockBackend({ upload: () => json(422) });

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    });
    expect([...store.records.values()][0]!.blockedReason).toBe('invalid');
    expect(uploads).toHaveLength(1);

    expect(
      await handler({
        type: 'capture:queue-resume',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: false, error: 'invalid' });
    expect(uploads).toHaveLength(1);

    expect(
      await handler({
        type: 'capture:queue-discard',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: true, status: { pending: 0, blocked: 0 } });
    expect(store.records.size).toBe(0);
  });

  it('registro antigo sem owner não é adivinhado por owner; só fingerprint original', async () => {
    const { store, handler } = setup();
    const { uploads } = mockBackend({
      upload: (call) => (call === 1 ? json(401) : json(201)),
    });

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    });

    const legacy = [...store.records.values()][0]!;
    legacy.ownerUserId = null;
    await store.put(legacy);

    await apiToken.setValue(TOKEN_A2);
    const statusA2 = (await handler({ type: 'capture:queue-status' })) as {
      ok: true;
      status: { items: unknown[] };
    };
    expect(statusA2.status.items).toEqual([]);
    expect(
      await handler({
        type: 'capture:queue-resume',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(uploads).toHaveLength(1);

    await apiToken.setValue(TOKEN_A1);
    const statusA1 = (await handler({ type: 'capture:queue-status' })) as {
      ok: true;
      status: { items: Array<{ idempotency_key: string }> };
    };
    expect(statusA1.status.items.map((item) => item.idempotency_key)).toEqual([
      captured.capture.idempotency_key,
    ]);
    expect(
      await handler({
        type: 'capture:queue-resume',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: true });
    expect(uploads).toHaveLength(2);
    expect(store.records.size).toBe(0);
  });


  it('status offline/429 mostra pendência da fingerprint local e não esconde material', async () => {
    const { store, queue, handler } = setup();
    const { uploads } = mockBackend({ upload: () => { throw new TypeError('offline'); } });

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    });
    expect(store.records.size).toBe(1);
    expect(uploads).toHaveLength(1);

    // Simula restart do worker: cache de owner vazio e validação indisponível.
    const restarted = createCaptureMessageHandler(queue, createMemoryCredentialContextStore());
    vi.restoreAllMocks();
    const offlineFetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
      if (String(url).includes('/extension/auth/validate')) return json(429);
      return json(201);
    });

    const status = (await restarted({ type: 'capture:queue-status' })) as {
      ok: true;
      status: { items: Array<{ idempotency_key: string }> };
    };
    expect(status.ok).toBe(true);
    expect(status.status.items.map((item) => item.idempotency_key)).toEqual([
      captured.capture.idempotency_key,
    ]);
    expect(offlineFetch).not.toHaveBeenCalled();
  });

  it('burst de status faz no máximo uma validação e depois usa cache', async () => {
    const { handler } = setup();
    let validations = 0;
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
      if (String(url).includes('/extension/auth/validate')) {
        validations++;
        return json(200, { valid: true, owner_user_id: 1 });
      }
      return json(201);
    });

    const firstThree = await Promise.all([
      handler({ type: 'capture:queue-status' }),
      handler({ type: 'capture:queue-status' }),
      handler({ type: 'capture:queue-status' }),
    ]);
    expect(firstThree.every((result) => (result as { ok: boolean }).ok)).toBe(true);
    expect(validations).toBe(1);

    await handler({ type: 'capture:queue-status' });
    expect(validations).toBe(1);
  });

  it('status com validação 429 para token novo não lista pendência de outra fingerprint', async () => {
    const { store, queue, handler } = setup();
    const { uploads } = mockBackend({ upload: () => { throw new TypeError('offline'); } });

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    });
    expect(store.records.size).toBe(1);
    expect(uploads).toHaveLength(1);

    await apiToken.setValue(TOKEN_A2);
    const restarted = createCaptureMessageHandler(queue, createMemoryCredentialContextStore());
    vi.restoreAllMocks();
    const rateLimited = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
      if (String(url).includes('/extension/auth/validate')) return json(429);
      return json(201);
    });

    const status = (await restarted({ type: 'capture:queue-status' })) as {
      ok: true;
      status: { items: unknown[] };
    };
    expect(status.status.items).toEqual([]);
    await restarted({ type: 'capture:queue-status' });
    expect(rateLimited).toHaveBeenCalledTimes(1);
  });

  it('falha atômica no rebind mantém o registro original inteiro', async () => {
    const { store, handler } = setup();
    const { uploads } = mockBackend({ upload: (call) => (call === 1 ? json(401) : json(201)) });

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    });
    const before = [...store.records.values()][0]!;
    const beforeId = before.id;
    const beforeFingerprint = before.credentialFingerprint;

    await apiToken.setValue(TOKEN_A2);
    store.replace = async () => {
      throw new Error('crash no commit');
    };

    expect(
      await handler({
        type: 'capture:queue-resume',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: false, error: 'storage' });
    expect(store.records.size).toBe(1);
    const after = [...store.records.values()][0]!;
    expect(after.id).toBe(beforeId);
    expect(after.credentialFingerprint).toBe(beforeFingerprint);
    expect(after.envelope).toEqual(before.envelope);
    expect(uploads).toHaveLength(1);
  });

  it('validação falha ou credencial muda durante validação não rebinda nem envia', async () => {
    const { store, handler } = setup();
    const { uploads } = mockBackend({
      upload: (call) => (call === 1 ? json(401) : json(201)),
    });

    const resolved = await resolveContext(handler);
    const captured = envelope('exec-1');
    await handler({
      type: 'capture:submit-envelope',
      envelope: captured,
      context: resolved.context!,
    });
    expect([...store.records.values()][0]!.blockedReason).toBe('auth');

    await apiToken.setValue(TOKEN_A2);
    const originalFingerprint = store.records.values().next().value!.credentialFingerprint;

    // Validação em rede falha.
    vi.restoreAllMocks();
    mockBackend({
      validate: () => {
        throw new TypeError('rede caiu');
      },
    });
    expect(
      await handler({
        type: 'capture:queue-resume',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: false, error: 'network' });
    expect(store.records.values().next().value!.credentialFingerprint).toBe(originalFingerprint);
    expect(uploads).toHaveLength(1);

    // Config muda durante validação: snapshot antigo não pode rebindar.
    vi.restoreAllMocks();
    mockBackend({
      validate: async () => {
        await apiToken.setValue(TOKEN_B);
        return json(200, { valid: true, owner_user_id: 1 });
      },
    });
    expect(
      await handler({
        type: 'capture:queue-resume',
        idempotency_key: captured.capture.idempotency_key,
      }),
    ).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(store.records.size).toBe(1);
    expect(uploads).toHaveLength(1);
  });
});
