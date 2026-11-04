import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const FIXTURE_DIR = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../../fixtures/capture-envelope/v1',
);
const FORMAT = 'jobora.capture-envelope';
const VERSION = 1;

type BlockKind = 'post' | 'card' | 'detail';

interface Block {
  block_id: string;
  kind: BlockKind;
  platform_id: string;
  permalink: string;
  card_block_id: string | null;
  detail_block_id: string | null;
}

interface Envelope {
  format: string;
  version: number;
  capture: {
    idempotency_key: string;
    execution_id: string | null;
    source: 'linkedin' | 'indeed';
    surface: 'linkedin_publications' | 'linkedin_jobs' | 'indeed_jobs';
    initial_url: string;
    final_url: string;
    captured_at: string;
    coverage: {
      status: 'complete' | 'partial';
      reason: string | null;
      blocks_expected: number | null;
      blocks_captured: number;
    };
    html: string;
  };
  blocks: Block[];
}

const FIXTURES = [
  {
    file: 'linkedin-publications.json',
    source: 'linkedin',
    surface: 'linkedin_publications',
    coverage: 'complete',
    blockCount: 2,
  },
  {
    file: 'linkedin-jobs.json',
    source: 'linkedin',
    surface: 'linkedin_jobs',
    coverage: 'complete',
    blockCount: 4,
  },
  {
    file: 'indeed-jobs.json',
    source: 'indeed',
    surface: 'indeed_jobs',
    coverage: 'complete',
    blockCount: 2,
  },
  {
    file: 'indeed-jobs-partial.json',
    source: 'indeed',
    surface: 'indeed_jobs',
    coverage: 'partial',
    blockCount: 3,
  },
] as const;

function readJson(file: string): Record<string, any> {
  return JSON.parse(readFileSync(resolve(FIXTURE_DIR, file), 'utf8')) as Record<string, any>;
}

function attributes(tag: string): Record<string, string> {
  const result: Record<string, string> = {};
  for (const match of tag.matchAll(/([a-zA-Z_:][-a-zA-Z0-9_:.]*)="([^"]*)"/g)) {
    const [, name, value] = match;
    if (name !== undefined && value !== undefined) result[name] = value;
  }
  return result;
}

function blockNodes(html: string): Map<string, Record<string, string>> {
  const nodes = new Map<string, Record<string, string>>();
  for (const match of html.matchAll(/<[a-zA-Z][^>]*\sdata-block-id="([^"]+)"[^>]*>/g)) {
    const attrs = attributes(match[0]);
    const id = attrs['data-block-id'] as string;
    expect(nodes.has(id)).toBe(false);
    nodes.set(id, attrs);
  }
  return nodes;
}

function assertSafeHtml(html: string): void {
  for (const tag of ['script', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'button', 'style']) {
    expect(html).not.toMatch(new RegExp(`<${tag}\\b`, 'i'));
  }

  expect(html).not.toMatch(/\son[a-z]+\s*=/i);
  expect(html).not.toMatch(/\ssrcdoc\s*=/i);
  expect(html).not.toMatch(/\sstyle\s*=/i);
  expect(html).not.toMatch(/(?:javascript|vbscript|data:text\/html):/i);
}

function assertBlocks(envelope: Envelope): void {
  expect(envelope.blocks.length).toBeGreaterThan(0);

  const byId = new Map<string, Block>();
  for (const block of envelope.blocks) {
    expect(Object.keys(block).sort()).toEqual(
      ['block_id', 'card_block_id', 'detail_block_id', 'kind', 'permalink', 'platform_id'].sort(),
    );
    expect(block.block_id).toMatch(/^[a-z0-9][a-z0-9-]{0,63}$/);
    expect(byId.has(block.block_id)).toBe(false);
    expect(block.platform_id.length).toBeGreaterThan(0);
    expect(block.permalink.startsWith('https://')).toBe(true);
    byId.set(block.block_id, block);
  }

  for (const block of envelope.blocks) {
    if (block.kind === 'post') {
      expect(block.card_block_id).toBeNull();
      expect(block.detail_block_id).toBeNull();
    }

    if (block.kind === 'detail') {
      expect(block.detail_block_id).toBeNull();
      const card = byId.get(block.card_block_id as string);
      expect(card).toBeDefined();
      if (!card) throw new Error(`Card ausente para ${block.block_id}`);
      expect(card.kind).toBe('card');
      expect(card.detail_block_id).toBe(block.block_id);
      expect(card.platform_id).toBe(block.platform_id);
      expect(card.permalink).toBe(block.permalink);
    }

    if (block.kind === 'card' && block.detail_block_id !== null) {
      const detail = byId.get(block.detail_block_id);
      expect(detail).toBeDefined();
      if (!detail) throw new Error(`Detalhe ausente para ${block.block_id}`);
      expect(detail.kind).toBe('detail');
      expect(detail.card_block_id).toBe(block.block_id);
      expect(detail.platform_id).toBe(block.platform_id);
      expect(detail.permalink).toBe(block.permalink);
    }
  }

  const html = envelope.capture.html;
  const root = html.match(/<[^>]*\sdata-capture-surface="([^"]+)"[^>]*>/i);
  expect(root?.[1]).toBe(envelope.capture.surface);
  const rootVersion = html.match(/<[^>]*\sdata-capture-version="([^"]+)"[^>]*>/i);
  expect(rootVersion?.[1]).toBe(String(VERSION));

  const nodes = blockNodes(html);
  expect([...nodes.keys()].sort()).toEqual([...byId.keys()].sort());

  for (const [id, block] of byId) {
    const node = nodes.get(id);
    expect(node).toBeDefined();
    if (!node) throw new Error(`Bloco ausente no HTML: ${id}`);
    expect(node['data-kind']).toBe(block.kind);
    expect(node['data-platform-id']).toBe(block.platform_id);
    expect(node['data-permalink']).toBe(block.permalink);

    if (block.kind === 'post') {
      expect(node['data-card-block-id']).toBeUndefined();
      expect(node['data-detail-block-id']).toBeUndefined();
    }

    if (block.kind === 'card' && block.detail_block_id !== null) {
      expect(node['data-detail-block-id']).toBe(block.detail_block_id);
    }

    if (block.kind === 'detail') {
      expect(node['data-card-block-id']).toBe(block.card_block_id);
    }
  }
}

describe('envelope de captura v1', () => {
  it('publica o schema fechado e versionado', () => {
    const schema = readJson('capture-envelope.v1.schema.json');

    expect(schema.$schema).toBe('https://json-schema.org/draft/2020-12/schema');
    expect(schema.$id).toContain('/v1');
    expect(schema.properties.format.const).toBe(FORMAT);
    expect(schema.properties.version.const).toBe(VERSION);
    expect(schema.additionalProperties).toBe(false);
    expect(schema.properties.capture.additionalProperties).toBe(false);
    expect(schema.$defs.block.additionalProperties).toBe(false);
    expect(schema.properties.capture.properties.surface.enum).toEqual([
      'linkedin_publications',
      'linkedin_jobs',
      'indeed_jobs',
    ]);
    expect(schema.$defs.block.properties.kind.enum).toEqual(['post', 'card', 'detail']);
    expect(schema.properties.capture.properties.coverage.properties.reason.enum).toEqual(
      expect.arrayContaining(['cancelled', 'navigation_interrupted', 'timeout', 'no_content']),
    );
  });

  it('mantém manifesto com as três superfícies e cobertura parcial', () => {
    const manifest = readJson('fixtures.json');

    expect(manifest.version).toBe(VERSION);

    const files = manifest.fixtures.map((fixture: { file: string }) => fixture.file);
    for (const file of [
      'linkedin-publications.json',
      'linkedin-jobs.json',
      'indeed-jobs.json',
      'indeed-jobs-partial.json',
    ]) {
      expect(files).toContain(file);
    }

    const coverages = manifest.fixtures.map((fixture: { coverage: string }) => fixture.coverage);
    expect(coverages).toContain('complete');
    expect(coverages).toContain('partial');
  });

  describe.each(FIXTURES)('$file', ({ file, source, surface, coverage, blockCount }) => {
    it('representa a captura consolidada sem HTML executável', () => {
      const envelope = readJson(file) as unknown as Envelope;

      expect(envelope.format).toBe(FORMAT);
      expect(envelope.version).toBe(VERSION);
      expect(Object.keys(envelope.capture).sort()).toEqual(
        [
          'captured_at',
          'coverage',
          'execution_id',
          'final_url',
          'html',
          'idempotency_key',
          'initial_url',
          'source',
          'surface',
        ].sort(),
      );
      expect(envelope.capture.source).toBe(source);
      expect(envelope.capture.surface).toBe(surface);
      expect(envelope.capture.idempotency_key.length).toBeGreaterThan(0);
      expect(envelope.capture.execution_id).toBeTruthy();
      expect(envelope.capture.initial_url.startsWith('https://')).toBe(true);
      expect(envelope.capture.final_url.startsWith('https://')).toBe(true);
      expect(Number.isNaN(Date.parse(envelope.capture.captured_at))).toBe(false);

      expect(envelope.capture.coverage.status).toBe(coverage);
      expect(envelope.capture.coverage.blocks_captured).toBe(envelope.blocks.length);
      expect(envelope.blocks).toHaveLength(blockCount);

      if (coverage === 'complete') {
        expect(envelope.capture.coverage.reason).toBeNull();
      } else {
        expect(envelope.capture.coverage.reason).toBeTruthy();
      }

      const cardsWithoutDetail = envelope.blocks.filter(
        (block) => block.kind === 'card' && block.detail_block_id === null,
      );
      if (coverage === 'partial') {
        expect(cardsWithoutDetail.length).toBeGreaterThan(0);
      } else {
        expect(cardsWithoutDetail).toHaveLength(0);
      }

      if (envelope.capture.coverage.blocks_expected !== null) {
        expect(envelope.capture.coverage.blocks_expected).toBeGreaterThanOrEqual(envelope.blocks.length);
      }

      expect(envelope.capture.html).toContain('Exemplo');
      expect(envelope.capture.html).not.toMatch(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);

      assertSafeHtml(envelope.capture.html);
      assertBlocks(envelope);
    });
  });
});
