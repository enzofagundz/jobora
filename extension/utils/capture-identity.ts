import type { CapturePayload, CaptureSource } from '@/types/capture';

/**
 * Identidade lógica de uma captura, independente da referência DOM.
 * Precedência idêntica à do backend (JobCaptureService::dedupKey):
 * 1. external_id → 'external:<source>:<id>'
 * 2. URL válida   → 'url:<source>:<scheme://host/path>' (sem query/fragment)
 * 3. fingerprint  → 'fingerprint:<source>:<sha256>' sobre o payload composto
 *    normalizado (nunca sobre a descrição isolada).
 */

/** Chave externa explícita (Indeed data-jk, LinkedIn URN). Usada antes do clique. */
export function externalCaptureIdentity(source: CaptureSource, externalId: string): string {
  return `external:${source}:${externalId.trim()}`;
}

/** Colapsa espaços, corta bordas e normaliza caixa — base do fingerprint. */
function normalizeText(value: string | null | undefined): string {
  return (value ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
}

/** scheme://host/path — descarta query/fragment de tracking. null se inválida. */
function canonicalUrl(raw: string): string | null {
  try {
    const url = new URL(raw.trim());
    return `${url.protocol}//${url.host}${url.pathname}`;
  } catch {
    return null;
  }
}

export async function deriveCaptureIdentity(payload: CapturePayload): Promise<string> {
  if (payload.external_id && payload.external_id.trim() !== '') {
    return externalCaptureIdentity(payload.source, payload.external_id);
  }

  if (payload.url) {
    const canonical = canonicalUrl(payload.url);
    if (canonical) return `url:${payload.source}:${canonical}`;
  }

  // Ordem fixa das chaves — JSON.stringify é determinístico para objeto literal.
  const composite = {
    source: payload.source,
    url: payload.url ? canonicalUrl(payload.url) : null,
    title: normalizeText(payload.title),
    company: normalizeText(payload.company),
    location: normalizeText(payload.location),
    author_profile_url: normalizeText(payload.author?.profile_url),
    author_name: normalizeText(payload.author?.name),
    description: normalizeText(payload.description),
  };

  const digest = await crypto.subtle.digest(
    'SHA-256',
    new TextEncoder().encode(JSON.stringify(composite)),
  );
  const hex = Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
  return `fingerprint:${payload.source}:${hex}`;
}
