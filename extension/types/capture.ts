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

export type CaptureErrorKind = 'auth' | 'invalid' | 'http' | 'network' | 'timeout';

export interface CaptureResult {
  ok: boolean;
  duplicate?: boolean;
  error?: CaptureErrorKind;
  status?: number;
}

/** Único canal de messaging v1: content script → background. */
export type CaptureMessage = { type: 'capture:submit'; payload: CapturePayload };
