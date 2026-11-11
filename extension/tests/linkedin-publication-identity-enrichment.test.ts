// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureMessage } from '@/types/capture';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { OBSERVER_DEBOUNCE_MS } from '@/sites/linkedin/extract';
import { createHydrationIdentityCollector } from '@/sites/linkedin/hydration-identity';
import { createCaptureQueue } from '@/utils/capture-queue';
import { hydrationScript, mountHydrationScript } from './helpers/hydration-fixture';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import { createCaptureSession } from '@/utils/capture-session';
import { createLinkedInPublicationsBuilder } from '@/sites/linkedin/envelope';

vi.mock('@/utils/capture-session', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/utils/capture-session')>();
  return { ...original, createCaptureSession: vi.fn(original.createCaptureSession) };
});

const SEARCH_URL = 'https://www.linkedin.com/search/results/content/?keywords=synthetic';
const ACTIVITY_ID = '9000000000000000991';
const EXECUTION_ID = 'exec-linkedin-identity-enrichment';
const TARGET = {
  origin: 'https://jobora.test',
  credentialFingerprint: 'synthetic-credential',
  ownerUserId: 17,
};

describe('LinkedIn publicação anônima hidratada', () => {
  let store: ReturnType<typeof createMemoryQueueStore>;

  beforeEach(() => {
    fakeBrowser.reset();
    vi.useFakeTimers();
    store = createMemoryQueueStore();
    window.happyDOM.setURL(SEARCH_URL);
    document.documentElement.innerHTML = `
      <head></head><body>
        <div class="feed">
          <article role="listitem" componentkey="ck-A">
            <div data-testid="expandable-text-box"><p>Synthetic job post.</p></div>
          </article>
        </div>
      </body>`;
    vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
    vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it.each([null, 'https://www.linkedin.com/feed/update/urn:li:share:9000000000000000991'])('updates same observed card with initial URL %s before durable envelope enqueue', async (initialUrl) => {
    if (initialUrl) {
      const link = document.createElement('a');
      link.href = initialUrl;
      document.querySelector('[role="listitem"]')!.appendChild(link);
    }
    const queue = createCaptureQueue(store);
    const identities = createHydrationIdentityCollector();
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return {
          ok: true,
          run: {
            executionId: EXECUTION_ID,
            source: 'linkedin',
            surface: 'linkedin_publications',
            initialUrl: SEARCH_URL,
            expiresAt: null,
          },
          context: 'ctx-hydration',
        };
      }
      if (message.type === 'capture:submit-envelope') {
        await queue.enqueue(message.envelope, TARGET);
        return { ok: false, error: 'network', queued: true };
      }
      return { ok: true, duplicate: false };
    });

    const controller = createLinkedInController({
      resolveHydrationIdentity: (componentKey) => identities.resolve(componentKey),
    });
    const activation = controller.activate(
      new ContentScriptContext('linkedin-publication-hydration-regression'),
    );
    await vi.advanceTimersByTimeAsync(200);
    await activation;

    const card = document.querySelector('[role="listitem"]') as HTMLElement;
    const feed = document.querySelector('.feed') as HTMLElement;
    mountHydrationScript(hydrationScript([{ componentKey: 'ck-A', activityId: ACTIVITY_ID }]));
    await vi.advanceTimersByTimeAsync(0);
    expect(identities.resolve('ck-A')).toEqual({
      activityId: ACTIVITY_ID,
      permalink: `https://www.linkedin.com/feed/update/urn:li:activity:${ACTIVITY_ID}`,
    });

    feed.removeChild(card);
    feed.appendChild(card);
    await vi.advanceTimersByTimeAsync(OBSERVER_DEBOUNCE_MS + 100);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await vi.advanceTimersByTimeAsync(2000);

    const records = [...store.records.values()];
    expect(records).toHaveLength(1);
    const envelope: CaptureEnvelope = records[0]!.envelope;
    expect(envelope.blocks).toHaveLength(1);
    expect(envelope.blocks[0]).toMatchObject({
      block_id: 'post-1',
      platform_id: `urn:li:activity:${ACTIVITY_ID}`,
      permalink: initialUrl ?? `https://www.linkedin.com/feed/update/urn:li:activity:${ACTIVITY_ID}`,
    });
    expect(envelope.capture.html.match(/data-block-id=/g)).toHaveLength(1);
    expect(envelope.capture.html).toContain(`data-platform-id="urn:li:activity:${ACTIVITY_ID}"`);
    expect(envelope.capture.html).toContain(
      `data-permalink="${initialUrl ?? `https://www.linkedin.com/feed/update/urn:li:activity:${ACTIVITY_ID}`}"`,
    );
    expect(envelope.capture.html).toContain('Synthetic job post.');
    expect(controller.getCaptureState().blocks).toBe(1);
    const resumedQueue = createCaptureQueue(store);
    await expect(resumedQueue.get(records[0]!.id)).resolves.toMatchObject({ envelope });
    await expect(resumedQueue.enqueue(envelope, TARGET)).resolves.toMatchObject({
      ok: true,
      existing: true,
    });
    expect(store.records.size).toBe(1);

    controller.deactivate();
    identities.dispose();
  });

  it.each([null, 'https://www.linkedin.com/feed/update/urn:li:share:9000000000000000991'])('settles promoted identity once across SPA re-entry with initial URL %s', async (initialUrl) => {
    const card = document.querySelector('[role="listitem"]')!;
    if (initialUrl) {
      const link = document.createElement('a');
      link.href = initialUrl;
      card.appendChild(link);
    }
    const envelopes: CaptureEnvelope[] = [];
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return { ok: true, run: null, context: 'ctx-hydration' };
      }
      if (message.type === 'capture:submit-envelope') envelopes.push(message.envelope);
      return { ok: true, duplicate: false };
    });
    let hydrated = false;
    const controller = createLinkedInController({
      resolveHydrationIdentity: () => hydrated ? {
        activityId: ACTIVITY_ID,
        permalink: `https://www.linkedin.com/feed/update/urn:li:activity:${ACTIVITY_ID}`,
      } : null,
    });
    const activation = controller.activate(new ContentScriptContext('linkedin-promotion-reentry'));
    await vi.advanceTimersByTimeAsync(200);
    await activation;
    hydrated = true;
    card.remove();
    document.querySelector('.feed')!.appendChild(card);
    await vi.advanceTimersByTimeAsync(OBSERVER_DEBOUNCE_MS + 100);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await vi.advanceTimersByTimeAsync(2000);
    expect(envelopes.flatMap((envelope) => envelope.blocks)).toHaveLength(1);
    controller.deactivate();
    const reactivation = controller.activate(new ContentScriptContext('linkedin-promotion-reentry-2'));
    await vi.advanceTimersByTimeAsync(200);
    await reactivation;
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await vi.advanceTimersByTimeAsync(2000);
    expect(envelopes.flatMap((envelope) => envelope.blocks)).toHaveLength(1);
    const session = vi.mocked(createCaptureSession).mock.results.at(-1)!.value;
    expect(session.counters).toMatchObject({ found: 1, processed: 1, sent: 1, duplicates: 0 });
    controller.deactivate();
  });

  it.each(['settled', 'pending'])('merges late hydration into an already %s identity across activations', async (firstResult) => {
    const envelopes: CaptureEnvelope[] = [];
    let releaseFirst!: () => void;
    const firstAck = new Promise<void>((resolve) => { releaseFirst = resolve; });
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return { ok: true, run: null, context: 'ctx-hydration' };
      }
      if (message.type === 'capture:submit-envelope') {
        envelopes.push(message.envelope);
        if (envelopes.length === 1) await firstAck;
      }
      return { ok: true, duplicate: false };
    });
    let hydrated = true;
    const controller = createLinkedInController({
      resolveHydrationIdentity: () => hydrated ? {
        activityId: ACTIVITY_ID,
        permalink: `https://www.linkedin.com/feed/update/urn:li:activity:${ACTIVITY_ID}`,
      } : null,
    });
    const activation = controller.activate(new ContentScriptContext('linkedin-collision-first'));
    await vi.advanceTimersByTimeAsync(200);
    await activation;
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await vi.advanceTimersByTimeAsync(2000);
    if (firstResult === 'settled') {
      releaseFirst();
      await vi.advanceTimersByTimeAsync(0);
    }
    controller.deactivate();
    hydrated = false;
    const card = document.querySelector('[role="listitem"]')!;
    const link = document.createElement('a');
    link.href = 'https://www.linkedin.com/feed/update/urn:li:share:9000000000000000991';
    card.appendChild(link);
    const reactivation = controller.activate(new ContentScriptContext('linkedin-collision-second'));
    await vi.advanceTimersByTimeAsync(200);
    await reactivation;
    expect(controller.getCaptureState().blocks).toBe(1);
    hydrated = true;
    card.remove();
    document.querySelector('.feed')!.appendChild(card);
    await vi.advanceTimersByTimeAsync(OBSERVER_DEBOUNCE_MS + 100);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await vi.advanceTimersByTimeAsync(2000);
    releaseFirst();
    await vi.advanceTimersByTimeAsync(0);
    expect(envelopes.flatMap((envelope) => envelope.blocks)).toHaveLength(1);
    expect(controller.getCaptureState().blocks).toBe(0);
    const session = vi.mocked(createCaptureSession).mock.results.at(-1)!.value;
    expect(session.counters).toMatchObject({ found: 1, processed: 1, sent: 1, duplicates: 0 });
    expect(session.claimedIds.size).toBe(0);
    controller.deactivate();
  });

  it('preserves promoted identity when the same card returns to its URL alias', async () => {
    const envelopes: CaptureEnvelope[] = [];
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation(async (raw: unknown) => {
      const message = raw as CaptureMessage;
      if (message.type === 'capture:resolve-run') {
        return { ok: true, run: null, context: 'ctx-hydration' };
      }
      if (message.type === 'capture:submit-envelope') envelopes.push(message.envelope);
      return { ok: true, duplicate: false };
    });
    const card = document.querySelector('[role="listitem"]')!;
    const link = document.createElement('a');
    link.href = 'https://www.linkedin.com/feed/update/urn:li:share:9000000000000000991';
    card.appendChild(link);
    const controller = createLinkedInController();
    const activation = controller.activate(new ContentScriptContext('linkedin-alias-regression'));
    await vi.advanceTimersByTimeAsync(200);
    await activation;
    card.setAttribute('data-urn', `urn:li:activity:${ACTIVITY_ID}`);
    card.remove();
    document.querySelector('.feed')!.appendChild(card);
    await vi.advanceTimersByTimeAsync(OBSERVER_DEBOUNCE_MS + 100);
    card.removeAttribute('data-urn');
    card.remove();
    document.querySelector('.feed')!.appendChild(card);
    await vi.advanceTimersByTimeAsync(OBSERVER_DEBOUNCE_MS + 100);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await vi.advanceTimersByTimeAsync(2000);
    expect(envelopes.flatMap((envelope) => envelope.blocks)).toHaveLength(1);
    expect(envelopes[0]!.blocks[0]).toMatchObject({ platform_id: `urn:li:activity:${ACTIVITY_ID}` });
    expect(controller.getCaptureState().blocks).toBe(1);
    const session = vi.mocked(createCaptureSession).mock.results.at(-1)!.value;
    expect(session.counters).toMatchObject({ found: 1, processed: 1, sent: 1, duplicates: 0 });
    expect(session.claimedIds.size).toBe(0);
    controller.deactivate();
  });

  it('transfers claims without counting promotion or accepting repeated results', () => {
    const session = createCaptureSession();
    expect(session.claim('occurrence')).toBe(true);
    session.transferClaim('occurrence', 'url');
    session.transferClaim('url', 'external');
    session.transferClaim('url', 'external');
    expect(session.claimedIds).toEqual(new Set(['external']));
    expect(session.claim('occurrence')).toBe(false);
    expect(session.claim('url')).toBe(false);
    expect(session.claim('external')).toBe(false);
    session.applyResult('occurrence', { ok: true, duplicate: false });
    session.applyResult('external', { ok: true, duplicate: false });
    session.applyResult('external', { ok: true, duplicate: false });
    expect(session.counters).toMatchObject({ found: 1, processed: 1, sent: 1, duplicates: 0 });
    expect(session.claim('external')).toBe(false);
    expect(session.claim('new-search-post')).toBe(true);
  });

  it('removes provisional block aliases without reusing remaining block IDs', () => {
    const builder = createLinkedInPublicationsBuilder('identity-collision');
    const raw = { url: null, externalId: null, description: 'Synthetic post.', author: null };
    const card = document.createElement('article');
    builder.add(raw, 'first', card);
    builder.add(raw, 'provisional', card);
    builder.add(raw, 'third', card);
    expect(builder.enrich('provisional', 'promoted', raw, card)).toBe(true);
    builder.remove('promoted');
    expect(builder.has('provisional')).toBe(false);
    expect(builder.has('promoted')).toBe(false);
    builder.add(raw, 'fourth', card);
    expect(builder.list().map((post) => post.block.block_id)).toEqual(['post-1', 'post-3', 'post-4']);
  });
});
