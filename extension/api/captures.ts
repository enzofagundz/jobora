import type { CapturePayload, CaptureResult } from '@/types/capture';
import { apiBaseUrl, apiToken } from '@/utils/storage';
import { isCaptureReady } from '@/utils/capture-control';

export const CAPTURE_PATH = '/api/job-captures';
export const VALIDATE_PATH = '/api/extension/auth/validate';

/** Origins de API declarados no manifest (host_permissions). Qualquer outro é rejeitado antes do fetch. */
export const REQUEST_TIMEOUT_MS = 10_000;
const ALLOWED_API_ORIGINS: Record<string, true> = {
  'http://localhost:8000': true,
  'http://127.0.0.1:8000': true,
  'https://vagas.test': true,
  'https://jobora.test': true,
};

/** Normaliza a base: sem barra final; null se inválida/fora dos origins declarados. */
export function sanitizeApiBaseUrl(raw: string): string | null {
  const trimmed = raw.trim().replace(/\/+$/, '');
  try {
    const url = new URL(trimmed);
    if (url.search || url.hash) return null;
    return ALLOWED_API_ORIGINS[url.origin] ? url.origin : null;
  } catch {
    return null;
  }
}

/**
 * POST /api/job-captures com classificação byte a byte dos status:
 * 201 nova, 200 duplicada, 401 auth, 422 invalid, demais http,
 * rejeição de rede network e abort timeout.
 * Lê apenas status/resultado — nunca loga token nem payload.
 */
export async function submitCapture(payload: CapturePayload): Promise<CaptureResult> {
  // Defesa dupla: nenhum chamador bypassa o gate do background.
  if (!(await isCaptureReady())) return { ok: false, error: 'auth' };

  const base = await apiBaseUrl.getValue();
  const token = (await apiToken.getValue()).trim();
  const origin = sanitizeApiBaseUrl(base);

  if (!origin) {
    return { ok: false, error: 'http' };
  }

  if (!token) {
    return { ok: false, error: 'auth' };
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    const res = await fetch(origin + CAPTURE_PATH, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
      signal: controller.signal,
    });

    if (res.status === 200) return { ok: true, duplicate: true, status: res.status };
    if (res.status === 201) return { ok: true, duplicate: false, status: res.status };
    if (res.status === 401) return { ok: false, error: 'auth', status: res.status };
    if (res.status === 422) return { ok: false, error: 'invalid', status: res.status };
    return { ok: false, error: 'http', status: res.status };
  } catch (err) {
    // AbortError do nosso timer = timeout; qualquer outra rejeição = rede/DNS/offline.
    return err instanceof DOMException && err.name === 'AbortError'
      ? { ok: false, error: 'timeout' }
      : { ok: false, error: 'network' };
  } finally {
    clearTimeout(timer);
  }
}

/**
 * GET /api/extension/auth/validate — valida a credencial individual do usuário.
 * Mesma classificação de status de submitCapture, sem logar token.
 */
export async function validateCaptureToken(): Promise<CaptureResult> {
  if (!(await isCaptureReady())) return { ok: false, error: 'auth' };

  const base = await apiBaseUrl.getValue();
  const token = (await apiToken.getValue()).trim();
  const origin = sanitizeApiBaseUrl(base);

  if (!origin) {
    return { ok: false, error: 'http' };
  }

  if (!token) {
    return { ok: false, error: 'auth' };
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    const res = await fetch(origin + VALIDATE_PATH, {
      method: 'GET',
      headers: {
        Authorization: `Bearer ${token}`,
      },
      signal: controller.signal,
    });

    if (res.status === 200) return { ok: true, status: res.status };
    if (res.status === 401) return { ok: false, error: 'auth', status: res.status };
    return { ok: false, error: 'http', status: res.status };
  } catch (err) {
    return err instanceof DOMException && err.name === 'AbortError'
      ? { ok: false, error: 'timeout' }
      : { ok: false, error: 'network' };
  } finally {
    clearTimeout(timer);
  }
}
