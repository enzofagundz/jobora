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
});
