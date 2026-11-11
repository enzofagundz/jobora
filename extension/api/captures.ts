import type {
  CaptureEnvelope,
  CaptureErrorKind,
  CapturePayload,
  CaptureResult,
  DiscoveryRunContext,
  EnvelopeResult,
  ResolveRunResult,
} from '@/types/capture';
import { apiBaseUrl, apiToken } from '@/utils/storage';
import { isCaptureReady } from '@/utils/capture-control';

export const CAPTURE_PATH = '/api/job-captures';
export const VALIDATE_PATH = '/api/extension/auth/validate';
export const RESOLVE_RUN_PATH = '/api/discovery-runs/resolve';
export const PAGE_CAPTURE_PATH = '/api/page-captures';

/** Origins de API declarados no manifest (host_permissions). Qualquer outro é rejeitado antes do fetch. */
export const REQUEST_TIMEOUT_MS = 10_000;
export const ALLOWED_API_ORIGINS = [
  'http://localhost:8000',
  'http://127.0.0.1:8000',
  'https://vagas.test',
  'https://jobora.test',
  'https://jobora-v2.vagas.test',
] as const;
const ALLOWED_API_ORIGINS_SET: Record<string, true> = Object.fromEntries(
  ALLOWED_API_ORIGINS.map((origin) => [origin, true]),
);

/** Normaliza a base: sem barra final; null se inválida/fora dos origins declarados. */
export function sanitizeApiBaseUrl(raw: string): string | null {
  const trimmed = raw.trim().replace(/\/+$/, '');
  try {
    const url = new URL(trimmed);
    if (url.search || url.hash) return null;
    return ALLOWED_API_ORIGINS_SET[url.origin] ? url.origin : null;
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


export type ValidatedCredentialResult =
  | { ok: true; ownerUserId: number }
  | { ok: false; error: CaptureErrorKind; status?: number };

/** Valida a credencial no background e devolve o owner autenticado pelo backend. */
export async function validateCaptureCredential(
  credential?: CaptureCredential,
): Promise<ValidatedCredentialResult> {
  const result = await authorizedFetch(
    VALIDATE_PATH,
    {
      method: 'GET',
    },
    credential,
  );

  if ('error' in result) return { ok: false, error: result.error, status: result.status };
  if (result.response.status !== 200) {
    return { ok: false, error: 'http', status: result.response.status };
  }

  const body = await readJson(result.response);
  const owner = body.owner_user_id;
  if (body.valid !== true || typeof owner !== 'number' || !Number.isInteger(owner) || owner <= 0) {
    return { ok: false, error: 'invalid', status: 200 };
  }

  return { ok: true, ownerUserId: owner };
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

export interface CaptureCredential {
  origin: string;
  token: string;
}

type AuthorizedResponse =
  | { response: Response }
  | { error: CaptureErrorKind; status?: number };

/** Fetch autenticado reutilizado pelos contratos de descoberta/envelope. */
async function authorizedFetch(
  path: string,
  init: RequestInit,
  credential?: CaptureCredential,
): Promise<AuthorizedResponse> {
  if (!credential && !(await isCaptureReady())) return { error: 'auth' };

  const base = credential?.origin ?? (await apiBaseUrl.getValue());
  const token = (credential?.token ?? (await apiToken.getValue())).trim();
  const origin = sanitizeApiBaseUrl(base);

  if (!origin) return { error: 'http' };
  if (!token) return { error: 'auth' };

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    const response = await fetch(origin + path, {
      ...init,
      headers: {
        Authorization: `Bearer ${token}`,
        ...(init.headers as Record<string, string> | undefined),
      },
      signal: controller.signal,
    });
    if (response.status === 401) return { error: 'auth', status: 401 };
    return { response };
  } catch (err) {
    return err instanceof DOMException && err.name === 'AbortError'
      ? { error: 'timeout' }
      : { error: 'network' };
  } finally {
    clearTimeout(timer);
  }
}

async function readJson(response: Response): Promise<Record<string, unknown>> {
  try {
    const body: unknown = await response.json();
    return typeof body === 'object' && body !== null ? (body as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

/**
 * POST /api/discovery-runs/resolve: correlação autenticada pela URL canônica.
 * 404 é ausência de execução ativa (captura espontânea), 409 é ambiguidade.
 */
export async function resolveDiscoveryRun(
  url: string,
  credential?: CaptureCredential,
): Promise<ResolveRunResult> {
  const result = await authorizedFetch(
    RESOLVE_RUN_PATH,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url }),
    },
    credential,
  );

  if ('error' in result) return { ok: false, error: result.error, status: result.status };

  if (result.response.status === 404) return { ok: true, run: null };
  if (result.response.status === 409) return { ok: false, error: 'conflict', status: 409 };
  if (result.response.status !== 200) {
    return { ok: false, error: 'http', status: result.response.status };
  }

  const body = await readJson(result.response);
  const executionId = body.execution_id;
  const source = body.source;
  const surface = body.surface;

  if (typeof executionId !== 'string' || executionId === '') {
    return { ok: false, error: 'invalid', status: 200 };
  }

  const run: DiscoveryRunContext = {
    executionId,
    source: source === 'indeed' ? 'indeed' : 'linkedin',
    surface:
      surface === 'linkedin_jobs' || surface === 'indeed_jobs'
        ? surface
        : 'linkedin_publications',
    initialUrl: typeof body.initial_url === 'string' ? body.initial_url : url,
    expiresAt: typeof body.expires_at === 'string' ? body.expires_at : null,
  };

  return { ok: true, run };
}

/**
 * POST /api/page-captures: único ACK que declara a página capturada.
 * 201 nova, 200 replay, 409 conflito de chave, 413 tamanho, 422 inválido.
 */
export async function submitEnvelope(
  envelope: CaptureEnvelope,
  credential?: CaptureCredential,
): Promise<EnvelopeResult> {
  const result = await authorizedFetch(
    PAGE_CAPTURE_PATH,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(envelope),
    },
    credential,
  );

  if ('error' in result) return { ok: false, error: result.error, status: result.status };

  const status = result.response.status;
  const body = await readJson(result.response);
  const coverage: EnvelopeResult['coverage'] =
    body.coverage === 'complete'
      ? 'complete'
      : body.coverage === 'partial'
        ? 'partial'
        : undefined;
  const retryAfterMs = parseRetryAfter(result.response.headers.get('retry-after'));
  const details = {
    captureId:
      typeof body.id === 'number' || typeof body.id === 'string' ? body.id : undefined,
    executionId: typeof body.execution_id === 'string' ? body.execution_id : undefined,
    coverage,
    replay: typeof body.replay === 'boolean' ? body.replay : undefined,
    retryAfterMs,
  };

  if (status === 201) return { ok: true, duplicate: false, status, ...details };
  if (status === 200) return { ok: true, duplicate: true, status, ...details };
  if (status === 409) return { ok: false, error: 'conflict', status, ...details };
  if (status === 413) return { ok: false, error: 'too_large', status, ...details };
  if (status === 422) return { ok: false, error: 'invalid', status, ...details };
  return { ok: false, error: 'http', status, ...details };
}

/** Retry-After em segundos ou HTTP-date; ignorado quando ausente/inválido. */
function parseRetryAfter(raw: string | null): number | undefined {
  if (!raw) return undefined;
  const trimmed = raw.trim();
  if (/^\d+$/.test(trimmed)) return Number(trimmed) * 1000;
  const date = Date.parse(trimmed);
  return Number.isNaN(date) ? undefined : Math.max(0, date - Date.now());
}
