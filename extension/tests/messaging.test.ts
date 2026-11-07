// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import type { CaptureEnvelope } from '@/types/capture';
import { createCaptureMessageHandler } from '@/api/capture-message-handler';
import { createCaptureQueue } from '@/utils/capture-queue';
import { apiToken, captureEnabled } from '@/utils/storage';
import { lastApiStatus } from '@/utils/session-storage';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import {
  createMemoryCredentialContextStore,
  seedCredentialContext,
} from './helpers/memory-credential-context';

/** Contrato do handler do background: token vem só do storage, nunca da mensagem;
 * gate de captura fecha antes do payload/toque na rede. */
let handleMessage: ReturnType<typeof createCaptureMessageHandler>;
let contexts: ReturnType<typeof createMemoryCredentialContextStore>;

function envelope(): CaptureEnvelope {
  return {
    format: 'jobora.capture-envelope',
    version: 1,
    capture: {
      idempotency_key: 'capture-key-1',
      execution_id: 'exec-1',
      source: 'linkedin',
      surface: 'linkedin_publications',
      initial_url: 'https://www.linkedin.com/search/results/content/?keywords=php',
      final_url: 'https://www.linkedin.com/search/results/content/?keywords=php',
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

function payload() {
  return {
    source: 'indeed',
    external_id: 'abc111',
    url: 'https://br.indeed.com/viewjob?jk=abc111',
    search_url: 'https://br.indeed.com/jobs?q=php',
    title: 'Dev PHP',
    description: 'Descrição',
    company: null,
    location: null,
    captured_at: new Date().toISOString(),
  };
}

describe('messaging background → API', () => {
  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    contexts = createMemoryCredentialContextStore();
    handleMessage = createCaptureMessageHandler(
      createCaptureQueue(createMemoryQueueStore()),
      contexts,
    );
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  function mockFetch(status: number, body: unknown = {}): ReturnType<typeof vi.fn> {
    const spy = vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status,
          headers: { 'Content-Type': 'application/json' },
        }),
    );
    vi.stubGlobal('fetch', spy);
    return spy;
  }

  it('ignores retired capture:submit without network or status writes', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    const fetchSpy = mockFetch(201);
    expect(await handleMessage({ type: 'capture:submit', payload: payload() })).toBeUndefined();
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(await lastApiStatus.getValue()).toBeNull();
  });

  it('token vem do storage; mensagem não carrega segredo', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    const fetchSpy = mockFetch(201);

    const context = await seedCredentialContext(contexts, { token: 'segredo-do-env', origin: 'https://vagas.test' });
    const message = { type: 'capture:submit-envelope' as const, envelope: envelope(), context };
    expect(JSON.stringify(message)).not.toContain('segredo-do-env');

    const result = await handleMessage(message);
    expect(result).toMatchObject({ ok: true, duplicate: false });

    const [, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect((init.headers as Record<string, string>).Authorization).toBe(
      'Bearer segredo-do-env',
    );
  });

  it.each([
    [401, 'auth'],
    [503, 'http'],
    [422, 'invalid'],
  ])('status %i classifica error=%s e grava lastApiStatus', async (status, error) => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(status);
    const context = await seedCredentialContext(contexts, { token: 'segredo-do-env', origin: 'https://vagas.test' });
    const result = await handleMessage({ type: 'capture:submit-envelope', envelope: envelope(), context });
    expect(result).toBeDefined();
    if (!result || !('error' in result)) throw new Error('resultado inesperado');
    expect(result.ok).toBe(false);
    expect(result.error).toBe(error);

    const stored = await lastApiStatus.getValue();
    expect(stored).toMatchObject({ ok: false, error });
  });

  it('gate fechado → auth, zero fetch e lastApiStatus intacto', async () => {
    const fetchSpy = mockFetch(200);
    const context = await seedCredentialContext(contexts, { token: 'segredo-do-env', origin: 'https://vagas.test' });
    const result = await handleMessage({ type: 'capture:submit-envelope', envelope: envelope(), context });
    expect(result).toMatchObject({ ok: false, error: 'auth' });
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(await lastApiStatus.getValue()).toBeNull();

    // Token presente, mas flag desligada: também bloqueia.
    await apiToken.setValue('segredo-do-env');
    const withToken = await handleMessage({ type: 'capture:submit-envelope', envelope: envelope(), context });
    expect(withToken).toMatchObject({ ok: false, error: 'auth' });
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('mensagem estranha é rejeitada sem tocar a rede', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    const fetchSpy = mockFetch(200);
    expect(await handleMessage({ type: 'outra-coisa' })).toBeUndefined();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('resolve-run correla execução sem enviar token no payload', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    const fetchSpy = vi
      .spyOn(globalThis, 'fetch')
      .mockImplementation(async (url) => {
        if (String(url).includes('/extension/auth/validate')) {
          return new Response(JSON.stringify({ valid: true, owner_user_id: 1 }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          });
        }
        return new Response(
          JSON.stringify({
            execution_id: 'exec-1',
            source: 'linkedin',
            surface: 'linkedin_publications',
            initial_url: 'https://www.linkedin.com/search/results/content/?keywords=php',
            expires_at: null,
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        );
      });
    const message = {
      type: 'capture:resolve-run' as const,
      url: 'https://www.linkedin.com/search/results/content/?keywords=php',
    };
    expect(JSON.stringify(message)).not.toContain('segredo-do-env');

    const result = await handleMessage(message);

    expect(result).toMatchObject({
      ok: true,
      run: { executionId: 'exec-1', surface: 'linkedin_publications' },
      context: expect.any(String),
    });
    const resolveCall = fetchSpy.mock.calls.find(([url]) =>
      String(url).includes('/discovery-runs/resolve'),
    ) as [string, RequestInit];
    const [url, init] = resolveCall;
    expect(url).toBe('https://vagas.test/api/discovery-runs/resolve');
    expect((init.headers as Record<string, string>).Authorization).toBe(
      'Bearer segredo-do-env',
    );
  });

  it('submit-envelope só responde ok no ACK 201 e grava lastApiStatus', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(201, { id: 7, execution_id: 'exec-1', coverage: 'complete', replay: false });

    const captured = envelope();
    const context = await seedCredentialContext(contexts, {
      token: 'segredo-do-env',
      origin: 'https://vagas.test',
      executionId: 'exec-1',
      source: 'linkedin',
      surface: 'linkedin_publications',
    });
    const message = { type: 'capture:submit-envelope' as const, envelope: captured, context };
    expect(JSON.stringify(message)).not.toContain('segredo-do-env');

    const result = await handleMessage(message);

    expect(result).toMatchObject({ ok: true, status: 201, coverage: 'complete' });
    expect(await lastApiStatus.getValue()).toMatchObject({ ok: true });
  });

  it('submit-envelope 409 não declara captura e não vira ok', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(409, {});
    const context = await seedCredentialContext(contexts, {
      token: 'segredo-do-env',
      origin: 'https://vagas.test',
      executionId: 'exec-1',
      source: 'linkedin',
      surface: 'linkedin_publications',
    });

    const result = await handleMessage({
      type: 'capture:submit-envelope',
      envelope: envelope(),
      context,
    });

    expect(result).toMatchObject({ ok: false, error: 'conflict' });
    expect(await lastApiStatus.getValue()).toMatchObject({ ok: false, error: 'conflict' });
  });

  it('limpar token remove o valor do storage (popup Desconectar)', async () => {
    await apiToken.setValue('segredo-do-env');
    await apiToken.removeValue();
    expect(await apiToken.getValue()).toBe('');
  });
});
