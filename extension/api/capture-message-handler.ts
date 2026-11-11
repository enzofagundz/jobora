import type {
  CaptureEnvelope,
  CaptureErrorKind,
  CaptureMessage,
  CaptureQueueDrainResult,
  CaptureQueueStatus,
  CaptureResult,
  CaptureSource,
  CaptureSurface,
  EnvelopeResult,
  ResolveRunResult,
} from '@/types/capture';
import {
  resolveDiscoveryRun,
  sanitizeApiBaseUrl,
  submitCapture,
  submitEnvelope,
  validateCaptureCredential,
} from '@/api/captures';
import { isCaptureReady } from '@/utils/capture-control';
import { apiBaseUrl, apiToken } from '@/utils/storage';
import { lastApiStatus } from '@/utils/session-storage';
import {
  credentialFingerprint,
  type CaptureQueue,
  type CaptureQueueRecord,
  type CaptureQueueTarget,
} from '@/utils/capture-queue';
import {
  newCredentialContextId,
  type CredentialContext,
  type CredentialContextStore,
} from '@/utils/credential-context';
import type { CaptureQueueWake } from '@/utils/capture-queue-wake';
import { ownerCacheKey, type OwnerCache } from '@/utils/owner-cache';

export type CaptureMessageResult =
  | CaptureResult
  | ResolveRunResult
  | EnvelopeResult
  | { ok: true; status: CaptureQueueStatus }
  | { ok: true; drain: CaptureQueueDrainResult };

interface Credential {
  origin: string;
  token: string;
  fingerprint: string;
}

export interface CaptureMessageHandler {
  (message: unknown): Promise<CaptureMessageResult | undefined>;
  /** Retoma fila pendente após restart/suspensão do service worker. */
  flush(): Promise<void>;
}

const NOOP_WAKE: CaptureQueueWake = { schedule: () => {} };
const OWNER_CACHE_TTL_MS = 5 * 60_000;
const OWNER_NEGATIVE_TTL_MS = 30_000;

export function createCaptureMessageHandler(
  queue: CaptureQueue,
  contexts: CredentialContextStore,
  wake: CaptureQueueWake = NOOP_WAKE,
  owners: OwnerCache = createInMemoryOwnerCache(),
): CaptureMessageHandler {
  let draining: Promise<CaptureQueueDrainResult | null> | null = null;
  const ownerLookups = new Map<string, Promise<number | null>>();
  const ownerNegativeUntil = new Map<string, number>();

  async function currentCredential(): Promise<Credential | null> {
    if (!(await isCaptureReady())) return null;

    const [base, token] = await Promise.all([
      apiBaseUrl.getValue(),
      apiToken.getValue(),
    ]);
    const origin = sanitizeApiBaseUrl(base);
    const trimmed = token.trim();

    if (!origin || !trimmed) return null;

    return {
      origin,
      token: trimmed,
      fingerprint: await credentialFingerprint(trimmed),
    };
  }

  function targetOf(
    credential: Credential,
    ownerUserId: number | null = null,
  ): CaptureQueueTarget {
    return {
      origin: credential.origin,
      credentialFingerprint: credential.fingerprint,
      ownerUserId,
    };
  }

  function sameCredential(a: Credential, b: Credential): boolean {
    return a.origin === b.origin && a.fingerprint === b.fingerprint;
  }

  async function validatedCredential(): Promise<
    | { ok: true; credential: Credential; ownerUserId: number }
    | { ok: false; error: CaptureErrorKind; status?: number }
  > {
    const credential = await currentCredential();
    if (!credential) return { ok: false, error: 'auth' };

    const validation = await validateCaptureCredential({
      origin: credential.origin,
      token: credential.token,
    });
    if (!validation.ok) {
      return { ok: false, error: validation.error, status: validation.status };
    }

    const current = await currentCredential();
    if (!current || !sameCredential(current, credential)) {
      return { ok: false, error: 'credential_changed' };
    }

    await owners.put(
      credential.origin,
      credential.fingerprint,
      validation.ownerUserId,
    );
    return { ok: true, credential, ownerUserId: validation.ownerUserId };
  }

  async function ownerForStatus(credential: Credential): Promise<number | null> {
    const key = ownerCacheKey(credential.origin, credential.fingerprint);
    const cached = await owners.get(credential.origin, credential.fingerprint);
    if (cached && Date.now() - cached.validatedAt <= OWNER_CACHE_TTL_MS) {
      return cached.ownerUserId;
    }
    if ((ownerNegativeUntil.get(key) ?? 0) > Date.now()) return null;

    const inFlight = ownerLookups.get(key);
    if (inFlight) return inFlight;

    const lookup = (async () => {
      try {
        const validation = await validateCaptureCredential({
          origin: credential.origin,
          token: credential.token,
        });
        if (!validation.ok) {
          ownerNegativeUntil.set(key, Date.now() + OWNER_NEGATIVE_TTL_MS);
          return null;
        }
        await owners.put(
          credential.origin,
          credential.fingerprint,
          validation.ownerUserId,
        );
        ownerNegativeUntil.delete(key);
        return validation.ownerUserId;
      } finally {
        ownerLookups.delete(key);
      }
    })();

    ownerLookups.set(key, lookup);
    return lookup;
  }

  async function stillCurrent(credential: Credential): Promise<boolean> {
    const current = await currentCredential();
    return !!current && sameCredential(current, credential);
  }

  async function drainCurrent(
    force = false,
    credentialOverride?: Credential,
  ): Promise<CaptureQueueDrainResult | null> {
    if (draining) return draining;

    draining = (async () => {
      const credential = credentialOverride ?? (await currentCredential());
      if (!credential) return null;

      // Mesmo dono com token trocado continua entregável: status e drain
      // precisam julgar a fila pelo mesmo critério.
      const target = targetOf(credential, await ownerForStatus(credential));
      const summary = await queue.drain(
        target,
        (envelope) =>
          submitEnvelope(envelope, { origin: credential.origin, token: credential.token }),
        force,
      );
      // Retry em background também atualiza o último resultado visível.
      for (const item of summary.results) await writeStatus(item.result);
      scheduleRetry(summary.next_attempt_at);
      return summary;
    })();

    try {
      return await draining;
    } finally {
      draining = null;
    }
  }

  function scheduleRetry(nextAttemptAt: number | null): void {
    wake.schedule(nextAttemptAt);
  }

  async function flush(): Promise<void> {
    const queued = await queue.status();
    if (queued.items.length === 0) return;

    const credential = await currentCredential();
    if (!credential) return;

    // Token/destino trocados deixariam a fila presa na credencial antiga.
    await queue.retarget(targetOf(credential), () => ownerForStatus(credential));

    // Startup/retomada respeita backoff e Retry-After já persistidos.
    await drainCurrent(false, credential);
  }

  async function issueContext(
    credential: Credential,
    ownerUserId: number,
    run: {
      executionId: string | null;
      source: CaptureSource | null;
      surface: CaptureSurface | null;
    },
  ): Promise<string> {
    const id = newCredentialContextId();
    await contexts.put(id, {
      origin: credential.origin,
      credentialFingerprint: credential.fingerprint,
      ownerUserId,
      executionId: run.executionId,
      source: run.source,
      surface: run.surface,
      createdAt: Date.now(),
    });
    return id;
  }

  async function handler(message: unknown): Promise<CaptureMessageResult | undefined> {
    if (!isCaptureMessage(message)) return undefined;

    if (message.type === 'capture:queue-status') {
      const credential = await currentCredential();
      if (!credential) return { ok: false, error: 'auth' };

      const cached = await owners.get(credential.origin, credential.fingerprint);
      if (cached && Date.now() - cached.validatedAt <= OWNER_CACHE_TTL_MS) {
        const status = await queue.status(
          targetOf(credential, cached.ownerUserId),
        );
        if (!(await stillCurrent(credential))) {
          return { ok: false, error: 'credential_changed' };
        }
        return { ok: true, status };
      }

      // Fingerprint local sempre primeiro e sem rede.
      const local = await queue.status(targetOf(credential, null));
      if (local.pending + local.blocked > 0) {
        if (!(await stillCurrent(credential))) {
          return { ok: false, error: 'credential_changed' };
        }
        return { ok: true, status: local };
      }

      const ownerUserId = await ownerForStatus(credential);
      const status = await queue.status(targetOf(credential, ownerUserId));
      if (!(await stillCurrent(credential))) {
        return { ok: false, error: 'credential_changed' };
      }
      return { ok: true, status };
    }

    if (message.type === 'capture:queue-drain') {
      const credential = await currentCredential();
      if (!credential) return { ok: false, error: 'auth' };
      const summary = await drainCurrent(message.force !== false, credential);
      return summary
        ? { ok: true, drain: summary }
        : { ok: false, error: 'auth' };
    }

    if (message.type === 'capture:queue-resume') {
      const validated = await validatedCredential();
      if (!validated.ok) return validated;
      const target = targetOf(validated.credential, validated.ownerUserId);
      const outcome = await queue.resumeAuth(message.idempotency_key, target);
      if (outcome !== 'resumed') {
        return {
          ok: false,
          error:
            outcome === 'credential_changed'
              ? 'credential_changed'
              : outcome === 'not_found'
                ? 'not_found'
                : outcome === 'ambiguous'
                  ? 'ambiguous'
                  : outcome === 'storage'
                    ? 'storage'
                    : 'invalid',
        };
      }
      await drainCurrent(true, validated.credential);
      if (!(await stillCurrent(validated.credential))) {
        return { ok: false, error: 'credential_changed' };
      }
      return { ok: true, status: await queue.status(target) };
    }

    if (message.type === 'capture:queue-discard') {
      const validated = await validatedCredential();
      if (!validated.ok) return validated;
      const target = targetOf(validated.credential, validated.ownerUserId);
      const outcome = await queue.discard(message.idempotency_key, target);
      if (outcome !== 'discarded') {
        return {
          ok: false,
          error:
            outcome === 'credential_changed'
              ? 'credential_changed'
              : outcome === 'ambiguous'
                ? 'ambiguous'
                : 'not_found',
        };
      }
      if (!(await stillCurrent(validated.credential))) {
        return { ok: false, error: 'credential_changed' };
      }
      return { ok: true, status: await queue.status(target) };
    }

    if (!(await isCaptureReady())) return { ok: false, error: 'auth' };

    if (message.type === 'capture:resolve-run') {
      if (typeof message.url !== 'string' || message.url.trim() === '') {
        return { ok: false, error: 'invalid' };
      }

      const validated = await validatedCredential();
      if (!validated.ok) return validated;
      const credential = validated.credential;

      const result = await resolveDiscoveryRun(message.url, {
        origin: credential.origin,
        token: credential.token,
      });

      if (result.ok) {
        return {
          ...result,
          context: await issueContext(credential, validated.ownerUserId, {
            executionId: result.run?.executionId ?? null,
            source: result.run?.source ?? null,
            surface: result.run?.surface ?? null,
          }),
        };
      }

      if (result.error === 'network' || result.error === 'timeout' || result.error === 'http') {
        return {
          ...result,
          context: await issueContext(credential, validated.ownerUserId, {
            executionId: null,
            source: null,
            surface: null,
          }),
        };
      }

      return result;
    }

    if (message.type === 'capture:submit-envelope') {
      if (!isCaptureEnvelope(message.envelope)) {
        return { ok: false, error: 'invalid' };
      }
      if (typeof message.context !== 'string' || message.context === '') {
        return { ok: false, error: 'invalid' };
      }

      const binding = await contexts.get(message.context);
      if (!binding || !envelopeMatchesBinding(message.envelope, binding)) {
        return { ok: false, error: 'invalid' };
      }

      const target: CaptureQueueTarget = {
        origin: binding.origin,
        credentialFingerprint: binding.credentialFingerprint,
        ownerUserId: binding.ownerUserId,
      };
      const enqueued = await queue.enqueue(message.envelope, target);
      if (!enqueued.ok) return { ok: false, error: enqueued.error };

      const credential = await currentCredential();
      if (!credential || !sameTarget(credential, binding)) {
        const visible: EnvelopeResult = {
          ok: false,
          error: credential ? 'credential_changed' : 'auth',
          queued: true,
          executionId: message.envelope.capture.execution_id,
          coverage: message.envelope.capture.coverage.status,
        };
        await writeStatus(visible);
        return visible;
      }

      const summary = await drainCurrent(false, credential);
      const outcome = outcomeFor(summary, message.envelope.capture.idempotency_key);
      const result = outcome ?? fallbackResult(await queue.get(enqueued.record.id));
      const queued = !result.ok;
      const visible = queued ? { ...result, queued } : result;

      await writeStatus(visible);
      return visible;
    }

    if (!message.payload || typeof message.payload !== 'object') {
      return { ok: false, error: 'invalid' };
    }

    const result = await submitCapture(message.payload);
    await writeStatus(result);
    return result;
  }

  handler.flush = flush;
  return handler;
}

function envelopeMatchesBinding(
  envelope: CaptureEnvelope,
  binding: CredentialContext,
): boolean {
  if (binding.executionId !== null && envelope.capture.execution_id !== binding.executionId) {
    return false;
  }
  if (binding.source !== null && envelope.capture.source !== binding.source) return false;
  if (binding.surface !== null && envelope.capture.surface !== binding.surface) return false;
  return true;
}

function sameTarget(credential: Credential, binding: CredentialContext): boolean {
  return (
    credential.origin === binding.origin &&
    credential.fingerprint === binding.credentialFingerprint
  );
}

function createInMemoryOwnerCache(): OwnerCache {
  const entries = new Map<string, { ownerUserId: number; validatedAt: number }>();
  return {
    async get(origin, credentialFingerprint) {
      return entries.get(ownerCacheKey(origin, credentialFingerprint)) ?? null;
    },
    async put(origin, credentialFingerprint, ownerUserId) {
      entries.set(ownerCacheKey(origin, credentialFingerprint), {
        ownerUserId,
        validatedAt: Date.now(),
      });
    },
  };
}

function outcomeFor(
  summary: CaptureQueueDrainResult | null,
  idempotencyKey: string,
): EnvelopeResult | undefined {
  return summary?.results.find((item) => item.idempotency_key === idempotencyKey)?.result;
}

function fallbackResult(record: CaptureQueueRecord | null): EnvelopeResult {
  if (!record) return { ok: false, error: 'network' };
  return {
    ok: false,
    error: record.lastError ?? 'network',
    status: record.lastStatus,
    queued: true,
    executionId: record.envelope.capture.execution_id,
    coverage: record.envelope.capture.coverage.status,
  };
}

async function writeStatus(result: CaptureResult): Promise<void> {
  await lastApiStatus.setValue({
    ok: result.ok,
    duplicate: result.duplicate,
    error: result.error,
    status: result.status,
    at: new Date().toISOString(),
  });
}

function isCaptureMessage(message: unknown): message is CaptureMessage {
  if (typeof message !== 'object' || message === null) return false;
  const type = (message as { type?: unknown }).type;
  return (
    type === 'capture:submit' ||
    type === 'capture:resolve-run' ||
    type === 'capture:submit-envelope' ||
    type === 'capture:queue-status' ||
    type === 'capture:queue-drain' ||
    type === 'capture:queue-resume' ||
    type === 'capture:queue-discard'
  );
}

function isCaptureEnvelope(value: unknown): value is CaptureEnvelope {
  if (typeof value !== 'object' || value === null) return false;
  const envelope = value as Partial<CaptureEnvelope>;
  return (
    envelope.format === 'jobora.capture-envelope' &&
    envelope.version === 1 &&
    typeof envelope.capture === 'object' &&
    envelope.capture !== null &&
    Array.isArray(envelope.blocks)
  );
}
