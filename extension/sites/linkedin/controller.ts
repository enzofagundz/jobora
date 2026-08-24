import { createApp, type App as VueApp } from 'vue';
import type { ContentScriptContext, ShadowRootContentScriptUi } from '#imports';
import CapturePanel from '@/components/CapturePanel.vue';
import { createCaptureSession, type CaptureSession } from '@/utils/capture-session';
import { deriveCaptureIdentity } from '@/utils/capture-identity';
import {
  OBSERVER_DEBOUNCE_MS,
  buildPayload,
  extractLinkedInPost,
  isLinkedInRoute,
  isPost,
} from './extract';

export interface LinkedInController {
  activate(ctx: ContentScriptContext): Promise<void>;
  deactivate(): void;
}

/** Uma instância por aba; activate/deactivate por rota SPA. */
export function createLinkedInController(): LinkedInController {
  const session: CaptureSession = createCaptureSession();
  let observer: MutationObserver | null = null;
  let debounceTimer: number | undefined;
  let scanRunning = false;
  let rescanQueued = false;
  let panelVisible = true;
  let app: VueApp | null = null;
  let ui: ShadowRootContentScriptUi<VueApp> | null = null;
  let context: ContentScriptContext | undefined;
  // Geração de ciclo de vida: deactivate() a incrementa; todo `await` re-checa.
  let lifecycleGeneration = 0;

  /** Falso após deactivate(), troca de rota ou invalidação do contexto WXT. */
  function isGenerationActive(generation: number): boolean {
    return (
      generation === lifecycleGeneration &&
      !!context &&
      !context.isInvalid &&
      isLinkedInRoute(location)
    );
  }

  async function processPost(el: Element): Promise<void> {
    if (session.isInvalidElement(el)) return;

    const generation = lifecycleGeneration;
    let raw: Awaited<ReturnType<typeof extractLinkedInPost>>;
    try {
      raw = await extractLinkedInPost(el);
    } catch {
      session.markInvalidElement(el);
      session.counters.errors++;
      return;
    }
    if (!isGenerationActive(generation)) return;

    // Regra exata do userscript: rejeita somente se TUDO vazio.
    if (!raw.externalId && !raw.description && !raw.url) {
      session.markInvalidElement(el);
      session.counters.errors++;
      return;
    }

    const payload = buildPayload(raw);
    const identity = await deriveCaptureIdentity(payload);
    if (!isGenerationActive(generation)) return;

    // Claim antes da rede: clones/recriações/scroll não passam daqui.
    if (!session.claim(identity)) return;

    try {
      session.counters.phase = 'sending';
      const result = await browser.runtime.sendMessage({
        type: 'capture:submit',
        payload,
      });
      if (!isGenerationActive(generation)) return;
      session.applyResult(identity, result);
    } catch {
      // Erro não é reenviado na mesma sessão; o claim vira terminal.
      session.applyResult(identity, { ok: false, error: 'network' });
    }
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

      const posts = Array.from(document.querySelectorAll('[role="listitem"]')).filter(isPost);
      for (const el of posts) {
        if (!isGenerationActive(generation)) return;
        await processPost(el);
        if (!isGenerationActive(generation)) return;
      }

      if (!isGenerationActive(generation)) return;
      session.counters.phase =
        session.counters.processed > 0 && session.counters.errors === 0 ? 'completed' : 'waiting';
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

  function scheduleScan(): void {
    clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void processNewPosts(), OBSERVER_DEBOUNCE_MS);
  }

  async function activate(ctx: ContentScriptContext): Promise<void> {
    const generation = lifecycleGeneration;
    context = ctx;

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
    if (!isGenerationActive(generation)) {
      removeUi();
      return;
    }

    // Observer ANTES do scan inicial para não perder mutações da expansão.
    observer = new MutationObserver(scheduleScan);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributeFilter: ['href', 'data-jk', 'data-urn', 'data-entity-urn', 'aria-expanded', 'style'],
    });

    await processNewPosts();

    if (!isGenerationActive(generation)) return;

    // Guardar e remover o callback para não acumular a cada entrada/saída SPA.
    const removeInvalidatedListener = ctx.onInvalidated(deactivate);
    removeInvalidatedListeners.push(removeInvalidatedListener);
  }

  const removeInvalidatedListeners: Array<() => void> = [];

  /** Remove UI sem tocar geração/contadores; usado pelo activate invalidado e pelo deactivate. */
  function removeUi(): void {
    observer?.disconnect();
    observer = null;
    clearTimeout(debounceTimer);
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
    removeUi();
    // Contadores/claims permanecem em memória para reentrada na mesma aba.
  }

  return { activate, deactivate };
}
