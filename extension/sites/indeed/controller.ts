import { createApp, type App as VueApp } from 'vue';
import type { ContentScriptContext, ShadowRootContentScriptUi } from '#imports';
import CapturePanel from '@/components/CapturePanel.vue';
import { createCaptureSession, type CaptureSession } from '@/utils/capture-session';
import type {
  CaptureCoverageReason,
  EnvelopeResult,
  ResolveRunResult,
} from '@/types/capture';
import {
  OBSERVER_DEBOUNCE_MS,
  extractJob,
  frozenSearchUrl,
  isCard,
  isIndeedRoute,
  indeedPaneMatches,
  waitForPane,
} from './extract';
import { createIndeedJobsBuilder, jobIdentity } from './envelope';

const CAPTURE_SETTLE_MS = 1_500;

export interface IndeedCaptureState {
  captured: boolean;
  pending: boolean;
  coverage: 'complete' | 'partial' | null;
  executionId: string | null;
  cards: number;
  details: number;
}

export interface IndeedController {
  activate(ctx: ContentScriptContext): Promise<void>;
  deactivate(): void;
  getCaptureState(): IndeedCaptureState;
}

interface CaptureAttempt {
  initialUrl: string;
  idempotencyKey: string;
  builder: ReturnType<typeof createIndeedJobsBuilder>;
  executionId: string | null;
  credentialContext: string | null;
  resolved: boolean;
  blocked: boolean;
  finalized: boolean;
  pending: boolean;
  captured: boolean;
  coverage: 'complete' | 'partial' | null;
  detailFailure: boolean;
}

export function createIndeedController(): IndeedController {
  const session: CaptureSession = createCaptureSession();
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
  let attempt: CaptureAttempt | null = null;
  let pendingElements: Element[] = [];
  const pendingSet = new Set<Element>();

  function isGenerationActive(generation: number): boolean {
    return (
      generation === lifecycleGeneration &&
      !!context &&
      !context.isInvalid &&
      isIndeedRoute(location)
    );
  }

  function addCandidate(element: Element, includeDetached = false): void {
    if (!element.matches('a[data-jk]')) return;
    if (!includeDetached && !isCard(element)) return;
    if (pendingSet.has(element)) return;
    pendingSet.add(element);
    pendingElements.push(element);
  }

  function collectCandidates(root: ParentNode, includeDetached = false): void {
    if (root instanceof Element) addCandidate(root, includeDetached);
    for (const element of root.querySelectorAll('a[data-jk]')) {
      addCandidate(element, includeDetached);
    }
  }

  function enqueueMutationNode(node: Node, includeDetached = false): void {
    if (node instanceof Element) collectCandidates(node, includeDetached);
  }

  async function processCard(element: Element, current: CaptureAttempt, generation: number): Promise<void> {
    if (session.isInvalidElement(element)) return;

    const jk = element.getAttribute('data-jk')?.trim();
    if (!jk) {
      current.detailFailure = true;
      session.markInvalidElement(element);
      session.counters.errors++;
      return;
    }

    if (!current.builder.hasCard(jk)) {
      current.builder.addCard(jk, element);
      session.claim(jobIdentity(jk));
    }
    if (current.builder.hasDetail(jk)) return;
    if (!element.isConnected) {
      current.detailFailure = true;
      session.counters.errors++;
      return;
    }

    const title = element.querySelector('span[title]')?.getAttribute('title')?.trim()
      || element.querySelector('h2')?.textContent?.trim()
      || null;
    const previousVjk = new URLSearchParams(location.search).get('vjk');
    const previousPane = document.querySelector('#jobsearch-ViewjobPaneWrapper');
    const previousPaneHtml = previousPane?.innerHTML ?? null;
    try {
      (element as HTMLElement).click();
    } catch {
      current.detailFailure = true;
      session.counters.errors++;
      return;
    }

    const paneReady = await waitForPane(
      generation,
      isGenerationActive,
      jk,
      previousVjk,
      previousPane,
      previousPaneHtml,
    );
    if (!isGenerationActive(generation) || attempt !== current) return;
    if (!paneReady) {
      current.detailFailure = true;
      session.counters.errors++;
      return;
    }

    const raw = await extractJob(jk, title, element);
    if (!isGenerationActive(generation) || attempt !== current) return;
    const pane = document.querySelector('#jobsearch-ViewjobPaneWrapper');
    if (
      !raw?.description ||
      !pane ||
      !indeedPaneMatches(pane, jk, previousVjk, previousPane, previousPaneHtml)
    ) {
      current.detailFailure = true;
      session.counters.errors++;
      return;
    }

    current.builder.addDetail(jk, pane);
  }

  async function processNewCards(): Promise<void> {
    if (scanRunning) {
      rescanQueued = true;
      return;
    }
    scanRunning = true;
    const generation = lifecycleGeneration;
    const current = attempt;
    try {
      if (!current || !current.resolved || current.blocked || !isGenerationActive(generation)) return;
      session.counters.phase = 'processing';

      while (pendingElements.length > 0) {
        if (!isGenerationActive(generation) || attempt !== current || current.finalized) return;
        const element = pendingElements.shift();
        if (!element) break;
        pendingSet.delete(element);
        await processCard(element, current, generation);
      }
    } finally {
      scanRunning = false;
      if (rescanQueued && isGenerationActive(lifecycleGeneration) && attempt === current) {
        rescanQueued = false;
        void processNewCards();
      } else {
        rescanQueued = false;
        scheduleFinalize();
      }
    }
  }

  function scheduleScan(records?: MutationRecord[]): void {
    clearTimeout(finalizeTimer);
    if (records) {
      for (const record of records) {
        for (const node of Array.from(record.addedNodes)) {
          enqueueMutationNode(node, !node.isConnected);
        }
        for (const node of Array.from(record.removedNodes)) {
          enqueueMutationNode(node, true);
        }
        if (record.type === 'attributes' && record.target instanceof Element) {
          enqueueMutationNode(record.target);
        }
      }
    }
    clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void processNewCards(), OBSERVER_DEBOUNCE_MS);
  }

  function scheduleFinalize(): void {
    const current = attempt;
    if (!current || !current.resolved || current.finalized || current.blocked) return;
    clearTimeout(finalizeTimer);
    finalizeTimer = window.setTimeout(() => void finalizeAttempt(current, null), CAPTURE_SETTLE_MS);
  }

  async function resolveAttempt(current: CaptureAttempt, generation: number): Promise<void> {
    try {
      const response = (await browser.runtime.sendMessage({
        type: 'capture:resolve-run',
        url: current.initialUrl,
      })) as ResolveRunResult | undefined;

      if (!isGenerationActive(generation) || attempt !== current) return;
      if (response?.context) current.credentialContext = response.context;
      if (response && response.ok === false) {
        if (['auth', 'invalid', 'conflict', 'not_found', 'ambiguous'].includes(response.error)) {
          current.blocked = true;
          session.counters.phase = 'error';
        }
        return;
      }

      if (response?.ok && response.run) {
        if (response.run.source !== 'indeed' || response.run.surface !== 'indeed_jobs') {
          current.blocked = true;
          session.counters.phase = 'error';
          return;
        }
        current.executionId = response.run.executionId;
      }
    } catch {
      // A falha de rede não autoriza trocar credencial nem inventar execução.
    } finally {
      current.resolved = true;
    }
  }

  async function finalizeAttempt(
    current: CaptureAttempt | null,
    reason: CaptureCoverageReason | null,
  ): Promise<void> {
    if (!current || !current.resolved || current.finalized || current.blocked) return;
    current.finalized = true;
    clearTimeout(finalizeTimer);

    const blocks = current.builder.blocks();
    const cards = blocks.filter((block) => block.kind === 'card');
    const coverageReason = reason ?? (current.detailFailure ? 'timeout' : cards.length === 0 ? 'no_content' : null);
    const finalUrl = isIndeedRoute(location) ? location.href : current.initialUrl;
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
    session.counters.phase = 'sending';
    try {
      const result = (await browser.runtime.sendMessage({
        type: 'capture:submit-envelope',
        envelope,
        context: current.credentialContext,
      })) as EnvelopeResult | undefined;
      if (!result || result.ok !== true) {
        for (const card of cards) {
          session.applyResult(jobIdentity(card.platform_id), result ?? { ok: false, error: 'network' });
        }
        session.counters.errors++;
        session.counters.lastOutcome = 'error';
        session.counters.phase = 'error';
        return;
      }

      current.captured = true;
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

  function newAttempt(): CaptureAttempt {
    const idempotencyKey = `indeed-jobs-${Date.now().toString(36)}-${Math.random()
      .toString(36)
      .slice(2, 10)}`;
    return {
      initialUrl: frozenSearchUrl(),
      idempotencyKey,
      builder: createIndeedJobsBuilder(idempotencyKey),
      executionId: null,
      credentialContext: null,
      resolved: false,
      blocked: false,
      finalized: false,
      pending: false,
      captured: false,
      coverage: null,
      detailFailure: false,
    };
  }

  async function activate(ctx: ContentScriptContext): Promise<void> {
    const generation = lifecycleGeneration;
    context = ctx;
    pendingElements = [];
    pendingSet.clear();
    const current = newAttempt();
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
      return;
    }

    const removeInvalidatedListener = ctx.onInvalidated(deactivate);
    removeInvalidatedListeners.push(removeInvalidatedListener);
    observer = new MutationObserver(scheduleScan);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['href', 'data-jk', 'aria-expanded', 'style'],
    });

    await resolveAttempt(current, generation);
    if (!isGenerationActive(generation) || attempt !== current || current.blocked) return;

    collectCandidates(document);
    await processNewCards();
  }

  const removeInvalidatedListeners: Array<() => void> = [];

  function removeUi(): void {
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

  function deactivate(): void {
    lifecycleGeneration++;
    for (const remove of removeInvalidatedListeners.splice(0)) remove();
    context = undefined;
    const interrupted = attempt;
    removeUi();
    if (interrupted && !interrupted.finalized && !interrupted.blocked) {
      void finalizeAttempt(interrupted, 'navigation_interrupted');
    }
  }

  function getCaptureState(): IndeedCaptureState {
    const blocks = attempt?.builder.blocks() ?? [];
    return {
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
