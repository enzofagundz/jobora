// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import type { CaptureMessage, CaptureResult } from '@/types/capture';
import { submitCapture } from '@/api/captures';
import { isCaptureReady } from '@/utils/capture-control';
import { apiToken, captureEnabled, lastApiStatus } from '@/utils/storage';

/** Contrato do handler do background: token vem só do storage, nunca da mensagem;
 * gate de captura fecha antes do payload/toque na rede. */
async function handleMessage(message: unknown): Promise<CaptureResult> {
  if (
    typeof message !== 'object' ||
    message === null ||
    (message as CaptureMessage).type !== 'capture:submit'
  ) {
    return { ok: false, error: 'invalid' };
  }
  if (!(await isCaptureReady())) return { ok: false, error: 'auth' };
  const payload = (message as CaptureMessage).payload;
  const result = await submitCapture(payload);
  await lastApiStatus.setValue({
    ok: result.ok,
    duplicate: result.duplicate,
    error: result.error,
    status: result.status,
    at: new Date().toISOString(),
  });
  return result;
}

function payload(): CaptureMessage['payload'] {
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
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  function mockFetch(status: number): ReturnType<typeof vi.fn> {
    const spy = vi.fn(async () => new Response('{}', { status }));
    vi.stubGlobal('fetch', spy);
    return spy;
  }

  it('token vem do storage; mensagem não carrega segredo', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    const fetchSpy = mockFetch(201);

    const message = { type: 'capture:submit' as const, payload: payload() };
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
    const result = await handleMessage({ type: 'capture:submit', payload: payload() });
    expect(result.ok).toBe(false);
    expect(result.error).toBe(error);

    const stored = await lastApiStatus.getValue();
    expect(stored).toMatchObject({ ok: false, error });
  });

  it('gate fechado → auth, zero fetch e lastApiStatus intacto', async () => {
    const fetchSpy = mockFetch(200);
    const result = await handleMessage({ type: 'capture:submit', payload: payload() });
    expect(result).toMatchObject({ ok: false, error: 'auth' });
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(await lastApiStatus.getValue()).toBeNull();

    // Token presente, mas flag desligada: também bloqueia.
    await apiToken.setValue('segredo-do-env');
    const withToken = await handleMessage({ type: 'capture:submit', payload: payload() });
    expect(withToken).toMatchObject({ ok: false, error: 'auth' });
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('mensagem estranha é rejeitada sem tocar a rede', async () => {
    await captureEnabled.setValue(true);
    await apiToken.setValue('segredo-do-env');
    const fetchSpy = mockFetch(200);
    const result = await handleMessage({ type: 'outra-coisa' });
    expect(result).toMatchObject({ ok: false, error: 'invalid' });
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('limpar token remove o valor do storage (popup Desconectar)', async () => {
    await apiToken.setValue('segredo-do-env');
    await apiToken.removeValue();
    expect(await apiToken.getValue()).toBe('');
  });
});
