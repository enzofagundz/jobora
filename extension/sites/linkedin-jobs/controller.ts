import { createApp, ref, type App as VueApp } from 'vue';
import type { ContentScriptContext, ShadowRootContentScriptUi } from '#imports';
import CapturePanel from '@/components/CapturePanel.vue';
import { createCaptureSession, type CaptureSession } from '@/utils/capture-session';
import type { CaptureCoverageReason, CaptureErrorKind, EnvelopeResult, ResolveRunResult } from '@/types/capture';
import {
  OBSERVER_DEBOUNCE_MS,
  extractLinkedInJob,
  frozenSearchUrl,
  isJobCard,
  isLinkedInJobsRoute,
  jobIdFromCard,
  waitForJobsPane,
} from './extract';
import { createLinkedInJobsBuilder, jobIdentity } from './envelope';

const CAPTURE_SETTLE_MS = 1500;
const PANE_SELECTOR = '.scaffold-layout__detail.jobs-search__job-details';

export interface LinkedInJobsCaptureState {
  captured: boolean;
  pending: boolean;
  coverage: 'complete' | 'partial' | null;
  executionId: string | null;
  cards: number;
  details: number;
  error: CaptureErrorKind | null;
}

export interface LinkedInJobsController {
  activate(ctx: ContentScriptContext): Promise<void>;
  deactivate(): void;
  getCaptureState(): LinkedInJobsCaptureState;
}

interface JobAttempt {
  generation: number;
  initialUrl: string;
  idempotencyKey: string;
  builder: ReturnType<typeof createLinkedInJobsBuilder>;
  executionId: string | null;
  credentialContext: string | null;
  blocked: boolean;
  finalized: boolean;
  pending: boolean;
  captured: boolean;
  coverage: 'complete' | 'partial' | null;
  cancelled: boolean;
  detailFailure: boolean;
  error: CaptureErrorKind | null;
}

export function createLinkedInJobsController(): LinkedInJobsController {
  const session: CaptureSession = createCaptureSession();
  const panelError = ref<CaptureErrorKind | null>(null);
  let observer: MutationObserver | null = null;
  let debounceTimer: number | undefined;
  let finalizeTimer: number | undefined;
  let scanRunning = false;
  let rescanQueued = false;
  let panelVisible = true;
  let app: VueApp | null = null;
  let ui: ShadowRootContentScriptUi<VueApp> | null = null;
  let context: ContentScriptContext | undefined;
  let lifecycleGeneration = 0;
  let attempt: JobAttempt | null = null;
  let pendingElements: Element[] = [];
  const pendingSet = new Set<Element>();
  let escListener: ((event: KeyboardEvent) => void) | null = null;

  function isGenerationActive(generation: number): boolean {
    return (
      generation === lifecycleGeneration &&
      !!context &&
      !context.isInvalid &&
      isLinkedInJobsRoute(location)
    );
  }

  function setAttemptError(current: JobAttempt, error: CaptureErrorKind | null): void {
    current.error = error;
    if (attempt === current && isGenerationActive(current.generation)) {
      panelError.value = error === 'reservation_changed' ? error : null;
    }
  }

  function isCardCandidate(el: Element): boolean {
    return isJobCard(el) && !el.closest(PANE_SELECTOR);
  }

  function collectCandidates(root: ParentNode): void {
    const selectors = [
      'div[data-job-id]',
      'li[data-occludable-job-id]',
      'div.job-card-container',
      'li.scaffold-layout__list-item',
    ];

    for (const selector of selectors) {
      for (const el of root.querySelectorAll(selector)) {
        if (!isCardCandidate(el) || pendingSet.has(el)) continue;
        pendingSet.add(el);
        pendingElements.push(el);
      }
    }

    for (const anchor of root.querySelectorAll('a[href*="/jobs/view/"]')) {
      const card = anchor.closest(
        'li[data-occludable-job-id], div[data-job-id], div.job-card-container, li.scaffold-layout__list-item',
      );
      if (!card || !isCardCandidate(card) || pendingSet.has(card)) continue;
      pendingSet.add(card);
      pendingElements.push(card);
    }
  }

  function enqueueMutationNode(node: Node): void {
    if (!(node instanceof Element)) return;
    if (isCardCandidate(node) && !pendingSet.has(node)) {
      pendingSet.add(node);
      pendingElements.push(node);
    }
    collectCandidates(node);
  }

  async function processCard(el: Element, current: JobAttempt, generation: number): Promise<void> {
    if (session.isInvalidElement(el) || current.cancelled) return;

    const cardEl = el.matches('div[data-job-id], div.job-card-container')
      ? el
      : (el.querySelector('div[data-job-id], div.job-card-container') ?? el);
    const jobId = jobIdFromCard(cardEl);
    if (!jobId) {
      current.detailFailure = true;
      session.markInvalidElement(el);
      session.counters.errors++;
      return;
    }

    if (!current.builder.hasCard(jobId)) {
      if (!current.builder.addCard(jobId, cardEl)) {
        current.detailFailure = true;
        session.applyResult(jobIdentity(jobId), { ok: false, error: 'invalid' });
        return;
      }
      session.claim(jobIdentity(jobId));
    }

    if (current.builder.hasDetail(jobId)) return;
    if (!cardEl.isConnected) {
      // Card removido pelo DOM virtualizado: preserva o bloco sem inventar detalhe.
      current.detailFailure = true;
      return;
    }

    try {
      (cardEl as HTMLElement).click();
    } catch {
      current.detailFailure = true;
      session.counters.errors++;
      return;
    }

    const paneOk = await waitForJobsPane(
      generation,
      () => isGenerationActive(generation) && attempt === current && !current.cancelled,
      jobId,
    );
    if (!isGenerationActive(generation) || attempt !== current || current.cancelled) return;
    if (!paneOk) {
      current.detailFailure = true;
      session.counters.errors++;
      return;
    }

    const raw = await extractLinkedInJob(jobId, cardEl);
    if (!isGenerationActive(generation) || attempt !== current || current.cancelled) return;
    const pane = document.querySelector(PANE_SELECTOR);
    if (!raw || !raw.description || !pane) {
      current.detailFailure = true;
      session.counters.errors++;
      return;
    }

    current.builder.addDetail(jobId, pane);
  }

  async function processNewJobs(): Promise<void> {
    if (scanRunning) {
      rescanQueued = true;
      return;
    }
    scanRunning = true;
    const generation = lifecycleGeneration;
    const current = attempt;
    try {
      if (!current || !isGenerationActive(generation)) return;
      session.counters.phase = 'processing';

      while (pendingElements.length > 0) {
        if (!isGenerationActive(generation) || attempt !== current || current.finalized) return;
        if (current.cancelled) break;
        const el = pendingElements.shift();
        if (!el) break;
        pendingSet.delete(el);
        await processCard(el, current, generation);
      }
    } finally {
      scanRunning = false;
      if (rescanQueued && isGenerationActive(lifecycleGeneration) && attempt === current) {
        rescanQueued = false;
        void processNewJobs();
      } else {
        rescanQueued = false;
        scheduleFinalize();
      }
    }
  }

  function scheduleScan(records?: MutationRecord[]): void {
    // Nova mutação adia a finalização; o lote ainda pode crescer.
    clearTimeout(finalizeTimer);
    if (records) {
      for (const record of records) {
        for (const node of Array.from(record.addedNodes)) enqueueMutationNode(node);
        for (const node of Array.from(record.removedNodes)) enqueueMutationNode(node);
      }
    }
    clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void processNewJobs(), OBSERVER_DEBOUNCE_MS);
  }

  function scheduleFinalize(): void {
    const current = attempt;
    if (!current || current.finalized || current.blocked || current.cancelled) return;
    clearTimeout(finalizeTimer);
    finalizeTimer = window.setTimeout(() => void finalizeAttempt(current, null), CAPTURE_SETTLE_MS);
  }

  async function resolveAttempt(current: JobAttempt, generation: number): Promise<void> {
    try {
      const response = (await browser.runtime.sendMessage({
        type: 'capture:resolve-run',
        url: current.initialUrl,
      })) as ResolveRunResult | undefined;

      if (!isGenerationActive(generation) || attempt !== current) return;
      if (response?.context) current.credentialContext = response.context;

      if (response && response.ok === false) {
        setAttemptError(current, response.error);
        if (
          response.error === 'conflict' ||
          response.error === 'reservation_changed' ||
          response.error === 'auth' ||
          response.error === 'invalid'
        ) {
          current.blocked = true;
          session.counters.phase = 'error';
        }
        return;
      }

      if (response?.ok && response.run) {
        setAttemptError(current, null);
        if (response.run.source !== 'linkedin' || response.run.surface !== 'linkedin_jobs') {
          current.blocked = true;
          session.counters.phase = 'error';
          return;
        }
        current.executionId = response.run.executionId;
      } else if (response?.ok) {
        setAttemptError(current, null);
      }
    } catch {
      // Falha de correlação não impede acumular; o ACK do backend decide.
    }
  }

  async function finalizeAttempt(
    current: JobAttempt | null,
    reason: CaptureCoverageReason | null,
  ): Promise<void> {
    if (!current || current.finalized || current.blocked) return;
    current.finalized = true;
    clearTimeout(finalizeTimer);

    const blocks = current.builder.blocks();
    const cards = blocks.filter((block) => block.kind === 'card');
    const coverageReason =
      reason ??
      (current.cancelled
        ? 'cancelled'
        : current.detailFailure
          ? 'timeout'
          : cards.length === 0
            ? 'no_content'
            : null);
    const finalUrl = isLinkedInJobsRoute(location) ? location.href : current.initialUrl;
    if (!current.credentialContext) {
      session.counters.phase = 'error';
      return;
    }

    const envelope = current.builder.toEnvelope({
      executionId: current.executionId,
      initialUrl: current.initialUrl,
      finalUrl,
      coverageReason,
    });

    current.pending = true;
    try {
      const result = (await browser.runtime.sendMessage({
        type: 'capture:submit-envelope',
        envelope,
        context: current.credentialContext,
      })) as EnvelopeResult | undefined;

      if (!result || result.ok !== true) {
        setAttemptError(current, result?.error ?? 'network');
        for (const card of cards) {
          session.applyResult(jobIdentity(card.platform_id), result ?? { ok: false, error: 'network' });
        }
        session.counters.errors++;
        session.counters.lastOutcome = 'error';
        session.counters.phase = 'error';
        return;
      }

      current.captured = true;
      setAttemptError(current, null);
      current.coverage = result.coverage ?? envelope.capture.coverage.status;
      for (const card of cards) session.applyResult(jobIdentity(card.platform_id), result);
      session.counters.phase = 'completed';
    } catch {
      for (const card of cards) {
        session.applyResult(jobIdentity(card.platform_id), { ok: false, error: 'network' });
      }
      session.counters.errors++;
      session.counters.lastOutcome = 'error';
      session.counters.phase = 'error';
    } finally {
      current.pending = false;
    }
  }

  function newAttempt(generation: number): JobAttempt {
    const idempotencyKey = `linkedin-jobs-${Date.now().toString(36)}-${Math.random()
      .toString(36)
      .slice(2, 10)}`;
    return {
      generation,
      initialUrl: frozenSearchUrl(),
      idempotencyKey,
      builder: createLinkedInJobsBuilder(idempotencyKey),
      executionId: null,
      credentialContext: null,
      blocked: false,
      finalized: false,
      pending: false,
      captured: false,
      coverage: null,
      cancelled: false,
      detailFailure: false,
      error: null,
    };
  }

  function cancelCapture(): void {
    const current = attempt;
    if (!current || current.finalized) return;
    current.cancelled = true;
    void finalizeAttempt(current, 'cancelled');
  }

  async function activate(ctx: ContentScriptContext): Promise<void> {
    const generation = lifecycleGeneration;
    context = ctx;
    panelError.value = null;
    pendingElements = [];
    pendingSet.clear();
    const current = newAttempt(generation);
    attempt = current;

    ui = await createShadowRootUi(ctx, {
      name: 'vagas-capture-panel',
      position: 'modal',
      zIndex: 2147483647,
      onMount(container, _shadow, shadowHost) {
        shadowHost.style.pointerEvents = 'none';
        const panelApp = createApp(CapturePanel, {
          siteLabel: 'Jobora Job Capture',
          counters: session.counters,
          error: panelError,
          visible: panelVisible,
          'onUpdate:visible': (value: boolean) => {
            panelVisible = value;
          },
        });
        panelApp.mount(container);
        return panelApp;
      },
      onRemove(mountedApp) {
        mountedApp?.unmount();
      },
    });
    ui.mount();
    app = (ui.mounted as VueApp | undefined) ?? null;

    if (!isGenerationActive(generation) || attempt !== current) {
      removeUi();
      if (!current.finalized && !current.blocked) {
        void finalizeAttempt(current, 'navigation_interrupted');
      }
      return;
    }

    observer = new MutationObserver(scheduleScan);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributeFilter: ['href', 'data-job-id', 'data-occludable-job-id', 'aria-expanded', 'style'],
    });

    escListener = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') cancelCapture();
    };
    window.addEventListener('keydown', escListener, { capture: true });

    await resolveAttempt(current, generation);
    if (!isGenerationActive(generation) || attempt !== current) return;
    if (current.blocked) return;

    collectCandidates(document);
    await processNewJobs();

    if (!isGenerationActive(generation) || attempt !== current) return;

    const removeInvalidatedListener = ctx.onInvalidated(deactivate);
    removeInvalidatedListeners.push(removeInvalidatedListener);
  }

  const removeInvalidatedListeners: Array<() => void> = [];

  function removeUi(): void {
    clearPreEscListener();
    clearTimeout(debounceTimer);
    clearTimeout(finalizeTimer);
    observer?.disconnect();
    observer = null;
    rescanQueued = false;
    app?.unmount();
    app = null;
    ui?.remove();
    ui = null;
  }

  function clearPreEscListener(): void {
    if (!escListener) return;
    window.removeEventListener('keydown', escListener, { capture: true });
    escListener = null;
  }

  function deactivate(): void {
    lifecycleGeneration++;
    for (const remove of removeInvalidatedListeners.splice(0)) remove();
    context = undefined;
    panelError.value = null;
    const interrupted = attempt;
    removeUi();
    if (interrupted && !interrupted.finalized && !interrupted.blocked) {
      void finalizeAttempt(interrupted, 'navigation_interrupted');
    }
  }

  function getCaptureState(): LinkedInJobsCaptureState {
    const blocks = attempt?.builder.blocks() ?? [];
    return {
      error: attempt?.error ?? null,
      captured: attempt?.captured ?? false,
      pending: attempt?.pending ?? false,
      coverage: attempt?.coverage ?? null,
      executionId: attempt?.executionId ?? null,
      cards: blocks.filter((block) => block.kind === 'card').length,
      details: blocks.filter((block) => block.kind === 'detail').length,
    };
  }

  return { activate, deactivate, getCaptureState };
}
