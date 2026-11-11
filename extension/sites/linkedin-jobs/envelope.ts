import type {
  CaptureBlock,
  CaptureJobCardBlock,
  CaptureJobDetailBlock,
  CaptureCoverageReason,
  CaptureEnvelope,
  CaptureSurface,
} from '@/types/capture';
import {
  buildCaptureDocument,
  createCaptureBlockElement,
  serializeCaptureBlock,
} from '@/utils/capture-dom';

export interface EnvelopeInput {
  executionId: string | null;
  initialUrl: string;
  finalUrl: string;
  capturedAt?: string;
  coverageReason?: CaptureCoverageReason | null;
}

interface StoredJob {
  jobId: string;
  card: CaptureJobCardBlock;
  cardElement: Element;
  detail: CaptureJobDetailBlock | null;
  detailElement: Element | null;
}

export function createLinkedInJobsBuilder(
  idempotencyKey: string,
  surface: CaptureSurface = 'linkedin_jobs',
) {
  const jobs: StoredJob[] = [];
  const byJobId = new Map<string, StoredJob>();

  function addCard(jobId: string, cardElement: Element): CaptureJobCardBlock | null {
    const existing = byJobId.get(jobId);
    if (existing) return existing.card;

    const card: CaptureJobCardBlock = {
      block_id: `card-${jobs.length + 1}`,
      kind: 'card',
      platform_id: jobId,
      permalink: jobPermalink(jobId),
      card_block_id: null,
      detail_block_id: null,
    };
    const stored: StoredJob = {
      jobId,
      card,
      cardElement: createCaptureBlockElement(cardElement, card, 'article'),
      detail: null,
      detailElement: null,
    };
    jobs.push(stored);
    byJobId.set(jobId, stored);
    return card;
  }

  function addDetail(jobId: string, paneElement: Element): CaptureJobDetailBlock | null {
    const stored = byJobId.get(jobId);
    if (!stored) return null;
    if (stored.detail) return stored.detail;

    const detail: CaptureJobDetailBlock = {
      block_id: `detail-${jobs.indexOf(stored) + 1}`,
      kind: 'detail',
      platform_id: jobId,
      permalink: jobPermalink(jobId),
      card_block_id: stored.card.block_id,
      detail_block_id: null,
    };
    stored.card.detail_block_id = detail.block_id;
    stored.detail = detail;
    stored.detailElement = createCaptureBlockElement(paneElement, detail, 'section');
    return detail;
  }

  function hasCard(jobId: string): boolean {
    return byJobId.has(jobId);
  }

  function hasDetail(jobId: string): boolean {
    return byJobId.get(jobId)?.detail != null;
  }

  function blocks(): CaptureBlock[] {
    return jobs.flatMap((job) =>
      job.detail ? [job.card, job.detail] : [job.card],
    );
  }

  function toEnvelope(input: EnvelopeInput): CaptureEnvelope {
    const reason = input.coverageReason ?? null;
    const status = reason === null ? 'complete' : 'partial';
    const list = blocks();
    const htmlBlocks = jobs.flatMap((job) => {
      const card = serializeCaptureBlock(job.cardElement, job.card);
      if (!job.detail || !job.detailElement) return [card];
      return [card, serializeCaptureBlock(job.detailElement, job.detail)];
    });

    return {
      format: 'jobora.capture-envelope',
      version: 1,
      capture: {
        idempotency_key: idempotencyKey,
        execution_id: input.executionId,
        source: 'linkedin',
        surface,
        initial_url: input.initialUrl,
        final_url: input.finalUrl,
        captured_at: input.capturedAt ?? new Date().toISOString(),
        coverage: {
          status,
          reason,
          blocks_expected: status === 'complete' ? list.length : null,
          blocks_captured: list.length,
        },
        html: buildCaptureDocument(surface, htmlBlocks),
      },
      blocks: list,
    };
  }

  return { addCard, addDetail, hasCard, hasDetail, blocks, toEnvelope };
}

export function jobPermalink(jobId: string): string {
  return `https://www.linkedin.com/jobs/view/${jobId}`;
}

export function jobIdentity(jobId: string): string {
  return `external:linkedin:linkedin:job:${jobId}`;
}
