import { createApp, reactive, type App as VueApp } from 'vue';
import type { ContentScriptContext, ShadowRootContentScriptUi } from '#imports';
import CapturePanel from '@/components/CapturePanel.vue';
import { createCaptureSession, type CaptureSession } from '@/utils/capture-session';
import { deriveCaptureIdentity } from '@/utils/capture-identity';
import { safeHref } from '@/utils/capture-dom';
import type { CaptureCoverageReason, EnvelopeResult, ResolveRunResult } from '@/types/capture';
import {
  OBSERVER_DEBOUNCE_MS,
  buildPayload,
  extractLinkedInPost,
  isLinkedInRoute,
  isPost,
  type HydrationIdentityResolver,
} from './extract';
import { countPosts, runJumpToEnd } from './jump';
import { createLinkedInPublicationsBuilder } from './envelope';

const CAPTURE_SETTLE_MS = 1500;

export interface LinkedInCaptureState {
  captured: boolean;
  pending: boolean;
  coverage: 'complete' | 'partial' | null;
  executionId: string | null;
  blocks: number;
}

export interface LinkedInController {
  activate(ctx: ContentScriptContext): Promise<void>;
  deactivate(): void;
  getCaptureState(): LinkedInCaptureState;
}

export interface LinkedInControllerOptions {
  resolveHydrationIdentity?: HydrationIdentityResolver;
}

interface CaptureAttempt {
  generation: number;
  initialUrl: string;
  idempotencyKey: string;
  builder: ReturnType<typeof createLinkedInPublicationsBuilder>;
  executionId: string | null;
  credentialContext: string | null;
  blocked: boolean;
  finalized: boolean;
  pending: boolean;
  captured: boolean;
  coverage: 'complete' | 'partial' | null;
  identityFailure: boolean;
  occurrenceIdentities: WeakMap<Element, { signature: string; identity: string }>;
  nextOccurrenceId: number;
}

/** Uma instância por aba; activate/deactivate por rota SPA. */
export function createLinkedInController(
  options: LinkedInControllerOptions = {},
): LinkedInController {
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
  // Geração de ciclo de vida: deactivate() a incrementa; todo `await` re-checa.
  let lifecycleGeneration = 0;
  const jumpState = reactive({ active: false, count: 0 });
  let jumpAbort: AbortController | null = null;
  let escListener: ((e: KeyboardEvent) => void) | null = null;
  let preInteracted = false;
  let preListeners: Array<() => void> = [];
  let attempt: CaptureAttempt | null = null;
  let pendingElements: Element[] = [];
  const pendingSet = new Set<Element>();

  /** Falso após deactivate(), troca de rota ou invalidação do contexto WXT. */
  function isGenerationActive(generation: number): boolean {
    return (
      generation === lifecycleGeneration &&
      !!context &&
      !context.isInvalid &&
      isLinkedInRoute(location)
    );
  }

  function enqueueCandidates(root: ParentNode): void {
    for (const el of root.querySelectorAll('[role="listitem"]')) {
      if (!isPost(el) || pendingSet.has(el)) continue;
      pendingSet.add(el);
      pendingElements.push(el);
    }
  }

  function enqueueMutationNode(node: Node): void {
    if (!(node instanceof Element)) return;
    if (isPost(node) && !pendingSet.has(node)) {
      pendingSet.add(node);
      pendingElements.push(node);
    }
    enqueueCandidates(node);
  }

  async function collectPost(el: Element, generation: number): Promise<void> {
    const current = attempt;
    if (!current || current.blocked || current.finalized) return;
    if (session.isInvalidElement(el)) return;

    let raw: Awaited<ReturnType<typeof extractLinkedInPost>>;
    try {
      raw = await extractLinkedInPost(el, options.resolveHydrationIdentity);
    } catch {
      current.identityFailure = true;
      session.markInvalidElement(el);
      session.counters.errors++;
      return;
    }
    if (!isGenerationActive(generation) || attempt !== current) return;
    if (raw.identityConflict) {
      current.identityFailure = true;
      session.markInvalidElement(el);
      session.counters.errors++;
      return;
    }

    // Regra exata do usuarioscript: rejeita somente se TUDO vazio.
    if (!raw.externalId && !raw.description && !raw.url) {
      current.identityFailure = true;
      session.markInvalidElement(el);
      session.counters.errors++;
      return;
    }

    const payload = buildPayload(raw);
    const normalizedUrl = raw.url ? safeHref(raw.url) : null;
    const hasStrongIdentity = Boolean(raw.externalId?.trim() || normalizedUrl);
    const signature = JSON.stringify([
      payload.description,
      payload.author?.name ?? null,
      payload.author?.profile_url ?? null,
    ]);
    const occurrence = current.occurrenceIdentities.get(el);
    let occurrenceIdentityToEnrich: string | null = null;
    let identity: string;
    if (hasStrongIdentity) {
      identity = await deriveCaptureIdentity({ ...payload, url: normalizedUrl });
      if (
        occurrence?.signature === signature &&
        current.builder.has(occurrence.identity)
      ) {
        occurrenceIdentityToEnrich = occurrence.identity;
      }
    } else {
      if (occurrence?.signature === signature) {
        identity = occurrence.identity;
      } else {
        identity = `${current.idempotencyKey}:occurrence:${++current.nextOccurrenceId}`;
        current.occurrenceIdentities.set(el, { signature, identity });
      }
    }
    if (!isGenerationActive(generation) || attempt !== current) return;

    if (
      occurrenceIdentityToEnrich !== null &&
      current.builder.enrich(occurrenceIdentityToEnrich, identity, raw, el)
    ) {
      // Promote only this unchanged DOM occurrence, never a content match across cards.
      if (!jumpState.active) scheduleFinalize();
      return;
    }

    if (current.builder.has(identity)) return;
    if (!session.claim(identity)) return;

    current.builder.add(raw, identity, el);
    current.occurrenceIdentities.set(el, { signature, identity });

    if (!jumpState.active) scheduleFinalize();
  }

  /** Lote sequencial; callback concorrente apenas enfileira um rescan. */
  async function processNewPosts(): Promise<void> {
    if (scanRunning) {
      rescanQueued = true;
      return;
    }
    scanRunning = true;
    const generation = lifecycleGeneration;
    try {
      if (!isGenerationActive(generation)) return;
      session.counters.phase = 'processing';

      while (pendingElements.length > 0) {
        if (!isGenerationActive(generation) || attempt?.finalized) return;
        const el = pendingElements.shift();
        if (!el) break;
        pendingSet.delete(el);
        await collectPost(el, generation);
        if (!isGenerationActive(generation)) return;
      }
    } finally {
      scanRunning = false;
      // Um scan antigo nunca agenda o próximo lote.
      if (rescanQueued && isGenerationActive(lifecycleGeneration)) {
        rescanQueued = false;
        void processNewPosts();
      } else {
        rescanQueued = false;
      }
    }
  }

  function scheduleScan(records?: MutationRecord[]): void {
    if (records) {
      for (const record of records) {
        for (const node of Array.from(record.addedNodes)) enqueueMutationNode(node);
        // LinkedIn virtualiza a lista: o card removido ainda existe na memória.
        for (const node of Array.from(record.removedNodes)) enqueueMutationNode(node);
      }
    }
    clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void processNewPosts(), OBSERVER_DEBOUNCE_MS);
  }

  function scheduleFinalize(reason: CaptureCoverageReason | null = null): void {
    const current = attempt;
    if (!current || current.finalized || current.blocked) return;
    clearTimeout(finalizeTimer);
    finalizeTimer = window.setTimeout(
      () => void finalizeAttempt(current, reason),
      CAPTURE_SETTLE_MS,
    );
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
        // Ambiguidade/auth invalida a correlação: não mistura execuções.
        if (
          response.error === 'conflict' ||
          response.error === 'auth' ||
          response.error === 'invalid'
        ) {
          current.blocked = true;
          session.counters.phase = 'error';
        }
        return;
      }

      if (response?.ok && response.run) {
        if (response.run.source !== 'linkedin' || response.run.surface !== 'linkedin_publications') {
          current.blocked = true;
          session.counters.phase = 'error';
          return;
        }
        current.executionId = response.run.executionId;
      }
    } catch {
      // Falha de correlação não impede acumular; o ACK do backend decide.
    }
  }

  async function finalizeAttempt(
    current: CaptureAttempt | null,
    reason: CaptureCoverageReason | null,
  ): Promise<void> {
    if (!current || current.finalized || current.blocked) return;
    current.finalized = true;
    clearTimeout(finalizeTimer);

    const blocks = current.builder.list();
    const coverageReason =
      reason ??
      (current.identityFailure ? 'timeout' : blocks.length === 0 ? 'no_content' : null);
    const finalUrl = isLinkedInRoute(location) ? location.href : current.initialUrl;
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
        for (const post of blocks) {
          session.applyResult(post.identity, result ?? { ok: false, error: 'network' });
        }
        session.counters.errors++;
        session.counters.lastOutcome = 'error';
        session.counters.phase = 'error';
        return;
      }

      current.captured = true;
      current.coverage = result.coverage ?? envelope.capture.coverage.status;
      for (const post of blocks) session.applyResult(post.identity, result);
      session.counters.phase = 'completed';
    } catch {
      for (const post of blocks) {
        session.applyResult(post.identity, { ok: false, error: 'network' });
      }
      session.counters.errors++;
      session.counters.lastOutcome = 'error';
      session.counters.phase = 'error';
    } finally {
      current.pending = false;
    }
  }

  function cancelJump(): void {
    jumpAbort?.abort();
  }

  function clearPreInteractionListeners(): void {
    for (const remove of preListeners.splice(0)) remove();
  }

  function watchPreInteraction(): void {
    clearPreInteractionListeners();
    const mark = (): void => {
      preInteracted = true;
    };
    const events = ['wheel', 'keydown', 'mousedown', 'touchmove'] as const;
    for (const name of events) {
      // keydown aqui é só veto pré-disparo; o Esc durante o jump tem listener próprio.
      const handler = (): void => mark();
      window.addEventListener(name, handler, { capture: true });
      preListeners.push(() => window.removeEventListener(name, handler, { capture: true }));
    }
  }

  async function startJump(generation: number): Promise<void> {
    const current = attempt;
    if (!current || jumpAbort) return;
    if (!isGenerationActive(generation)) return;
    if (current.builder.size() === 0) {
      void finalizeAttempt(
        current,
        current.identityFailure ? 'timeout' : 'no_content',
      );
      return;
    }
    clearTimeout(finalizeTimer);

    const ac = new AbortController();
    jumpAbort = ac;
    jumpState.active = true;
    jumpState.count = countPosts();
    const onEsc = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') ac.abort();
    };
    escListener = onEsc;
    window.addEventListener('keydown', onEsc, { capture: true });

    let result: Awaited<ReturnType<typeof runJumpToEnd>>;
    try {
      result = await runJumpToEnd({
        isActive: () => isGenerationActive(generation) && !ac.signal.aborted && attempt === current,
        signal: ac.signal,
        onProgress: (c) => {
          jumpState.count = c;
        },
      });
    } finally {
      window.removeEventListener('keydown', onEsc, { capture: true });
      if (escListener === onEsc) escListener = null;
      if (jumpAbort === ac) jumpAbort = null;
      jumpState.active = false;
    }

    if (attempt !== current || current.finalized) return;
    if (result === 'cancelled') {
      void finalizeAttempt(current, 'cancelled');
    } else if (result === 'empty') {
      void finalizeAttempt(current, 'no_content');
    } else if (result === 'aborted') {
      void finalizeAttempt(current, 'navigation_interrupted');
    } else {
      void finalizeAttempt(current, null);
    }
  }

  function newAttempt(generation: number): CaptureAttempt {
    const idempotencyKey = `linkedin-publications-${Date.now().toString(36)}-${Math.random()
      .toString(36)
      .slice(2, 10)}`;
    return {
      generation,
      initialUrl: window.location.href,
      idempotencyKey,
      builder: createLinkedInPublicationsBuilder(idempotencyKey),
      executionId: null,
      credentialContext: null,
      blocked: false,
      finalized: false,
      pending: false,
      captured: false,
      coverage: null,
      identityFailure: false,
      occurrenceIdentities: new WeakMap(),
      nextOccurrenceId: 0,
    };
  }

  async function activate(ctx: ContentScriptContext): Promise<void> {
    const generation = lifecycleGeneration;
    context = ctx;
    preInteracted = false;
    jumpState.active = false;
    jumpState.count = 0;
    pendingElements = [];
    pendingSet.clear();
    watchPreInteraction();

    const current = newAttempt(generation);
    attempt = current;

    // position modal → container fixo cobrindo a viewport; pointer-events none
    // deixa os cliques do site passarem (o painel religa em si).
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
          jump: jumpState,
          onCancelJump: () => cancelJump(),
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

    // deactivate() pode ter corrido durante o await da UI: descarta a geração.
    if (!isGenerationActive(generation) || attempt !== current) {
      removeUi();
      if (!current.finalized && !current.blocked) {
        void finalizeAttempt(current, 'navigation_interrupted');
      }
      return;
    }

    // Observer ANTES do scan inicial para não perder mutações da expansão.
    observer = new MutationObserver(scheduleScan);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributeFilter: ['href', 'data-jk', 'data-urn', 'data-entity-urn', 'aria-expanded', 'style'],
    });

    await resolveAttempt(current, generation);
    if (!isGenerationActive(generation) || attempt !== current) return;
    if (current.blocked) {
      clearPreInteractionListeners();
      return;
    }

    enqueueCandidates(document);
    await processNewPosts();

    clearPreInteractionListeners();

    if (!isGenerationActive(generation) || attempt !== current) return;

    // Guardar e remover o callback para não acumular a cada entrada/saída SPA.
    const removeInvalidatedListener = ctx.onInvalidated(deactivate);
    removeInvalidatedListeners.push(removeInvalidatedListener);

    if (!preInteracted) {
      void startJump(generation);
    } else {
      scheduleFinalize();
    }
  }

  const removeInvalidatedListeners: Array<() => void> = [];

  /** Remove UI sem tocar geração/contadores; usado pelo activate invalidado e pelo deactivate. */
  function removeUi(): void {
    cancelJump();
    clearPreInteractionListeners();
    clearTimeout(finalizeTimer);
    if (escListener) {
      window.removeEventListener('keydown', escListener, { capture: true });
      escListener = null;
    }
    observer?.disconnect();
    observer = null;
    clearTimeout(debounceTimer);
    rescanQueued = false;
    jumpState.active = false;
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
    // Contadores/claims permanecem em memória para reentrada na mesma aba.
  }

  function getCaptureState(): LinkedInCaptureState {
    const current = attempt;
    return {
      captured: current?.captured ?? false,
      pending: current?.pending ?? false,
      coverage: current?.coverage ?? null,
      executionId: current?.executionId ?? null,
      blocks: current?.builder.size() ?? 0,
    };
  }

  return { activate, deactivate, getCaptureState };
}
