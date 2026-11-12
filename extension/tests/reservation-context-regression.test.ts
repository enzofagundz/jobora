// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { ContentScriptContext } from 'wxt/utils/content-script-context';
import { createCaptureMessageHandler } from '@/api/capture-message-handler';
import { createLinkedInJobsController } from '@/sites/linkedin-jobs/controller';
import type { CaptureEnvelope } from '@/types/capture';
import { createCaptureQueue } from '@/utils/capture-queue';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';
import { createMemoryQueueStore } from './helpers/memory-queue-store';
import { createMemoryCredentialContextStore } from './helpers/memory-credential-context';
import { createSessionCredentialContextStore, type CredentialContextStore } from '@/utils/credential-context';
import { lastApiStatus } from '@/utils/session-storage';

const INITIAL_URL = 'https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2';
const EQUIVALENT_URL = 'https://www.linkedin.com/jobs/search-results/?currentJobId=900001&keywords=Laravel&f_WT=2&skipRedirect=true&infoNotice=fixture';
const CHANGED_URL = 'https://www.linkedin.com/jobs/search-results/?currentJobId=900001&keywords=Laravel&f_TPR=r86400';
const UNKNOWN_FILTER_URL = 'https://www.linkedin.com/jobs/search-results/?keywords=Laravel&f_WT=2&unknownFilter=restricted';
const EXECUTION_ID = '00000000-0000-4000-8000-000000000007';

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

beforeEach(async () => {
  fakeBrowser.reset();
  vi.useFakeTimers();
  window.happyDOM.settings.navigation.disableMainFrameNavigation = true;
  window.happyDOM.settings.navigation.disableFallbackToSetURL = true;
  window.happyDOM.setURL(INITIAL_URL);
  document.body.innerHTML = '<main><p>Resultados sintéticos ainda sem cards.</p></main>';
  await captureEnabled.setValue(true);
  await apiBaseUrl.setValue('https://vagas.test');
  await apiToken.setValue('synthetic-reservation-credential');
});

afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

function composedCapture() {
  const store = createMemoryQueueStore();
  const handler = createCaptureMessageHandler(createCaptureQueue(store), createMemoryCredentialContextStore());
  const messages: Promise<unknown>[] = [];
  const resolutions: { url: string; status: number }[] = [];
  const uploads: { envelope: CaptureEnvelope; status: number }[] = [];
  vi.spyOn(fakeBrowser.runtime, 'sendMessage').mockImplementation((raw: unknown) => {
    const response = handler(raw, { tab: { id: 7 }, frameId: 0 });
    messages.push(response);
    return response as unknown as ReturnType<typeof browser.runtime.sendMessage>;
  });
  vi.stubGlobal('fetch', vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
    const path = String(url);
    if (path.includes('/content-scripts/')) return new Response('');
    if (path.endsWith('/extension/auth/validate')) return json({ valid: true, owner_user_id: 4 });
    if (path.endsWith('/discovery-runs/resolve')) {
      const requested = (JSON.parse(String(init?.body)) as { url: string }).url;
      const status = [INITIAL_URL, EQUIVALENT_URL].includes(requested) ? 200 : 404;
      resolutions.push({ url: requested, status });
      return status === 200
        ? json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL })
        : json({ message: 'Nenhuma reserva para esta busca sintética.' }, 404);
    }
    if (path.endsWith('/page-captures')) {
      const envelope = JSON.parse(String(init?.body)) as CaptureEnvelope;
      const validReservedUrl = [INITIAL_URL, EQUIVALENT_URL].includes(envelope.capture.final_url);
      const status = envelope.capture.execution_id !== null && !validReservedUrl ? 422 : 201;
      uploads.push({ envelope, status });
      return json({ id: uploads.length, execution_id: envelope.capture.execution_id,
        coverage: envelope.capture.coverage.status }, status);
    }
    throw new Error(`Transporte inesperado no cenário controlado: ${path}`);
  }));
  const controller = createLinkedInJobsController();
  const context = new ContentScriptContext(`reservation-context-${Math.random()}`);

  async function settleMessages(): Promise<void> {
    await Promise.all(messages);
  }

  async function stop(): Promise<void> {
    controller.deactivate();
    await settleMessages();
  }

  return { controller, context, resolutions, uploads, stop };
}

describe('contexto reservado não degrada silenciosamente para captura manual', () => {
  it.each([CHANGED_URL, UNKNOWN_FILTER_URL])('bloqueia upload manual após 200 reservado e re-resolução SPA 404: %s', async (changedUrl) => {
    const flow = composedCapture();
    try {
      await flow.controller.activate(flow.context);
      expect(flow.controller.getCaptureState().executionId).toBe(EXECUTION_ID);
      expect(flow.uploads).toEqual([]);

      window.happyDOM.setURL(changedUrl);
      await flow.stop();
      expect(flow.controller.getCaptureState().captured).toBe(false);
      await flow.controller.activate(flow.context);
      await flow.stop();
      expect(flow.controller.getCaptureState().error).toBe('reservation_changed');

      expect(flow.resolutions[0]).toEqual({ url: INITIAL_URL, status: 200 });
      const manualUploads = flow.uploads.filter(({ envelope }) => envelope.capture.execution_id === null);
      expect(manualUploads.map(({ envelope, status }) => ({
        status, execution_id: envelope.capture.execution_id, final_url: envelope.capture.final_url,
      })), JSON.stringify(flow.resolutions)).toEqual([]);
    } finally {
      await flow.stop();
    }
  });

  it('não força a execução antiga sobre filtros semanticamente diferentes durante a tentativa ativa', async () => {
    const flow = composedCapture();
    try {
      await flow.controller.activate(flow.context);
      expect(flow.controller.getCaptureState().executionId).toBe(EXECUTION_ID);
      window.happyDOM.setURL(CHANGED_URL);
      await flow.stop();
      expect(flow.uploads.map(({ envelope }) => ({
        execution_id: envelope.capture.execution_id,
        initial_url: envelope.capture.initial_url,
        final_url: envelope.capture.final_url,
      }))).toEqual([]);
    } finally {
      await flow.stop();
    }
  });

  it('mantém a reserva quando só path alias e parâmetros de interface mudam', async () => {
    const flow = composedCapture();
    try {
      await flow.controller.activate(flow.context);
      window.happyDOM.setURL(EQUIVALENT_URL);
      await flow.stop();
      expect(flow.uploads).toHaveLength(1);
      expect(flow.uploads[0]!.status).toBe(201);
      expect(flow.uploads[0]!.envelope.capture).toMatchObject({
        execution_id: EXECUTION_ID, initial_url: INITIAL_URL, final_url: EQUIVALENT_URL,
      });
      expect(flow.controller.getCaptureState().captured).toBe(true);
    } finally {
      await flow.stop();
    }
  });

  it('permite captura manual legítima quando a página começa sem qualquer reserva', async () => {
    window.happyDOM.setURL(CHANGED_URL);
    const flow = composedCapture();
    try {
      await flow.controller.activate(flow.context);
      await flow.stop();
      expect(flow.resolutions).toEqual([{ url: CHANGED_URL.replace('currentJobId=900001&', ''), status: 404 }]);
      expect(flow.uploads).toHaveLength(1);
      expect(flow.uploads[0]!.status).toBe(201);
      expect(flow.uploads[0]!.envelope.capture.execution_id).toBeNull();
      expect(flow.controller.getCaptureState().captured).toBe(true);
    } finally {
      await flow.stop();
    }
  });
});

const TAB_A = { tab: { id: 71 }, frameId: 0 };
const TAB_B = { tab: { id: 72 }, frameId: 0 };

function authorityFixture(contexts: CredentialContextStore = createSessionCredentialContextStore()) {
  const store = createMemoryQueueStore();
  const uploads: CaptureEnvelope[] = [];
  const resolvedUrls: string[] = [];
  const receipts = new Set<string>();
  let loseAck = false;
  let failBeforeReceipt = false;
  let uploadStatus = 201;
  let ownerUserId = 4;
  let resolve: (url: string) => Response | Promise<Response> = (url) => [INITIAL_URL, EQUIVALENT_URL, INITIAL_URL + '&trk=fixture&utm_source=smoke'].includes(url)
    ? json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL })
    : json({}, 404);
  vi.stubGlobal('fetch', vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
    if (String(url).endsWith('/extension/auth/validate')) return json({ valid: true, owner_user_id: ownerUserId });
    if (String(url).endsWith('/discovery-runs/resolve')) {
      const observed = JSON.parse(String(init?.body)).url as string;
      resolvedUrls.push(observed);
      return resolve(observed);
    }
    if (!String(url).endsWith('/page-captures')) throw new Error('Unexpected controlled transport');
    const capture = JSON.parse(String(init?.body)) as CaptureEnvelope;
    uploads.push(capture);
    if (failBeforeReceipt) { failBeforeReceipt = false; throw new TypeError('Controlled transport loss before server receipt'); }
    if (uploadStatus !== 201) return json({ message: 'Controlled reservation fencing rejection' }, uploadStatus);
    receipts.add(capture.capture.idempotency_key);
    if (loseAck) { loseAck = false; throw new TypeError('Controlled lost ACK'); }
    return json({ id: 7, coverage: 'complete', replay: uploads.length > 1 }, 201);
  }));
  const recreate = () => createCaptureMessageHandler(createCaptureQueue(store), contexts);
  return { handler: recreate(), recreate, contexts, store, uploads, receipts, resolvedUrls,
    setResolver: (next: typeof resolve) => { resolve = next; }, loseAck: () => { loseAck = true; },
    failBeforeReceipt: () => { failBeforeReceipt = true; }, setUploadStatus: (status: number) => { uploadStatus = status; },
    setOwner: (id: number) => { ownerUserId = id; } };
}

function authorityEnvelope(executionId: string | null = EXECUTION_ID, finalUrl = INITIAL_URL): CaptureEnvelope {
  return { format: 'jobora.capture-envelope', version: 1, blocks: [], capture: {
    idempotency_key: 'trusted-reservation-capture', execution_id: executionId, source: 'linkedin', surface: 'linkedin_jobs',
    initial_url: INITIAL_URL, final_url: finalUrl, captured_at: '2026-11-07T12:00:00Z',
    coverage: { status: 'complete', reason: null, blocks_expected: 0, blocks_captured: 0 },
    html: '<main data-capture-surface="linkedin_jobs" data-capture-version="1"></main>',
  } };
}

async function authorityContext(handler: ReturnType<typeof createCaptureMessageHandler>, url = INITIAL_URL, sender = TAB_A): Promise<string> {
  const result = await handler({ type: 'capture:resolve-run', url }, sender);
  expect(result).toMatchObject({ ok: true, context: expect.any(String) });
  if (!result || !('context' in result) || !result.context) throw new Error('Missing trusted context');
  return result.context;
}

describe('background reservation authority tied to trusted tab and frame', () => {
  it('fails closed without a trusted sender and rejects transferring opaque contexts between tabs or frames', async () => {
    const flow = authorityFixture();
    expect(await flow.handler({ type: 'capture:resolve-run', url: INITIAL_URL })).toMatchObject({ ok: false, error: 'invalid' });
    const context = await authorityContext(flow.handler);
    for (const sender of [undefined, TAB_B, { tab: TAB_A.tab, frameId: 1 }]) {
      expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope: authorityEnvelope() }, sender)).toMatchObject({ ok: false, error: 'invalid' });
    }
    expect(flow.uploads).toHaveLength(0);
    expect(await flow.store.getAll()).toHaveLength(0);
  });

  it('keeps reserved intent across worker recreation while another tab remains legitimately manual', async () => {
    const flow = authorityFixture();
    await authorityContext(flow.handler);
    const restarted = flow.recreate();
    expect(await restarted({ type: 'capture:resolve-run', url: CHANGED_URL }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    const context = await authorityContext(restarted, CHANGED_URL, TAB_B);
    const envelope = authorityEnvelope(null, CHANGED_URL);
    envelope.capture.initial_url = CHANGED_URL;
    expect(await restarted({ type: 'capture:submit-envelope', context, envelope }, TAB_B)).toMatchObject({ ok: true });
    expect(flow.uploads).toHaveLength(1);
    expect(flow.uploads[0]!.capture.execution_id).toBeNull();
  });

  it('retains the same execution for backend-approved aliases and tracking without rewriting capture URLs', async () => {
    const flow = authorityFixture();
    const context = await authorityContext(flow.handler);
    expect(await authorityContext(flow.handler, INITIAL_URL + '&trk=fixture&utm_source=smoke')).toBe(context);
    const envelope = authorityEnvelope(EXECUTION_ID, EQUIVALENT_URL);
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: true });
    expect(flow.uploads).toEqual([envelope]);
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: true, duplicate: true });
    const changed = structuredClone(envelope);
    changed.capture.idempotency_key = 'second-auto-upload';
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope: changed }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect(flow.uploads).toHaveLength(1);
  });

  it('does not downgrade an expired reservation or replace it with a different execution', async () => {
    const flow = authorityFixture();
    flow.setResolver(() => json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL, expires_at: new Date(Date.now() + 1000).toISOString() }));
    const context = await authorityContext(flow.handler);
    await vi.advanceTimersByTimeAsync(1001);
    expect(await flow.recreate()({ type: 'capture:resolve-run', url: INITIAL_URL }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope: authorityEnvelope() }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    flow.setResolver(() => json({ execution_id: 'different-execution', source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL }));
    expect(await flow.recreate()({ type: 'capture:resolve-run', url: INITIAL_URL }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect(flow.uploads).toHaveLength(0);
  });

  it('does not inherit authority across credentials and preserves the original credential payload', async () => {
    const flow = authorityFixture();
    const old = await authorityContext(flow.handler);
    await apiToken.setValue('different-controlled-credential');
    expect(await flow.handler({ type: 'capture:submit-envelope', context: old, envelope: authorityEnvelope() }, TAB_A)).toMatchObject({ ok: false, error: 'credential_changed' });
    const manual = await authorityContext(flow.handler, CHANGED_URL);
    const envelope = authorityEnvelope(null, CHANGED_URL);
    expect(await flow.handler({ type: 'capture:submit-envelope', context: manual, envelope }, TAB_A)).toMatchObject({ ok: true });
    expect(flow.uploads).toHaveLength(1);
    expect(await flow.store.getAll()).toHaveLength(1);
    expect((await flow.store.getAll())[0]?.envelope).toEqual(authorityEnvelope());
  });

  it('rejects stale resolve responses rather than overwriting the newer persisted reservation', async () => {
    const flow = authorityFixture();
    const oldReply = Promise.withResolvers<Response>();
    let started = false;
    flow.setResolver((url) => {
      if (url === INITIAL_URL) { started = true; return oldReply.promise; }
      return json({ execution_id: 'newer-execution', source: 'linkedin', surface: 'linkedin_jobs', initial_url: CHANGED_URL });
    });
    const stale = flow.handler({ type: 'capture:resolve-run', url: INITIAL_URL }, TAB_A);
    await vi.waitFor(() => expect(started).toBe(true));
    const current = await authorityContext(flow.handler, CHANGED_URL);
    oldReply.resolve(json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL }));
    expect(await stale).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect(await authorityContext(flow.recreate(), CHANGED_URL)).toBe(current);
  });

  it('rechecks generation and credentials after readonly validation before enqueue', async () => {
    const flow = authorityFixture();
    const context = await authorityContext(flow.handler);
    const delayed = Promise.withResolvers<Response>();
    let started = false;
    flow.setResolver(() => { started = true; return delayed.promise; });
    const submitting = flow.handler({ type: 'capture:submit-envelope', context, envelope: authorityEnvelope() }, TAB_A);
    await vi.waitFor(() => expect(started).toBe(true));
    flow.setResolver(() => json({}, 404));
    expect(await flow.handler({ type: 'capture:resolve-run', url: CHANGED_URL }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    delayed.resolve(json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL }));
    expect(await submitting).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect(flow.uploads).toHaveLength(0);
    expect(await flow.store.getAll()).toHaveLength(0);
  });

  it('replays only the exact durable queued capture after lost ACK even when no reservation resolves anymore', async () => {
    const flow = authorityFixture();
    const context = await authorityContext(flow.handler);
    const envelope = authorityEnvelope();
    flow.loseAck();
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: false, queued: true });
    const queries = flow.resolvedUrls.length;
    flow.setResolver(() => json({}, 404));
    const restarted = flow.recreate();
    expect(await restarted({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: false, queued: true });
    expect(flow.resolvedUrls).toHaveLength(queries);
    await vi.advanceTimersByTimeAsync(2000);
    await restarted.flush();
    expect(flow.uploads).toEqual([envelope, envelope]);
    expect(flow.receipts.size).toBe(1);
    expect(await flow.store.getAll()).toHaveLength(0);
    expect(await flow.recreate()({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: true });
    expect(flow.resolvedUrls).toHaveLength(queries);
    expect(flow.uploads).toEqual([envelope, envelope, envelope]);
    expect(flow.receipts.size).toBe(1);
    const changed = structuredClone(envelope);
    changed.capture.html += '<p>different body</p>';
    expect(await flow.recreate()({ type: 'capture:submit-envelope', context, envelope: changed }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect(flow.uploads).toHaveLength(3);
  });

  it.each(['receipt', 'status', 'both'])('preserves a durable ACK and exact replay when auxiliary %s persistence fails after HTTP 201', async (failure) => {
    const flow = authorityFixture();
    const expiresAt = new Date(Date.now() + 1000).toISOString();
    flow.setResolver(() => json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL, expires_at: expiresAt }));
    const context = await authorityContext(flow.handler);
    const envelope = authorityEnvelope();
    const put = flow.contexts.putIfRevision.bind(flow.contexts);
    const setStatus = lastApiStatus.setValue.bind(lastApiStatus);
    vi.spyOn(flow.contexts, 'putIfRevision').mockImplementation(async (...args) => {
      if (flow.uploads.length > 0 && failure !== 'status') throw new Error('Controlled auxiliary receipt failure after ACK');
      return put(...args);
    });
    vi.spyOn(lastApiStatus, 'setValue').mockImplementation(async (...args) => {
      if (flow.uploads.length > 0 && failure !== 'receipt') throw new Error('Controlled auxiliary status failure after ACK');
      return setStatus(...args);
    });
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: true });
    expect(await flow.store.getAll()).toHaveLength(0);
    expect((await flow.contexts.get(context))?.acceptedPayloadHash).toEqual(expect.any(String));
    const queries = flow.resolvedUrls.length;
    flow.setResolver(() => json({}, 404));
    await vi.advanceTimersByTimeAsync(2000);
    expect(await flow.recreate()({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: true });
    expect(flow.resolvedUrls).toHaveLength(queries);
    expect(flow.receipts.size).toBe(1);
    expect(flow.uploads.every((uploaded) => JSON.stringify(uploaded) === JSON.stringify(envelope))).toBe(true);
    const changed = structuredClone(envelope);
    changed.capture.idempotency_key = 'new-logical-capture';
    expect(await flow.recreate()({ type: 'capture:submit-envelope', context, envelope: changed }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect(flow.receipts.size).toBe(1);
    expect(await flow.store.getAll()).toHaveLength(0);
  });

  it('does not renew reservation fencing when an exact retry is the first request to reach the server after expiry', async () => {
    const flow = authorityFixture();
    const expiresAt = new Date(Date.now() + 1000).toISOString();
    flow.setResolver(() => json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL, expires_at: expiresAt }));
    const context = await authorityContext(flow.handler);
    const envelope = authorityEnvelope();
    flow.failBeforeReceipt();
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: false, error: 'network', queued: true });
    expect(flow.receipts.size).toBe(0);
    const queries = flow.resolvedUrls.length;
    flow.setResolver(() => json({}, 404));
    flow.setUploadStatus(409);
    await vi.advanceTimersByTimeAsync(2000);
    expect(await flow.recreate()({ type: 'capture:submit-envelope', context, envelope }, TAB_A)).toMatchObject({ ok: false, error: 'conflict', queued: true });
    expect(flow.resolvedUrls).toHaveLength(queries);
    expect(flow.uploads).toEqual([envelope, envelope]);
    expect(flow.receipts.size).toBe(0);
    expect((await flow.store.getAll())[0]?.blockedReason).toBe('conflict');
  });

  it('fails closed at session capacity without evicting a reserved intent', async () => {
    const flow = authorityFixture();
    const context = await authorityContext(flow.handler);
    const binding = await flow.contexts.get(context);
    if (!binding) throw new Error('Missing stored binding');
    for (let index = 0; index < 98; index++) await flow.contexts.put(`capacity-${index}`, binding);
    expect(await flow.handler({ type: 'capture:resolve-run', url: CHANGED_URL }, TAB_B)).toMatchObject({ ok: false, error: 'storage' });
    await expect(flow.contexts.put('overflow', binding)).rejects.toThrow('capacity');
    expect(await flow.recreate()({ type: 'capture:resolve-run', url: CHANGED_URL }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
    expect((await flow.contexts.get(context))?.executionId).toBe(EXECUTION_ID);
  });

  it('reclaims completed contexts at capacity without losing a live reservation', async () => {
    const flow = authorityFixture();
    const live = await authorityContext(flow.handler);
    const binding = (await flow.contexts.get(live))!;
    for (let index = 0; index < 49; index++) {
      const id = `completed-${index}`;
      const scopeKey = `completed-scope-${index}`;
      await flow.contexts.put(scopeKey, { ...binding, contextId: id });
      await flow.contexts.put(id, { ...binding, scopeKey, receipt: { ok: true } });
    }
    const manual = await authorityContext(flow.handler, CHANGED_URL, TAB_B);
    expect(manual).toEqual(expect.any(String));
    expect((await flow.contexts.get(live))?.executionId).toBe(EXECUTION_ID);
    expect(await flow.handler({ type: 'capture:submit-envelope', context: live, envelope: authorityEnvelope() }, TAB_A)).toMatchObject({ ok: true });
  });

  it('reclaims superseded manual contexts across repeated resolves in one tab', async () => {
    const flow = authorityFixture();
    let previous: string | undefined;
    for (let index = 0; index < 120; index++) {
      const context = await authorityContext(flow.handler, CHANGED_URL);
      expect(context).not.toBe(previous);
      previous = context;
    }
    expect(await flow.handler({ type: 'capture:submit-envelope', context: previous, envelope: authorityEnvelope(null, CHANGED_URL) }, TAB_A)).toMatchObject({ ok: true });
  });

  it('does not transfer reserved intent to another frame or API scope', async () => {
    const flow = authorityFixture();
    const reserved = await authorityContext(flow.handler);
    const frame = { tab: TAB_A.tab, frameId: 1 };
    const manualFrame = await authorityContext(flow.handler, CHANGED_URL, frame);
    expect(manualFrame).not.toBe(reserved);
    await apiBaseUrl.setValue('http://localhost:8000');
    expect(await flow.handler({ type: 'capture:submit-envelope', context: reserved, envelope: authorityEnvelope() }, TAB_A)).toMatchObject({ ok: false, error: 'credential_changed' });
    const manualApi = await authorityContext(flow.handler, CHANGED_URL);
    expect(manualApi).not.toBe(reserved);
    expect(await flow.store.getAll()).toHaveLength(1);
  });

  it('does not accept an old owner binding after credential validation identifies a different owner', async () => {
    const flow = authorityFixture();
    const old = await authorityContext(flow.handler);
    flow.setOwner(9);
    const current = await authorityContext(flow.handler, CHANGED_URL);
    expect(current).not.toBe(old);
    expect(await flow.handler({ type: 'capture:submit-envelope', context: old, envelope: authorityEnvelope() }, TAB_A)).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(flow.uploads).toHaveLength(0);
    expect(await flow.store.getAll()).toHaveLength(0);
  });

  it('fences an earlier manual context as soon as a reservation is pinned, even while its opaque context is persisting', async () => {
    const contexts = createSessionCredentialContextStore();
    const put = contexts.put.bind(contexts);
    const resume = Promise.withResolvers<void>();
    let paused = false;
    contexts.put = async (id, binding) => {
      if (binding.executionId === EXECUTION_ID && binding.scopeKey) {
        paused = true;
        await resume.promise;
      }
      await put(id, binding);
    };
    const flow = authorityFixture(contexts);
    const manual = await authorityContext(flow.handler, CHANGED_URL);
    const resolving = flow.handler({ type: 'capture:resolve-run', url: INITIAL_URL }, TAB_A);
    try {
      await vi.waitFor(() => expect(paused).toBe(true));
      expect(await flow.handler({ type: 'capture:submit-envelope', context: manual, envelope: authorityEnvelope(null, CHANGED_URL) }, TAB_A)).toMatchObject({ ok: false, error: 'reservation_changed' });
      expect(flow.uploads).toHaveLength(0);
      expect(await flow.store.getAll()).toHaveLength(0);
    } finally {
      resume.resolve();
      await resolving;
    }
  });

  it('preserves a reserved capture without sending if credentials change during readonly validation', async () => {
    const flow = authorityFixture();
    const context = await authorityContext(flow.handler);
    const delayed = Promise.withResolvers<Response>();
    let started = false;
    flow.setResolver(() => { started = true; return delayed.promise; });
    const submitting = flow.handler({ type: 'capture:submit-envelope', context, envelope: authorityEnvelope() }, TAB_A);
    await vi.waitFor(() => expect(started).toBe(true));
    await apiToken.setValue('new-credential-during-validation');
    delayed.resolve(json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL }));
    expect(await submitting).toMatchObject({ ok: false, error: 'credential_changed' });
    expect(await flow.store.getAll()).toHaveLength(1);
    expect(flow.uploads).toHaveLength(0);
  });

  it('releases closed-tab contexts without abandoning queued captures and fences in-flight replies', async () => {
    const flow = authorityFixture();
    const context = await authorityContext(flow.handler);
    flow.loseAck();
    expect(await flow.handler({ type: 'capture:submit-envelope', context, envelope: authorityEnvelope() }, TAB_A)).toMatchObject({ ok: false, queued: true });
    await flow.handler.tabClosed(TAB_A.tab.id);
    expect(await flow.contexts.get(context)).toBeNull();
    expect(await flow.handler({ type: 'capture:resolve-run', url: CHANGED_URL }, TAB_A)).toMatchObject({ ok: false, error: 'invalid' });
    const pending = Promise.withResolvers<Response>();
    let started = false;
    flow.setResolver(() => { started = true; return pending.promise; });
    const reply = flow.handler({ type: 'capture:resolve-run', url: INITIAL_URL }, TAB_B);
    await vi.waitFor(() => expect(started).toBe(true));
    await flow.handler.tabClosed(TAB_B.tab.id);
    pending.resolve(json({ execution_id: EXECUTION_ID, source: 'linkedin', surface: 'linkedin_jobs', initial_url: INITIAL_URL }));
    expect(await reply).toMatchObject({ ok: false, error: 'reservation_changed' });
    await vi.advanceTimersByTimeAsync(2000);
    await flow.recreate().flush();
    expect(flow.uploads).toHaveLength(2);
    expect(flow.receipts.size).toBe(1);
    expect(await flow.store.getAll()).toHaveLength(0);
  });
});
