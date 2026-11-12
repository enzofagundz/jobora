// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import type { CaptureEnvelope, EnvelopeResult } from '@/types/capture';
import { createTestCaptureMessageHandler as createCaptureMessageHandler } from './helpers/memory-credential-context';
import {
  CAPTURE_ENVELOPE_MAX_BYTES,
  createCaptureQueue,
} from '@/utils/capture-queue';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';
import { lastApiStatus } from '@/utils/session-storage';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import {
  createMemoryCredentialContextStore,
  seedCredentialContext,
} from './helpers/memory-credential-context';

const TOKEN_A = 'token-account-a';
const TOKEN_B = 'token-account-b';

function envelope(idempotencyKey = 'capture-key-1'): CaptureEnvelope {
  return {
    format: 'jobora.capture-envelope',
    version: 1,
    capture: {
      idempotency_key: idempotencyKey,
      execution_id: 'exec-1',
      source: 'linkedin',
      surface: 'linkedin_publications',
      initial_url: 'https://www.linkedin.com/search/results/content/?keywords=php',
      final_url: 'https://www.linkedin.com/search/results/content/?keywords=php',
      captured_at: '2026-08-18T10:00:00Z',
      coverage: {
        status: 'complete',
        reason: null,
        blocks_expected: 1,
        blocks_captured: 1,
      },
      html: '<main data-capture-surface="linkedin_publications" data-capture-version="1"><article data-block-id="post-1" data-kind="post" data-platform-id="urn:li:activity:1" data-permalink="https://www.linkedin.com/feed/update/urn:li:activity:1">Vaga PHP</article></main>',
    },
    blocks: [
      {
        block_id: 'post-1',
        kind: 'post',
        platform_id: 'urn:li:activity:1',
        permalink: 'https://www.linkedin.com/feed/update/urn:li:activity:1',
        card_block_id: null,
        detail_block_id: null,
      },
    ],
  };
}

async function configure(token = TOKEN_A): Promise<void> {
  await captureEnabled.setValue(true);
  await apiToken.setValue(token);
  await apiBaseUrl.setValue('http://localhost:8000');
}

function response(status: number, body: unknown = {}, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

type FetchMock = ReturnType<typeof vi.spyOn> & { count: () => number };

function mockFetch(fn: (call: number, init: RequestInit) => Promise<Response>): FetchMock {
  let calls = 0;
  const spy = vi
    .spyOn(globalThis, 'fetch')
    .mockImplementation(async (url: string | URL | Request, init?: RequestInit) => {
      if (String(url).includes('/extension/auth/validate')) {
        return response(200, { valid: true, owner_user_id: 1 });
      }
      return fn(++calls, init ?? {});
    }) as FetchMock;
  spy.count = () => calls;
  return spy;
}

function submittedEnvelopes(spy: ReturnType<typeof vi.fn>): CaptureEnvelope[] {
  return spy.mock.calls.map(([, init]) => JSON.parse((init as RequestInit).body as string) as CaptureEnvelope);
}


function createHandler(store: ReturnType<typeof createMemoryQueueStore>) {
  const contexts = createMemoryCredentialContextStore();
  const handler = createCaptureMessageHandler(createCaptureQueue(store), contexts);

  async function submit(envelope: CaptureEnvelope, token = TOKEN_A) {
    const context = await seedCredentialContext(contexts, {
      token,
      executionId: envelope.capture.execution_id,
      source: envelope.capture.source,
      surface: envelope.capture.surface,
    });
    return handler({ type: 'capture:submit-envelope', envelope, context });
  }

  return { handler, contexts, submit };
}

describe('fila durável de envelopes', () => {
  beforeEach(async () => {
    fakeBrowser.reset();
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
    await configure();
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  it('ACK 201 elimina o payload local e envia o envelope inteiro', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async () => response(201, { id: 7, coverage: 'complete' }));

    const body = envelope();
    const result = (await submit(body)) as EnvelopeResult;

    expect(result).toMatchObject({ ok: true, status: 201 });
    expect(store.records.size).toBe(0);
    expect(submittedEnvelopes(fetchSpy)[0]).toEqual(body);
  });

  it('suspensão/restart retoma com a mesma chave e execution_id e só elimina após ACK', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const first = mockFetch(async () => {
      throw new TypeError('service worker suspenso');
    });

    const body = envelope();
    const firstResult = (await submit(body)) as EnvelopeResult;

    expect(firstResult).toMatchObject({ ok: false, error: 'network', queued: true });
    expect(store.records.size).toBe(1);
    const pending = [...store.records.values()][0]!;
    expect(pending.envelope.capture.idempotency_key).toBe('capture-key-1');
    expect(pending.envelope.capture.execution_id).toBe('exec-1');
    expect(pending.envelope.capture.html).toBe(body.capture.html);
    expect(submittedEnvelopes(first)[0]!.capture.idempotency_key).toBe('capture-key-1');

    vi.clearAllTimers();
    // Simula o tempo de backoff já transcorrido antes do restart.
    const persisted = [...store.records.values()][0]!;
    persisted.nextAttemptAt = Date.now() - 1;
    await store.put(persisted);

    const second = vi
      .spyOn(globalThis, 'fetch')
      .mockImplementation(async () => response(201, { id: 8 }));
    const { handler: restarted } = createHandler(store);
    await restarted.flush();

    expect(store.records.size).toBe(0);
    const after = submittedEnvelopes(second);
    expect(after[0]!.capture.idempotency_key).toBe('capture-key-1');
    expect(after[0]!.capture.execution_id).toBe('exec-1');
    expect(await lastApiStatus.getValue()).toMatchObject({ ok: true, status: 201 });
  });

  it('resposta perdida após persistência reenvia o mesmo envelope e aceita replay 200', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async (call) => {
      if (call === 1) throw new TypeError('conexão caiu depois do persist');
      return response(200, { id: 9, replay: true, coverage: 'complete' });
    });

    const body = envelope('capture-key-lost-response');
    await submit(body);
    vi.clearAllTimers();

    const drain = (await handler({ type: 'capture:queue-drain' })) as {
      ok: true;
      drain: { captured: number; results: Array<{ result: EnvelopeResult }> };
    };

    expect(drain.drain.captured).toBe(1);
    expect(drain.drain.results[0]!.result).toMatchObject({
      ok: true,
      duplicate: true,
      status: 200,
    });
    expect(store.records.size).toBe(0);
    expect(submittedEnvelopes(fetchSpy).map((item) => item.capture.idempotency_key)).toEqual([
      'capture-key-lost-response',
      'capture-key-lost-response',
    ]);
  });

  it('429 respeita Retry-After e só repete quando o prazo vence', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async (call) =>
      call === 1
        ? response(429, {}, { 'Retry-After': '2' })
        : response(201, { id: 10 }),
    );

    const result = (await submit(envelope('capture-key-429'))) as EnvelopeResult;

    expect(result).toMatchObject({ ok: false, error: 'http', status: 429, queued: true });
    const pending = [...store.records.values()][0]!;
    expect(pending.nextAttemptAt - Date.now()).toBeGreaterThanOrEqual(1900);
    expect(pending.nextAttemptAt - Date.now()).toBeLessThanOrEqual(2100);

    vi.clearAllTimers();
    const beforeDue = (await handler({
      type: 'capture:queue-drain',
      force: false,
    })) as { ok: true; drain: { attempted: number } };
    expect(beforeDue.drain.attempted).toBe(0);
    expect(fetchSpy.count()).toBe(1);
    expect(store.records.size).toBe(1);

    const afterDue = (await handler({ type: 'capture:queue-drain' })) as {
      ok: true;
      drain: { captured: number };
    };
    expect(afterDue.drain.captured).toBe(1);
    expect(fetchSpy.count()).toBe(2);
    expect(store.records.size).toBe(0);
  });

  it('5xx/network têm retry limitado com backoff e param no teto', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async () => response(500));

    await submit(envelope('capture-key-500'));
    vi.clearAllTimers();

    const first = [...store.records.values()][0]!;
    expect(first.attempts).toBe(1);
    expect(first.nextAttemptAt - Date.now()).toBeGreaterThanOrEqual(900);
    expect(first.nextAttemptAt - Date.now()).toBeLessThanOrEqual(1100);

    for (let attempt = 2; attempt <= 5; attempt++) {
      await handler({ type: 'capture:queue-drain' });
    }

    expect(fetchSpy.count()).toBe(5);
    const blocked = [...store.records.values()][0]!;
    expect(blocked.state).toBe('blocked');
    expect(blocked.blockedReason).toBe('retry_exhausted');
    expect(blocked.attempts).toBe(5);

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);
    expect(fetchSpy.count()).toBe(5);
  });

  it('401 pausa reautenticação sem loop automático', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async () => response(401));

    const result = (await submit(envelope('capture-key-401'))) as EnvelopeResult;

    expect(result).toMatchObject({ ok: false, error: 'auth', queued: true });
    const blocked = [...store.records.values()][0]!;
    expect(blocked.blockedReason).toBe('auth');

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);
    expect(fetchSpy.count()).toBe(1);
  });

  it('422 fica bloqueado para correção e não repete indefinidamente', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async () => response(422));

    const result = (await submit(envelope('capture-key-422'))) as EnvelopeResult;

    expect(result).toMatchObject({ ok: false, error: 'invalid', queued: true });
    expect([...store.records.values()][0]!.blockedReason).toBe('invalid');

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);
    expect(fetchSpy.count()).toBe(1);
  });

  it('troca de credencial não envia material pendente ao novo token; token antigo retoma', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async (call) => {
      if (call === 1) throw new TypeError('offline');
      return response(201, { id: 11 });
    });

    await submit(envelope('capture-key-switch'));
    expect(store.records.size).toBe(1);
    vi.clearAllTimers();

    await apiToken.setValue(TOKEN_B);
    const other = (await handler({ type: 'capture:queue-drain' })) as {
      ok: true;
      drain: { attempted: number };
    };
    expect(other.drain.attempted).toBe(0);
    expect(fetchSpy.count()).toBe(1);
    expect(store.records.size).toBe(1);

    await apiToken.setValue(TOKEN_A);
    await vi.advanceTimersByTimeAsync(0);
    const resumed = (await handler({ type: 'capture:queue-drain' })) as {
      ok: true;
      drain: { captured: number };
    };
    expect(resumed.drain.captured).toBe(1);
    expect(fetchSpy.count()).toBe(2);
    expect(store.records.size).toBe(0);

    const bodies = submittedEnvelopes(fetchSpy);
    expect(bodies.every((body) => body.capture.idempotency_key === 'capture-key-switch')).toBe(true);
  });

  it('quota explícita rejeita envelope acima de 12 MiB sem truncar', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    const fetchSpy = mockFetch(async () => response(201));
    const huge = envelope('capture-key-huge');
    huge.capture.html = 'x'.repeat(CAPTURE_ENVELOPE_MAX_BYTES + 1);

    const result = (await submit(huge)) as EnvelopeResult;

    expect(result).toMatchObject({ ok: false, error: 'too_large' });
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(store.records.size).toBe(0);

    const status = (await handler({ type: 'capture:queue-status' })) as {
      ok: true;
      status: { envelope_max_bytes: number; bytes: number };
    };
    expect(status.status.envelope_max_bytes).toBe(CAPTURE_ENVELOPE_MAX_BYTES);
    expect(status.status.bytes).toBe(0);
  });

  it('status não expõe HTML nem token', async () => {
    const store = createMemoryQueueStore();
    const { handler, submit } = createHandler(store);
    mockFetch(async () => {
      throw new TypeError('offline');
    });
    await submit(envelope('capture-key-status'));

    const status = await handler({ type: 'capture:queue-status' });
    const json = JSON.stringify(status);
    expect(json).not.toContain(TOKEN_A);
    expect(json).not.toContain('<main');
    expect(json).toContain('capture-key-status');
  });
});
