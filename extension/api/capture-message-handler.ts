import type {
  CaptureEnvelope,
  CaptureErrorKind,
  CaptureMessage,
  CaptureQueueDrainResult,
  CaptureQueueStatus,
  CaptureResult,
  EnvelopeResult,
  ResolveRunResult,
} from '@/types/capture';
import {
  resolveDiscoveryRun,
  sanitizeApiBaseUrl,
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
  (message: unknown, sender?: CaptureSender): Promise<CaptureMessageResult | undefined>;
  /** Retoma fila pendente após restart/suspensão do service worker. */
  flush(): Promise<void>;
  tabClosed(tabId: number): Promise<void>;
}

export interface CaptureSender {
  tab?: { id?: number };
  frameId?: number;
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
  const generations = new Map<string, number>();
  const closedTabs = new Set<number>();

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

  async function bindingCurrent(credential: Credential, binding: CredentialContext): Promise<boolean> {
    if (!(await stillCurrent(credential))) return false;
    const owner = await owners.get(credential.origin, credential.fingerprint);
    return !owner || owner.ownerUserId === binding.ownerUserId;
  }

  async function drainCurrent(
    force = false,
    credentialOverride?: Credential,
  ): Promise<CaptureQueueDrainResult | null> {
    if (draining) return draining;

    draining = (async () => {
      const credential = credentialOverride ?? (await currentCredential());
      if (!credential) return null;

      const target = targetOf(credential);
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
    // Startup/retomada respeita backoff e Retry-After já persistidos.
    await drainCurrent(false);
  }

  async function issueContext(scopeKey: string, binding: CredentialContext): Promise<string> {
    const id = newCredentialContextId();
    if (!(await contexts.putIfRevision(scopeKey, binding.revision, binding))) {
      throw new Error('Capture resolution superseded');
    }
    await contexts.put(id, { ...binding, scopeKey });
    if (!(await contexts.putIfRevision(scopeKey, binding.revision, { ...binding, contextId: id }))) {
      throw new Error('Capture resolution superseded');
    }
    return id;
  }

  async function handler(message: unknown, sender?: CaptureSender): Promise<CaptureMessageResult | undefined> {
    if (!isCaptureMessage(message)) return undefined;
    if (sender?.tab?.id !== undefined && closedTabs.has(sender.tab.id)) return { ok: false, error: 'invalid' };
    const senderKey = Number.isInteger(sender?.tab?.id) && sender!.tab!.id! >= 0 && Number.isInteger(sender?.frameId) && sender!.frameId! >= 0
      ? `${sender!.tab!.id}:${sender!.frameId}` : null;
    let generation: number | undefined;
    if (message.type === 'capture:resolve-run') {
      if (!senderKey) return { ok: false, error: 'invalid' };
      generation = (generations.get(senderKey) ?? 0) + 1;
      generations.set(senderKey, generation);
    }

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
      if (generations.get(senderKey!) !== generation) return { ok: false, error: 'reservation_changed' };
      const scopeKey = JSON.stringify(['capture-scope', senderKey, credential.origin, credential.fingerprint, validated.ownerUserId]);
      const previous = await contexts.get(scopeKey);
      if (generations.get(senderKey!) !== generation) return { ok: false, error: 'reservation_changed' };
      const revision = newCredentialContextId();
      const pending: CredentialContext = previous ?? {
        origin: credential.origin, credentialFingerprint: credential.fingerprint, ownerUserId: validated.ownerUserId,
        executionId: null, source: null, surface: null, senderKey: senderKey!, createdAt: Date.now(),
      };
      try {
        await contexts.put(scopeKey, { ...pending, revision });
      } catch {
        return { ok: false, error: 'storage' };
      }
      const result = await resolveDiscoveryRun(message.url, {
        origin: credential.origin,
        token: credential.token,
      });
      if (!(await stillCurrent(credential))) return { ok: false, error: 'credential_changed' };
      if (closedTabs.has(sender!.tab!.id!) || generations.get(senderKey!) !== generation || (await contexts.get(scopeKey))?.revision !== revision) {
        return { ok: false, error: 'reservation_changed' };
      }
      if (pending.executionId !== null) {
        if (!result.ok || result.run?.executionId !== pending.executionId || result.run.source !== pending.source || result.run.surface !== pending.surface || expired(pending)) {
          await contexts.putIfRevision(scopeKey, revision, { ...pending, revision, blocked: true });
          await writeStatus({ ok: false, error: 'reservation_changed' });
          return { ok: false, error: 'reservation_changed' };
        }
        if (!(await contexts.putIfRevision(scopeKey, revision, { ...pending, revision, blocked: false }))) return { ok: false, error: 'reservation_changed' };
        if (!(await bindingCurrent(credential, pending))) return { ok: false, error: 'credential_changed' };
        if (generations.get(senderKey!) !== generation) return { ok: false, error: 'reservation_changed' };
        return { ...result, context: pending.contextId };
      }
      if (previous && !previous.contextId && (!result.ok || !result.run)) {
        return { ok: false, error: 'reservation_changed' };
      }
      if (!result.ok && !['network', 'timeout', 'http'].includes(result.error)) return result;
      try {
        const run = result.ok ? result.run : null;
        const context = await issueContext(scopeKey, { ...pending, revision,
          executionId: run?.executionId ?? null, source: run?.source ?? null, surface: run?.surface ?? null,
          observedUrl: message.url, expiresAt: run?.expiresAt ?? null,
        });
        if (!(await bindingCurrent(credential, pending))) return { ok: false, error: 'credential_changed' };
        if (closedTabs.has(sender!.tab!.id!) || generations.get(senderKey!) !== generation || (await contexts.get(scopeKey))?.revision !== revision) return { ok: false, error: 'reservation_changed' };
        return { ...result, context };
      } catch {
        return { ok: false, error: 'storage' };
      }
    }

    if (message.type === 'capture:submit-envelope') {
      if (!isCaptureEnvelope(message.envelope)) {
        return { ok: false, error: 'invalid' };
      }
      if (typeof message.context !== 'string' || message.context === '') {
        return { ok: false, error: 'invalid' };
      }

      const binding = await contexts.get(message.context);
      if (!senderKey || !binding || binding.senderKey !== senderKey || !binding.scopeKey || !envelopeMatchesBinding(message.envelope, binding)) {
        return { ok: false, error: 'invalid' };
      }
      const pin = await contexts.get(binding.scopeKey);
      if (pin?.contextId !== message.context || pin.executionId !== binding.executionId) return { ok: false, error: 'reservation_changed' };
      const credential = await currentCredential();
      if (!credential || !sameTarget(credential, binding) || !(await bindingCurrent(credential, binding))) {
        return { ok: false, error: credential ? 'credential_changed' : 'auth' };
      }
      const target: CaptureQueueTarget = {
        origin: binding.origin,
        credentialFingerprint: binding.credentialFingerprint,
        ownerUserId: binding.ownerUserId,
      };
      const payloadHash = await credentialFingerprint(JSON.stringify(message.envelope));
      if (!(await bindingCurrent(credential, binding))) return { ok: false, error: 'credential_changed' };
      if (binding.acceptedPayloadHash && binding.acceptedPayloadHash !== payloadHash) return { ok: false, error: 'reservation_changed' };
      if (binding.receipt && binding.acceptedPayloadHash === payloadHash) return { ...binding.receipt, duplicate: true };
      const exactReplay = binding.acceptedPayloadHash === payloadHash;
      if (!exactReplay && pin.blocked) return { ok: false, error: 'reservation_changed' };
      if (!exactReplay && binding.executionId !== null) {
        if (expired(binding)) return { ok: false, error: 'reservation_changed' };
        if (binding.surface === 'linkedin_jobs') {
          const urls = new Set([message.envelope.capture.final_url]);
          if (message.envelope.capture.initial_url !== binding.observedUrl) urls.add(message.envelope.capture.initial_url);
          for (const url of urls) {
            const resolved = await resolveDiscoveryRun(url, { origin: credential.origin, token: credential.token });
            if (!(await bindingCurrent(credential, binding))) return { ok: false, error: 'credential_changed' };
            if ((await contexts.get(binding.scopeKey))?.revision !== pin.revision) return { ok: false, error: 'reservation_changed' };
            if (!resolved.ok || resolved.run?.executionId !== binding.executionId || resolved.run.source !== binding.source || resolved.run.surface !== binding.surface) {
              await contexts.putIfRevision(binding.scopeKey, pin.revision, { ...pin, blocked: true });
              await writeStatus({ ok: false, error: 'reservation_changed' });
              return { ok: false, error: 'reservation_changed' };
            }
          }
        }
      }
      if (!(await bindingCurrent(credential, binding))) return { ok: false, error: 'credential_changed' };
      const currentPin = await contexts.get(binding.scopeKey);
      if (currentPin?.contextId !== message.context || currentPin.revision !== pin.revision) return { ok: false, error: 'reservation_changed' };
      const accepted = exactReplay ? binding : { ...binding, acceptedPayloadHash: payloadHash, revision: newCredentialContextId() };
      if (!exactReplay) {
        try {
          if (!(await contexts.putIfRevision(message.context, binding.revision, accepted))) return { ok: false, error: 'reservation_changed' };
        } catch {
          return { ok: false, error: 'storage' };
        }
      }
      if (!(await bindingCurrent(credential, binding))) return { ok: false, error: 'credential_changed' };
      if ((await contexts.get(binding.scopeKey))?.revision !== pin.revision) return { ok: false, error: 'reservation_changed' };
      const enqueued = await queue.enqueue(message.envelope, target);
      if (!enqueued.ok) return { ok: false, error: enqueued.error };

      const credentialCurrent = await bindingCurrent(credential, binding);
      if (!credentialCurrent || (await contexts.get(binding.scopeKey))?.revision !== pin.revision) {
        if (!enqueued.existing) await queue.discard(message.envelope.capture.idempotency_key, target);
        const visible: EnvelopeResult = {
          ok: false,
          error: credentialCurrent ? 'reservation_changed' : 'credential_changed',
          queued: enqueued.existing,
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
      if (result.ok) {
        try {
          await contexts.putIfRevision(message.context, accepted.revision, { ...accepted, receipt: visible });
        } catch {}
      }
      return visible;
    }

  }

  handler.flush = flush;
  handler.tabClosed = async (tabId: number) => {
    closedTabs.add(tabId);
    await contexts.removeTab(tabId);
  };
  return handler;
}

function envelopeMatchesBinding(
  envelope: CaptureEnvelope,
  binding: CredentialContext,
): boolean {
  if (envelope.capture.execution_id !== binding.executionId) {
    return false;
  }
  if (binding.source !== null && envelope.capture.source !== binding.source) return false;
  if (binding.surface !== null && envelope.capture.surface !== binding.surface) return false;
  return true;
}

function expired(binding: CredentialContext): boolean {
  return binding.expiresAt != null && (!Number.isFinite(Date.parse(binding.expiresAt)) || Date.parse(binding.expiresAt) <= Date.now());
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
  try {
    await lastApiStatus.setValue({
      ok: result.ok,
      duplicate: result.duplicate,
      error: result.error,
      status: result.status,
      at: new Date().toISOString(),
    });
  } catch {}
}

function isCaptureMessage(message: unknown): message is CaptureMessage {
  if (typeof message !== 'object' || message === null) return false;
  const type = (message as { type?: unknown }).type;
  return (
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
