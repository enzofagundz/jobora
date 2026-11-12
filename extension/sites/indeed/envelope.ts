import type {
  CaptureBlock,
  CaptureCoverageReason,
  CaptureEnvelope,
  CaptureJobCardBlock,
  CaptureJobDetailBlock,
} from '@/types/capture';
import {
  buildCaptureDocument,
  createCaptureBlockElement,
  serializeCaptureBlock,
} from '@/utils/capture-dom';

export interface IndeedEnvelopeInput {
  executionId: string | null;
  initialUrl: string;
  finalUrl: string;
  capturedAt?: string;
  coverageReason?: CaptureCoverageReason | null;
}

interface StoredJob {
  jk: string;
  permalink: string;
  card: CaptureJobCardBlock;
  cardElement: Element;
  detail: CaptureJobDetailBlock | null;
  detailElement: Element | null;
}

export function createIndeedJobsBuilder(idempotencyKey: string) {
  const jobs: StoredJob[] = [];
  const byJk = new Map<string, StoredJob>();

  function addCard(jk: string, element: Element): CaptureJobCardBlock {
    const existing = byJk.get(jk);
    if (existing) return existing.card;

    const card: CaptureJobCardBlock = {
      block_id: `card-${jobs.length + 1}`,
      kind: 'card',
      platform_id: jk,
      permalink: jobPermalink(jk),
      card_block_id: null,
      detail_block_id: null,
    };
    const stored: StoredJob = {
      jk,
      permalink: card.permalink,
      card,
      cardElement: createCaptureBlockElement(element, card, 'article'),
      detail: null,
      detailElement: null,
    };
    jobs.push(stored);
    byJk.set(jk, stored);
    return card;
  }

  function addDetail(jk: string, element: Element): CaptureJobDetailBlock | null {
    const stored = byJk.get(jk);
    if (!stored || stored.detail) return stored?.detail ?? null;

    const detail: CaptureJobDetailBlock = {
      block_id: `detail-${jobs.indexOf(stored) + 1}`,
      kind: 'detail',
      platform_id: jk,
      permalink: stored.permalink,
      card_block_id: stored.card.block_id,
      detail_block_id: null,
    };
    stored.card.detail_block_id = detail.block_id;
    stored.detail = detail;
    stored.detailElement = createCaptureBlockElement(element, detail, 'section');
    return detail;
  }

  function hasCard(jk: string): boolean {
    return byJk.has(jk);
  }

  function hasDetail(jk: string): boolean {
    return byJk.get(jk)?.detail != null;
  }

  function blocks(): CaptureBlock[] {
    return jobs.flatMap((job) => (job.detail ? [job.card, job.detail] : [job.card]));
  }

  function toEnvelope(input: IndeedEnvelopeInput): CaptureEnvelope {
    const list = blocks();
    const reason = input.coverageReason ?? null;
    const status = reason === null ? 'complete' : 'partial';
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
        source: 'indeed',
        surface: 'indeed_jobs',
        initial_url: input.initialUrl,
        final_url: input.finalUrl,
        captured_at: input.capturedAt ?? new Date().toISOString(),
        coverage: {
          status,
          reason,
          blocks_expected: reason === null ? list.length : jobs.length * 2,
          blocks_captured: list.length,
        },
        html: buildCaptureDocument('indeed_jobs', htmlBlocks),
      },
      blocks: list,
    };
  }

  return { addCard, addDetail, hasCard, hasDetail, blocks, toEnvelope };
}

export function jobPermalink(jk: string): string {
  const host = ['br.indeed.com', 'www.indeed.com', 'indeed.com'].includes(location.hostname)
    ? location.hostname
    : 'www.indeed.com';
  return `https://${host}/viewjob?jk=${encodeURIComponent(jk)}`;
}

export function jobIdentity(jk: string): string {
  return `external:indeed:${jk}`;
}
