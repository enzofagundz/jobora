import type {
  CaptureCoverageReason,
  CaptureEnvelope,
  CapturePostBlock,
  CaptureSurface,
} from '@/types/capture';
import { buildCaptureDocument, safeHref, snapshotCaptureBlock } from '@/utils/capture-dom';
import type { LinkedInRawPost } from './extract';

export interface CollectedPost {
  identity: string;
  block: CapturePostBlock;
}

interface StoredPost extends CollectedPost {
  html: string;
}

export interface EnvelopeInput {
  executionId: string | null;
  initialUrl: string;
  finalUrl: string;
  capturedAt?: string;
  coverageReason?: CaptureCoverageReason | null;
  blocksExpected?: number | null;
}

/**
 * Acumula posts observados (inclusive removidos do DOM virtualizado) e monta
 * um envelope v1 no formato publicado em fixtures/capture-envelope/v1.
 */
export function createLinkedInPublicationsBuilder(
  idempotencyKey: string,
  surface: CaptureSurface = 'linkedin_publications',
) {
  const posts: StoredPost[] = [];
  const byIdentity = new Map<string, StoredPost>();

  function add(raw: LinkedInRawPost, identity: string, postEl: Element): CollectedPost {
    const existing = byIdentity.get(identity);
    if (existing) return existing;

    const permalink = normalizePermalink(raw.url) ?? permalinkFromExternalId(raw.externalId);
    const platformId = raw.externalId?.trim() || null;

    const blockId = `post-${posts.length + 1}`;
    const block: CapturePostBlock = {
      block_id: blockId,
      kind: 'post',
      platform_id: platformId?.slice(0, 255) ?? null,
      permalink: permalink?.slice(0, 2000) ?? null,
      card_block_id: null,
      detail_block_id: null,
    };
    const stored: StoredPost = {
      identity,
      block,
      html: snapshotCaptureBlock(postEl, block),
    };
    posts.push(stored);
    byIdentity.set(identity, stored);
    return stored;
  }

  function has(identity: string): boolean {
    return byIdentity.has(identity);
  }

  function enrich(
    occurrenceIdentity: string,
    identity: string,
    raw: LinkedInRawPost,
    postEl: Element,
  ): boolean {
    const stored = byIdentity.get(occurrenceIdentity);
    if (!stored || byIdentity.has(identity)) return false;

    const permalink = normalizePermalink(raw.url) ?? permalinkFromExternalId(raw.externalId);
    stored.block.platform_id = raw.externalId?.trim().slice(0, 255) ?? null;
    stored.block.permalink = permalink?.slice(0, 2000) ?? null;
    stored.html = snapshotCaptureBlock(postEl, stored.block);
    byIdentity.set(identity, stored);

    return true;
  }

  function list(): CollectedPost[] {
    return posts.map(({ identity, block }) => ({ identity, block }));
  }

  function toEnvelope(input: EnvelopeInput): CaptureEnvelope {
    const reason = input.coverageReason ?? null;
    const status = reason === null ? 'complete' : 'partial';
    const blocks = posts.map((post) => post.block);

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
          blocks_expected:
            status === 'complete'
              ? blocks.length
              : (input.blocksExpected ?? null),
          blocks_captured: blocks.length,
        },
        html: buildCaptureDocument(
          surface,
          posts.map((post) => post.html),
        ),
      },
      blocks,
    };
  }

  return { add, enrich, has, list, size: () => posts.length, toEnvelope };
}

function normalizePermalink(raw: string | null): string | null {
  return raw ? safeHref(raw) : null;
}

export function permalinkFromExternalId(externalId: string | null): string | null {
  if (!externalId) return null;
  const urn = externalId.match(/^urn:li:activity:(\d+)$/);
  return urn
    ? `https://www.linkedin.com/feed/update/urn:li:activity:${urn[1]}`
    : null;
}
