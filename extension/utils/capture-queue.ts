import type {
  CaptureEnvelope,
  CaptureErrorKind,
  CaptureQueueDrainResult,
  CaptureQueueItemStatus,
  CaptureQueueStatus,
  EnvelopeResult,
  QueueBlockedReason,
} from '@/types/capture';

/** Limite explícito; o envelope nunca é truncado. */
export const CAPTURE_ENVELOPE_MAX_BYTES = 12 * 1024 * 1024;
/** Fila total: três envelopes no limite, sem compressão silenciosa. */
export const CAPTURE_QUEUE_MAX_BYTES = 3 * CAPTURE_ENVELOPE_MAX_BYTES;
export const CAPTURE_QUEUE_MAX_ATTEMPTS = 5;
export const CAPTURE_QUEUE_RETRY_BASE_MS = 1_000;
export const CAPTURE_QUEUE_RETRY_MAX_MS = 60_000;

export interface CaptureQueueTarget {
  origin: string;
  credentialFingerprint: string;
  ownerUserId: number | null;
}

export interface CaptureQueueRecord {
  id: string;
  origin: string;
  credentialFingerprint: string;
  ownerUserId: number | null;
  envelope: CaptureEnvelope;
  bytes: number;
  payloadHash: string;
  attempts: number;
  nextAttemptAt: number;
  state: 'pending' | 'blocked';
  blockedReason?: QueueBlockedReason;
  lastError?: CaptureErrorKind;
  lastStatus?: number;
  createdAt: number;
}

export interface CaptureQueueStore {
  getAll(): Promise<CaptureQueueRecord[]>;
  put(record: CaptureQueueRecord): Promise<void>;
  delete(id: string): Promise<void>;
  /** delete(oldId)+put(record) na MESMA transação atômica. */
  replace(oldId: string, record: CaptureQueueRecord): Promise<void>;
}

export type SubmitEnvelope = (
  envelope: CaptureEnvelope,
  target: CaptureQueueTarget,
) => Promise<EnvelopeResult>;

export interface CaptureQueueRetargetResult {
  /** Registros reapontados para a credencial atual. */
  moved: number;
  /** Registros de outro dono, bloqueados para decisão explícita. */
  blocked: number;
}

export interface CaptureQueue {
  enqueue(
    envelope: CaptureEnvelope,
    target: CaptureQueueTarget,
  ): Promise<
    | { ok: true; record: CaptureQueueRecord; existing: boolean }
    | { ok: false; error: 'conflict' | 'too_large' }
  >;
  get(id: string): Promise<CaptureQueueRecord | null>;
  /**
   * Troca de token/destino: registros do mesmo dono passam a pertencer à
   * credencial atual; registros de outro dono ficam bloqueados e acionáveis.
   * Sem `resolveOwner` nada é movido, porque o dono é a única garantia de que
   * a captura não vai parar na conta errada.
   */
  retarget(
    target: CaptureQueueTarget,
    resolveOwner?: () => Promise<number | null>,
  ): Promise<CaptureQueueRetargetResult>;
  drain(
    target: CaptureQueueTarget,
    submit: SubmitEnvelope,
    force?: boolean,
  ): Promise<CaptureQueueDrainResult>;
  status(target?: CaptureQueueTarget): Promise<CaptureQueueStatus>;
  resumeAuth(
    idempotencyKey: string,
    target: CaptureQueueTarget,
  ): Promise<
    'resumed' | 'not_found' | 'credential_changed' | 'invalid' | 'ambiguous' | 'storage'
  >;
  discard(
    idempotencyKey: string,
    target: CaptureQueueTarget,
  ): Promise<'discarded' | 'not_found' | 'credential_changed' | 'ambiguous'>;
}

export function createCaptureQueue(store: CaptureQueueStore): CaptureQueue {
  async function enqueue(envelope: CaptureEnvelope, target: CaptureQueueTarget) {
    const json = JSON.stringify(envelope);
    const bytes = new TextEncoder().encode(json).byteLength;
    if (bytes > CAPTURE_ENVELOPE_MAX_BYTES) return { ok: false as const, error: 'too_large' as const };

    const payloadHash = await sha256Hex(json);
    const id = queueId(target, envelope.capture.idempotency_key);
    const records = await store.getAll();
    const existing = records.find((record) => record.id === id);

    if (existing) {
      return existing.payloadHash === payloadHash
        ? { ok: true as const, record: existing, existing: true }
        : { ok: false as const, error: 'conflict' as const };
    }

    const used = records.reduce((total, record) => total + record.bytes, 0);
    if (used + bytes > CAPTURE_QUEUE_MAX_BYTES) {
      return { ok: false as const, error: 'too_large' as const };
    }

    const record: CaptureQueueRecord = {
      id,
      origin: target.origin,
      credentialFingerprint: target.credentialFingerprint,
      ownerUserId: target.ownerUserId,
      envelope,
      bytes,
      payloadHash,
      attempts: 0,
      nextAttemptAt: Date.now(),
      state: 'pending',
      createdAt: Date.now(),
    };
    await store.put(record);
    return { ok: true as const, record, existing: false };
  }

  async function drain(
    target: CaptureQueueTarget,
    submit: SubmitEnvelope,
    force = false,
  ): Promise<CaptureQueueDrainResult> {
    const now = Date.now();
    const records = await store.getAll();
    const due = records
      .filter(
        (record) =>
          // Mesmo critério do status: quem aparece como pendente é entregável.
          viewerMatches(record, target) &&
          record.state === 'pending' &&
          (force || record.nextAttemptAt <= now),
      )
      .sort((a, b) => a.createdAt - b.createdAt);

    const results: CaptureQueueDrainResult['results'] = [];

    for (const record of due) {
      let result: EnvelopeResult;
      try {
        result = await submit(record.envelope, target);
      } catch {
        result = { ok: false, error: 'network' };
      }

      const outcome = { ...result, queued: !result.ok };
      results.push({ idempotency_key: record.envelope.capture.idempotency_key, result: outcome });

      if (result.ok) {
        await store.delete(record.id);
        continue;
      }

      if (result.error === 'auth') {
        await block(record, 'auth', result);
        continue;
      }

      if (result.error === 'invalid') {
        await block(record, 'invalid', result);
        continue;
      }

      if (result.error === 'conflict') {
        await block(record, 'conflict', result);
        continue;
      }

      if (result.error === 'too_large') {
        await block(record, 'too_large', result);
        continue;
      }

      if (isRetriable(result)) {
        record.attempts += 1;
        record.lastError = result.error;
        record.lastStatus = result.status;
        if (record.attempts >= CAPTURE_QUEUE_MAX_ATTEMPTS) {
          record.state = 'blocked';
          record.blockedReason = 'retry_exhausted';
        } else {
          const delay = result.retryAfterMs ?? backoff(record.attempts);
          // Conta a partir da resposta, não do início da tentativa.
          record.nextAttemptAt = Date.now() + Math.max(0, delay);
        }
        await store.put(record);
        continue;
      }

      // 4xx não previsto é permanente; não vira loop infinito.
      await block(record, 'invalid', result);
    }

    const after = await store.getAll();
    const targetRecords = after.filter((record) => viewerMatches(record, target));
    return toDrain(targetRecords, results);
  }

  async function retarget(
    target: CaptureQueueTarget,
    resolveOwner?: () => Promise<number | null>,
  ): Promise<CaptureQueueRetargetResult> {
    const stale = (await store.getAll()).filter(
      (record) =>
        record.origin === target.origin &&
        (record.credentialFingerprint !== target.credentialFingerprint ||
          (record.state === 'blocked' && record.lastError === 'credential_changed')),
    );
    if (stale.length === 0) return { moved: 0, blocked: 0 };

    const ownerUserId = target.ownerUserId ?? (await resolveOwner?.()) ?? null;
    if (ownerUserId === null) return { moved: 0, blocked: 0 };

    const current = target.ownerUserId === null ? { ...target, ownerUserId } : target;
    let moved = 0;
    let blocked = 0;

    for (const record of stale) {
      if (record.ownerUserId === null) continue;
      if (record.ownerUserId !== ownerUserId) {
        await block(record, 'auth', { ok: false, error: 'credential_changed' });
        blocked++;
        continue;
      }

      const previousId = record.id;
      record.credentialFingerprint = current.credentialFingerprint;
      record.ownerUserId = ownerUserId;
      record.id = queueId(current, record.envelope.capture.idempotency_key);

      if (record.state === 'blocked' && record.lastError === 'credential_changed') {
        record.state = 'pending';
        delete record.blockedReason;
        delete record.lastError;
        delete record.lastStatus;
        if (record.id === previousId) await store.put(record);
      }

      if (record.id === previousId) continue;

      const duplicate = (await store.getAll()).some(
        (other) => other.id === record.id && other.id !== previousId,
      );
      if (duplicate) {
        // Mesma captura já enfileirada sob a credencial atual.
        await store.delete(previousId);
        moved++;
        continue;
      }

      await store.replace(previousId, record);
      moved++;
    }

    return { moved, blocked };
  }

  async function get(id: string): Promise<CaptureQueueRecord | null> {
    return (await store.getAll()).find((record) => record.id === id) ?? null;
  }

  async function status(target?: CaptureQueueTarget): Promise<CaptureQueueStatus> {
    const records = await store.getAll();
    return toStatus(
      target ? records.filter((record) => viewerMatches(record, target)) : records,
    );
  }

  async function resumeAuth(
    idempotencyKey: string,
    target: CaptureQueueTarget,
  ): Promise<
    'resumed' | 'not_found' | 'credential_changed' | 'invalid' | 'ambiguous' | 'storage'
  > {
    const records = await store.getAll();
    const sameKey = records.filter(
      (record) => record.envelope.capture.idempotency_key === idempotencyKey,
    );
    if (sameKey.length === 0) return 'not_found';
    const eligible = sameKey.filter((record) => viewerMatches(record, target));
    if (eligible.length === 0) return 'credential_changed';
    if (eligible.length > 1) return 'ambiguous';
    const matching = eligible[0]!;
    if (matching.state !== 'blocked' || matching.blockedReason !== 'auth') {
      return 'invalid';
    }

    const previousId = matching.id;
    matching.id = queueId(target, idempotencyKey);
    matching.origin = target.origin;
    matching.credentialFingerprint = target.credentialFingerprint;
    matching.ownerUserId = target.ownerUserId ?? matching.ownerUserId;
    matching.state = 'pending';
    matching.attempts = 0;
    matching.nextAttemptAt = Date.now();
    delete matching.blockedReason;
    delete matching.lastError;
    delete matching.lastStatus;
    try {
      await store.replace(previousId, matching);
    } catch {
      return 'storage';
    }
    return 'resumed';
  }

  async function discard(
    idempotencyKey: string,
    target: CaptureQueueTarget,
  ): Promise<'discarded' | 'not_found' | 'credential_changed' | 'ambiguous'> {
    const records = await store.getAll();
    const sameKey = records.filter(
      (record) => record.envelope.capture.idempotency_key === idempotencyKey,
    );
    if (sameKey.length === 0) return 'not_found';
    const eligible = sameKey.filter((record) => viewerMatches(record, target));
    if (eligible.length === 0) return 'credential_changed';
    if (eligible.length > 1) return 'ambiguous';

    await store.delete(eligible[0]!.id);
    return 'discarded';
  }

  async function block(
    record: CaptureQueueRecord,
    reason: QueueBlockedReason,
    result: EnvelopeResult,
  ): Promise<void> {
    record.state = 'blocked';
    record.blockedReason = reason;
    record.lastError = result.error;
    record.lastStatus = result.status;
    await store.put(record);
  }

  return { enqueue, get, retarget, drain, status, resumeAuth, discard };
}

function viewerMatches(record: CaptureQueueRecord, target: CaptureQueueTarget): boolean {
  if (record.origin !== target.origin) return false;
  if (record.ownerUserId !== null && target.ownerUserId !== null) {
    return record.ownerUserId === target.ownerUserId;
  }
  return record.credentialFingerprint === target.credentialFingerprint;
}

export function queueId(target: CaptureQueueTarget, idempotencyKey: string): string {
  return `${target.origin}|${target.credentialFingerprint}|${idempotencyKey}`;
}

export function backoff(attempts: number): number {
  return Math.min(
    CAPTURE_QUEUE_RETRY_BASE_MS * 2 ** Math.max(0, attempts - 1),
    CAPTURE_QUEUE_RETRY_MAX_MS,
  );
}

export function isRetriable(result: EnvelopeResult): boolean {
  if (result.error === 'network' || result.error === 'timeout') return true;
  if (result.status === 429) return true;
  return typeof result.status === 'number' && result.status >= 500 && result.status <= 599;
}

export async function credentialFingerprint(token: string): Promise<string> {
  return sha256Hex(token.trim());
}

async function sha256Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function toStatus(records: CaptureQueueRecord[]): CaptureQueueStatus {
  const common = commonStatus(records);
  return {
    ...common,
    max_bytes: CAPTURE_QUEUE_MAX_BYTES,
    envelope_max_bytes: CAPTURE_ENVELOPE_MAX_BYTES,
  };
}

function toDrain(
  records: CaptureQueueRecord[],
  results: CaptureQueueDrainResult['results'],
): CaptureQueueDrainResult {
  const common = commonStatus(records);
  return {
    attempted: results.length,
    captured: results.filter(({ result }) => result.ok).length,
    pending: common.pending,
    blocked: common.blocked,
    next_attempt_at: common.next_attempt_at,
    results,
  };
}

function commonStatus(records: CaptureQueueRecord[]) {
  const items: CaptureQueueItemStatus[] = records.map((record) => ({
    idempotency_key: record.envelope.capture.idempotency_key,
    execution_id: record.envelope.capture.execution_id,
    state: record.state,
    attempts: record.attempts,
    next_attempt_at: record.state === 'pending' ? record.nextAttemptAt : null,
    bytes: record.bytes,
    blocked_reason: record.blockedReason,
    last_error: record.lastError,
    last_status: record.lastStatus,
  }));

  const pending = records.filter((record) => record.state === 'pending');
  const nextAttemptAt = pending.length > 0
    ? Math.min(...pending.map((record) => record.nextAttemptAt))
    : null;

  return {
    pending: pending.length,
    blocked: records.length - pending.length,
    bytes: records.reduce((total, record) => total + record.bytes, 0),
    next_attempt_at: nextAttemptAt,
    items,
  };
}
