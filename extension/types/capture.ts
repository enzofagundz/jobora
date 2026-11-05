/** Payload exato aceito por POST /api/job-captures (JobCaptureController::store). */
export interface CaptureAuthor {
  name: string | null;
  profile_url: string | null;
}

export type CaptureSource = 'linkedin' | 'indeed';

export interface CapturePayload {
  source: CaptureSource;
  external_id: string | null;
  url: string | null;
  search_url: string;
  title: string | null;
  description: string | null;
  company: string | null;
  location: string | null;
  author?: CaptureAuthor | null;
  captured_at: string;
}

export type CaptureErrorKind =
  | 'auth'
  | 'invalid'
  | 'http'
  | 'network'
  | 'timeout'
  | 'conflict'
  | 'too_large'
  | 'credential_changed'
  | 'not_found'
  | 'ambiguous'
  | 'storage';

export interface CaptureResult {
  ok: boolean;
  duplicate?: boolean;
  error?: CaptureErrorKind;
  status?: number;
}

export type CaptureSurface = 'linkedin_publications' | 'linkedin_jobs' | 'indeed_jobs';

export type CaptureCoverageReason =
  | 'cancelled'
  | 'navigation_interrupted'
  | 'timeout'
  | 'no_content';

export interface CaptureCoverage {
  status: 'complete' | 'partial';
  reason: CaptureCoverageReason | null;
  blocks_expected: number | null;
  blocks_captured: number;
}

export type CaptureBlockKind = 'post' | 'card' | 'detail';

export interface CaptureBlock {
  block_id: string;
  kind: CaptureBlockKind;
  platform_id: string;
  permalink: string;
  card_block_id: string | null;
  detail_block_id: string | null;
}

/** Envelope v1 compartilhado com fixtures/capture-envelope/v1. */
export interface CaptureEnvelope {
  format: 'jobora.capture-envelope';
  version: 1;
  capture: {
    idempotency_key: string;
    execution_id: string | null;
    source: CaptureSource;
    surface: CaptureSurface;
    initial_url: string;
    final_url: string;
    captured_at: string;
    coverage: CaptureCoverage;
    html: string;
  };
  blocks: CaptureBlock[];
}

export interface DiscoveryRunContext {
  executionId: string;
  source: CaptureSource;
  surface: CaptureSurface;
  initialUrl: string;
  expiresAt: string | null;
}

export type ResolveRunResult =
  | { ok: true; run: DiscoveryRunContext | null; context?: string }
  | { ok: false; error: CaptureErrorKind; status?: number; context?: string };

export interface EnvelopeResult extends CaptureResult {
  captureId?: number | string;
  executionId?: string | null;
  coverage?: CaptureCoverage['status'];
  replay?: boolean;
  /** Material durável ficou pendente para retry/recuperação. */
  queued?: boolean;
  /** Cabeçalho Retry-After convertido em milissegundos (429/5xx). */
  retryAfterMs?: number;
}

export type QueueBlockedReason =
  | 'auth'
  | 'invalid'
  | 'conflict'
  | 'too_large'
  | 'retry_exhausted';

export interface CaptureQueueItemStatus {
  idempotency_key: string;
  execution_id: string | null;
  state: 'pending' | 'blocked';
  attempts: number;
  next_attempt_at: number | null;
  bytes: number;
  blocked_reason?: QueueBlockedReason;
  last_error?: CaptureErrorKind;
  last_status?: number;
}

export interface CaptureQueueStatus {
  pending: number;
  blocked: number;
  bytes: number;
  max_bytes: number;
  envelope_max_bytes: number;
  next_attempt_at: number | null;
  items: CaptureQueueItemStatus[];
}

export interface CaptureQueueDrainResult {
  attempted: number;
  captured: number;
  pending: number;
  blocked: number;
  next_attempt_at: number | null;
  results: Array<{ idempotency_key: string; result: EnvelopeResult }>;
}

export type CaptureQueueResult =
  | { ok: true; status: CaptureQueueStatus }
  | { ok: true; drain: CaptureQueueDrainResult }
  | { ok: false; error: CaptureErrorKind; status?: number };

export type CaptureSubmitMessage = { type: 'capture:submit'; payload: CapturePayload };

/** Canal content script → background. O token nunca entra em nenhuma mensagem. */
export type CaptureMessage =
  | CaptureSubmitMessage
  | { type: 'capture:resolve-run'; url: string }
  | { type: 'capture:submit-envelope'; envelope: CaptureEnvelope; context: string }
  | { type: 'capture:queue-status' }
  | { type: 'capture:queue-drain'; force?: boolean }
  | { type: 'capture:queue-resume'; idempotency_key: string }
  | { type: 'capture:queue-discard'; idempotency_key: string };
