// @vitest-environment happy-dom
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { CaptureEnvelope, CaptureSource, CaptureSurface } from '@/types/capture';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { createLinkedInJobsController } from '@/sites/linkedin-jobs/controller';
import { createIndeedController } from '@/sites/indeed/controller';
import { createCaptureMessageHandler } from '@/api/capture-message-handler';
import { createCaptureQueue } from '@/utils/capture-queue';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import { createMemoryCredentialContextStore } from './helpers/memory-credential-context';

interface FlowFixture {
  surface: CaptureSurface;
  source: CaptureSource;
  title: string;
  initialUrl: string;
  finalUrl: string;
  html: string;
  detailHtml: string;
  envelope: CaptureEnvelope;
}

const fixtures: FlowFixture[] = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/fixtures/v2-homologation.json'), 'utf8'));
const executionId = '00000000-0000-4000-8000-000000003800';
let cleanup: (() => Promise<void>) | undefined;

beforeEach(async () => {
  fakeBrowser.reset();
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-11-07T12:00:00Z'));
  window.happyDOM.settings.navigation.disableMainFrameNavigation = true;
  window.happyDOM.settings.navigation.disableFallbackToSetURL = true;
  vi.spyOn(window, 'scrollBy').mockImplementation(() => {});
  vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {});
  await captureEnabled.setValue(true);
  await apiBaseUrl.setValue('https://vagas.test');
  await apiToken.setValue('controlled-extension-credential');
});

afterEach(async () => {
  await cleanup?.();
  cleanup = undefined;
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

function backendResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('V2 controlled capture homologation', () => {
  it.each(fixtures)('$surface serializes the shared PHP fixture and waits only for durable capture ACK', async (fixture) => {
    window.happyDOM.setURL(fixture.initialUrl);
    document.body.innerHTML = fixture.html;
    const openPane = (event: Event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target?.closest('[data-occludable-job-id], [data-job-id], [data-jk]')) return;
      if (fixture.detailHtml) document.body.insertAdjacentHTML('beforeend', fixture.detailHtml);
      window.happyDOM.setURL(fixture.finalUrl);
    };
    document.addEventListener('click', openPane, true);
    const store = createMemoryQueueStore();
    const handler = createCaptureMessageHandler(createCaptureQueue(store), createMemoryCredentialContextStore());
    let runResolved = false;
    const messages: Promise<unknown>[] = [];
    vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation((message: unknown) => {
      const result = handler(message).then((reply) => {
        if ((message as { type?: string }).type === 'capture:resolve-run') runResolved = true;
        return reply;
      });
      messages.push(result);
      return result as unknown as ReturnType<typeof browser.runtime.sendMessage>;
    });
    const uploads: CaptureEnvelope[] = [];
    const ack = Promise.withResolvers<Response>();
    const fetchSpy = vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
      const path = String(url);
      if (path.includes('/content-scripts/')) return new Response('');
      if (path.endsWith('/extension/auth/validate')) return backendResponse({ valid: true, owner_user_id: 1 });
      if (path.endsWith('/discovery-runs/resolve')) return backendResponse({ execution_id: executionId, source: fixture.source, surface: fixture.surface, initial_url: fixture.initialUrl });
      if (path.endsWith('/page-captures')) {
        uploads.push(JSON.parse(String(init?.body)) as CaptureEnvelope);
        return ack.promise;
      }
      throw new Error(`Unexpected transport ${path}`);
    });
    vi.stubGlobal('fetch', fetchSpy);
    const controller = fixture.surface === 'linkedin_publications' ? createLinkedInController()
      : fixture.surface === 'linkedin_jobs' ? createLinkedInJobsController() : createIndeedController();
    const active = controller.activate(new ContentScriptContext(`homologation-${fixture.surface}`));
    cleanup = async () => {
      controller.deactivate();
      document.removeEventListener('click', openPane, true);
      ack.resolve(backendResponse({ id: 38, execution_id: executionId, coverage: 'complete', replay: false }, 201));
      await active;
      await Promise.all(messages);
      await vi.advanceTimersByTimeAsync(0);
    };
    await vi.waitFor(() => expect(runResolved).toBe(true));
    for (let index = 0; index < 300 && uploads.length === 0; index++) await vi.advanceTimersByTimeAsync(100);
    await vi.waitFor(() => expect(uploads).toHaveLength(1));
    expect(controller.getCaptureState().captured).toBe(false);
    expect(await store.getAll()).toHaveLength(1);
    const sent = uploads[0]!;
    expect(sent.capture.execution_id).toBe(executionId);
    expect({ ...sent, capture: { ...sent.capture, idempotency_key: fixture.envelope.capture.idempotency_key,
      captured_at: fixture.envelope.capture.captured_at } }).toEqual(fixture.envelope);
    expect(JSON.stringify(sent)).not.toContain('controlled-extension-credential');
    ack.resolve(backendResponse({ id: 38, execution_id: executionId, coverage: 'complete', replay: false, processing_status: 'queued' }, 201));
    await vi.waitFor(() => expect(controller.getCaptureState().captured).toBe(true));
    await active;
    expect(controller.getCaptureState().captured).toBe(true);
    expect(await store.getAll()).toHaveLength(0);
    expect(fetchSpy.mock.calls.filter(([url]) => String(url).includes('/page-captures'))).toHaveLength(1);
    expect(fetchSpy.mock.calls.map(([url]) => String(url)).some((url) => /clef|triage|ai-operation/.test(url))).toBe(false);
  });

  it('replays the same serialized capture after lost ACK and background recreation without a second receipt', async () => {
    const fixture = fixtures[0]!;
    const store = createMemoryQueueStore();
    const uploads: CaptureEnvelope[] = [];
    const receipts = new Set<string>();
    vi.stubGlobal('fetch', vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
      const path = String(url);
      if (path.endsWith('/extension/auth/validate')) return backendResponse({ valid: true, owner_user_id: 1 });
      if (path.endsWith('/discovery-runs/resolve')) return backendResponse({ execution_id: executionId, source: fixture.source, surface: fixture.surface, initial_url: fixture.initialUrl });
      if (!path.endsWith('/page-captures')) throw new Error(`Unexpected transport ${path}`);
      const envelope = JSON.parse(String(init?.body)) as CaptureEnvelope;
      uploads.push(envelope);
      receipts.add(envelope.capture.idempotency_key);
      if (uploads.length === 1) throw new TypeError('Controlled ACK loss after durable receipt');
      return backendResponse({ id: 38, execution_id: executionId, coverage: 'complete', replay: true }, 200);
    }));
    const first = createCaptureMessageHandler(createCaptureQueue(store), createMemoryCredentialContextStore());
    const resolved = await first({ type: 'capture:resolve-run', url: fixture.initialUrl });
    if (!resolved || !('context' in resolved) || typeof resolved.context !== 'string') throw new Error('Missing credential context');
    expect(await first({ type: 'capture:submit-envelope', envelope: fixture.envelope, context: resolved.context }))
      .toMatchObject({ ok: false, error: 'network', queued: true });
    expect(await store.getAll()).toHaveLength(1);
    const restarted = createCaptureMessageHandler(createCaptureQueue(store), createMemoryCredentialContextStore());
    await restarted.flush();
    expect(uploads).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(2000);
    await restarted.flush();
    expect(uploads).toHaveLength(2);
    expect(uploads[0]).toEqual(fixture.envelope);
    expect(uploads[1]).toEqual(uploads[0]);
    expect(receipts.size).toBe(1);
    expect(await store.getAll()).toHaveLength(0);
  });
});
