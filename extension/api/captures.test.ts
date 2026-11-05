// @vitest-environment happy-dom
import type { MockInstance } from 'vitest';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { apiBaseUrl, apiToken, captureEnabled, lastApiStatus } from '@/utils/storage';
import {
  CAPTURE_PATH,
  PAGE_CAPTURE_PATH,
  REQUEST_TIMEOUT_MS,
  RESOLVE_RUN_PATH,
  resolveDiscoveryRun,
  sanitizeApiBaseUrl,
  submitCapture,
  submitEnvelope,
} from './captures';
import type { CaptureEnvelope, CapturePayload } from '@/types/capture';

function payload(overrides: Partial<CapturePayload> = {}): CapturePayload {
  return {
    source: 'linkedin',
    external_id: 'urn:li:activity:111',
    url: 'https://www.linkedin.com/feed/update/urn:li:activity:111',
    search_url: 'https://www.linkedin.com/search/results/content/?keywords=vaga%20php',
    title: null,
    description: 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL.',
    company: null,
    location: null,
    author: { name: 'Maria Silva', profile_url: 'https://www.linkedin.com/in/maria' },
    captured_at: '2026-08-18T10:00:00Z',
    ...overrides,
  };
}

describe('sanitizeApiBaseUrl', () => {
  it('remove barra final de origin permitido', () => {
    expect(sanitizeApiBaseUrl('http://localhost:8000/')).toBe('http://localhost:8000');
  });

  it('aceita origins https do lerd (vagas/jobora .test)', () => {
    expect(sanitizeApiBaseUrl('https://vagas.test')).toBe('https://vagas.test');
    expect(sanitizeApiBaseUrl('https://vagas.test/')).toBe('https://vagas.test');
    expect(sanitizeApiBaseUrl('https://jobora.test')).toBe('https://jobora.test');
    expect(sanitizeApiBaseUrl('https://jobora.test/')).toBe('https://jobora.test');
  });

  it('rejeita http dos .test (site é secured, só https)', () => {
    expect(sanitizeApiBaseUrl('http://vagas.test')).toBeNull();
    expect(sanitizeApiBaseUrl('http://jobora.test')).toBeNull();
  });

  it('rejeita origins fora do manifest, query, fragmento e lixo', () => {
    expect(sanitizeApiBaseUrl('https://evil.example')).toBeNull();
    expect(sanitizeApiBaseUrl('http://localhost:8000?x=1')).toBeNull();
    expect(sanitizeApiBaseUrl('http://localhost:8000/#frag')).toBeNull();
    expect(sanitizeApiBaseUrl('not a url')).toBeNull();
  });
});

describe('submitCapture', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });


  function mockFetch(fn: (url: string, init: RequestInit) => Promise<Response>): MockInstance {
    const spy = vi.fn(fn);
    vi.stubGlobal('fetch', spy);
    return spy;
  }

  it('monta path, headers Bearer e JSON no host configurado', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    await apiBaseUrl.setValue('http://localhost:8000');
    const fetchSpy = mockFetch(async () => new Response('{}', { status: 201 }));

    await submitCapture(payload());

    expect(fetchSpy).toHaveBeenCalledOnce();
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('http://localhost:8000' + CAPTURE_PATH);
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer segredo-do-env');
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    expect(JSON.parse(init.body as string)).toMatchObject({ source: 'linkedin' });
  });

  it('envia para o origin lerd configurado (https://vagas.test)', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    await apiBaseUrl.setValue('https://vagas.test');
    const fetchSpy = mockFetch(async () => new Response('{}', { status: 201 }));

    await submitCapture(payload());

    expect(fetchSpy).toHaveBeenCalledOnce();
    const [url] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('https://vagas.test' + CAPTURE_PATH);
  });

  it('gate fechado (sem flag ou sem token) → auth e zero fetch', async () => {
    mockFetch(async () => new Response('{}', { status: 201 }));
    const noFlag = await submitCapture(payload());
    expect(noFlag).toMatchObject({ ok: false, error: 'auth' });

    await apiToken.setValue('tok');
    const withTokenNoFlag = await submitCapture(payload());
    expect(withTokenNoFlag).toMatchObject({ ok: false, error: 'auth' });

    await captureEnabled.setValue(true);
    await apiToken.setValue('');
    const flagNoToken = await submitCapture(payload());
    expect(flagNoToken).toMatchObject({ ok: false, error: 'auth' });
  });

  it.each([
    [200, { ok: true, duplicate: true }],
    [201, { ok: true, duplicate: false }],
  ])('status %i classifica como ok=%o', async (status, expected) => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(async () => new Response('{}', { status }));
    const result = await submitCapture(payload());
    expect(result.ok).toBe(expected.ok);
    expect(result.duplicate).toBe(expected.duplicate);
    expect(result.status).toBe(status);
  });

  it('401 → auth; 422 → invalid; 500 → http', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    for (const [status, error] of [
      [401, 'auth'],
      [422, 'invalid'],
      [500, 'http'],
    ] as const) {
      mockFetch(async () => new Response('{}', { status }));
      const result = await submitCapture(payload());
      expect(result.ok).toBe(false);
      expect(result.error).toBe(error);
      expect(result.status).toBe(status);
    }
  });

  it('rejeição de rede → network', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(async () => {
      throw new TypeError('Failed to fetch');
    });
    expect(await submitCapture(payload())).toMatchObject({ ok: false, error: 'network' });
  });

  it('aborto pelo timeout → timeout após 10000 ms', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    vi.useFakeTimers();
    try {
      mockFetch(
        (_url, init) =>
          new Promise((_resolve, reject) => {
            init.signal?.addEventListener('abort', () => {
              reject(new DOMException('aborted', 'AbortError'));
            });
          }),
      );

      const pending = submitCapture(payload());
      await vi.advanceTimersByTimeAsync(REQUEST_TIMEOUT_MS);
      expect(await pending).toMatchObject({ ok: false, error: 'timeout' });
    } finally {
      vi.useRealTimers();
    }
  });

  it('URL fora dos origins declarados não chama fetch', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    await apiBaseUrl.setValue('https://evil.example');
    const fetchSpy = mockFetch(async () => new Response('{}', { status: 201 }));
    const result = await submitCapture(payload());
    expect(result).toMatchObject({ ok: false, error: 'http' });
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('token nunca aparece no resultado nem no storage de sessão', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(async () => new Response('{}', { status: 201 }));
    const result = await submitCapture(payload());
    expect(JSON.stringify(result)).not.toContain('segredo-do-env');
    const stored = await lastApiStatus.getValue();
    expect(stored).toBeNull(); // quem grava lastApiStatus é o background
  });
});

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
        blocks_expected: 1,
        blocks_captured: 1,
      },
      html: '<main data-capture-surface="linkedin_publications" data-capture-version="1"></main>',
    },
    blocks: [],
  };
}

describe('resolveDiscoveryRun', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function mockFetch(fn: (url: string, init: RequestInit) => Promise<Response>): MockInstance {
    const spy = vi.fn(fn);
    vi.stubGlobal('fetch', spy);
    return spy;
  }

  it('POSTa a URL atual e devolve a execução correlacionada', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    await apiBaseUrl.setValue('https://vagas.test');
    const fetchSpy = mockFetch(
      async () =>
        new Response(
          JSON.stringify({
            execution_id: 'exec-1',
            source: 'linkedin',
            surface: 'linkedin_publications',
            initial_url: 'https://www.linkedin.com/search/results/content/?keywords=php',
            expires_at: '2026-08-18T11:00:00Z',
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
    );

    const result = await resolveDiscoveryRun(
      'https://www.linkedin.com/search/results/content/?keywords=php',
    );

    expect(result).toEqual({
      ok: true,
      run: {
        executionId: 'exec-1',
        source: 'linkedin',
        surface: 'linkedin_publications',
        initialUrl: 'https://www.linkedin.com/search/results/content/?keywords=php',
        expiresAt: '2026-08-18T11:00:00Z',
      },
    });
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('https://vagas.test' + RESOLVE_RUN_PATH);
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer segredo-do-env');
    expect(JSON.parse(init.body as string)).toEqual({
      url: 'https://www.linkedin.com/search/results/content/?keywords=php',
    });
  });

  it('404 sem execução ativa é resposta válida com run null', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(async () => new Response('{}', { status: 404 }));

    expect(await resolveDiscoveryRun('https://www.linkedin.com/search/results/content/?keywords=php')).toEqual({
      ok: true,
      run: null,
    });
  });

  it('409 não escolhe execução ambígua', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(async () => new Response('{}', { status: 409 }));

    expect(await resolveDiscoveryRun('https://www.linkedin.com/search/results/content/?keywords=php')).toMatchObject({
      ok: false,
      error: 'conflict',
      status: 409,
    });
  });

  it('gate fechado não chama a rede', async () => {
    const fetchSpy = mockFetch(async () => new Response('{}', { status: 200 }));

    expect(await resolveDiscoveryRun('https://www.linkedin.com/search/results/content/?keywords=php')).toMatchObject({
      ok: false,
      error: 'auth',
    });
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('submitEnvelope', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function mockFetch(fn: (url: string, init: RequestInit) => Promise<Response>): MockInstance {
    const spy = vi.fn(fn);
    vi.stubGlobal('fetch', spy);
    return spy;
  }

  it('POSTa o envelope v1 no mesmo contrato de ingestão e só confirma no ACK', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    await apiBaseUrl.setValue('https://vagas.test');
    const body = envelope();
    const fetchSpy = mockFetch(
      async () =>
        new Response(
          JSON.stringify({
            id: 7,
            execution_id: 'exec-1',
            coverage: 'complete',
            ingest_status: 'received',
            replay: false,
          }),
          { status: 201, headers: { 'Content-Type': 'application/json' } },
        ),
    );

    const result = await submitEnvelope(body);

    expect(result).toEqual({
      ok: true,
      duplicate: false,
      status: 201,
      captureId: 7,
      executionId: 'exec-1',
      coverage: 'complete',
      replay: false,
    });
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('https://vagas.test' + PAGE_CAPTURE_PATH);
    expect(JSON.parse(init.body as string)).toEqual(body);
  });

  it('200 replay é sucesso duplicado', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(
      async () =>
        new Response(JSON.stringify({ id: 7, replay: true, coverage: 'partial' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
    );

    expect(await submitEnvelope(envelope())).toMatchObject({
      ok: true,
      duplicate: true,
      status: 200,
      replay: true,
      coverage: 'partial',
    });
  });

  it.each([
    [401, 'auth'],
    [409, 'conflict'],
    [413, 'too_large'],
    [422, 'invalid'],
    [503, 'http'],
  ])('status %i → error=%s; não declara captura', async (status, error) => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    mockFetch(async () => new Response('{}', { status }));

    const result = await submitEnvelope(envelope());
    expect(result.ok).toBe(false);
    expect(result.error).toBe(error);
    expect(result.status).toBe(status);
  });
});
