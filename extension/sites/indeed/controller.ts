import { createApp, type App as VueApp } from 'vue';
import type { ContentScriptContext, ShadowRootContentScriptUi } from '#imports';
import CapturePanel from '@/components/CapturePanel.vue';
import { createCaptureSession, type CaptureSession } from '@/utils/capture-session';
import { externalCaptureIdentity } from '@/utils/capture-identity';
import {
  OBSERVER_DEBOUNCE_MS,
  buildPayload,
  extractJob,
  frozenSearchUrl,
  isCard,
  isIndeedRoute,
  waitForPane,
} from './extract';

export interface IndeedController {
  activate(ctx: ContentScriptContext): Promise<void>;
  deactivate(): void;
}

/**
 * Uma instância por aba. O painel lateral do Indeed é estado compartilhado:
 * a fila é ESTRITAMENTE serial (scanRunning/rescanQueued).
 */
export function createIndeedController(): IndeedController {
  const session: CaptureSession = createCaptureSession();
  let observer: MutationObserver | null = null;
  let debounceTimer: number | undefined;
  let scanRunning = false;
  let rescanQueued = false;
  let panelVisible = true;
  let app: VueApp | null = null;
  let ui: ShadowRootContentScriptUi<VueApp> | null = null;
  let searchUrl = '';
  let context: ContentScriptContext | undefined;
  // Geração de ciclo de vida: deactivate() a incrementa; todo `await` re-checa.
  let lifecycleGeneration = 0;

  /** Falso após deactivate(), troca de rota ou invalidação do contexto WXT. */
  function isGenerationActive(generation: number): boolean {
    return (
      generation === lifecycleGeneration &&
      !!context &&
      !context.isInvalid &&
      isIndeedRoute(location)
    );
  }

  async function processCard(el: Element): Promise<void> {
    if (session.isInvalidElement(el)) return;

    const jk = el.getAttribute('data-jk');
    const titleEl = el.querySelector('span[title]');
    const cardTitle =
      (titleEl && (titleEl.getAttribute('title') || titleEl.textContent.trim())) ||
      el.querySelector('h2')?.textContent.trim() ||
      null;

    if (!jk) {
      session.markInvalidElement(el);
      session.counters.errors++;
      return;
    }

    const identity = externalCaptureIdentity('indeed', jk);
    // Claim ANTES do clique/polling: nenhuma recriação do card reclica.
    if (!session.claim(identity)) return;

    const generation = lifecycleGeneration;
    try {
      (el as HTMLElement).click();
    } catch {
      session.applyResult(identity, { ok: false, error: 'invalid' });
      return;
    }
    const paneOk = await waitForPane(generation, isGenerationActive, jk, cardTitle);
    if (!isGenerationActive(generation)) return;
    if (!paneOk) {
      // Rota mudou/timeout: erro terminal; o backend continua sendo a barreira.
      session.applyResult(identity, { ok: false, error: 'network' });
      return;
    }

    const raw = await extractJob(jk, cardTitle, el);
    if (!isGenerationActive(generation)) return;
    // Captura parcial nunca é enviada (descrição vazia rejeita promoção no backend).
    if (!raw || !raw.description) {
      session.applyResult(identity, { ok: false, error: 'invalid' });
      return;
    }

    try {
      session.counters.phase = 'sending';
      const result = await browser.runtime.sendMessage({
        type: 'capture:submit',
        payload: buildPayload(raw, searchUrl),
      });
      if (!isGenerationActive(generation)) return;
      session.applyResult(identity, result);
    } catch {
      session.applyResult(identity, { ok: false, error: 'network' });
    }
  }

  /** Lote serial; callback concorrente apenas enfileira um rescan. */
  async function processNewCards(): Promise<void> {
    if (scanRunning) {
      rescanQueued = true;
      return;
    }
    scanRunning = true;
    const generation = lifecycleGeneration;
    try {
      if (!isGenerationActive(generation)) return;
      searchUrl = frozenSearchUrl();
      session.counters.phase = 'processing';

      const cards = Array.from(document.querySelectorAll('a[data-jk]')).filter(isCard);
      for (const el of cards) {
        if (!isGenerationActive(generation)) return;
        await processCard(el);
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
        void processNewCards();
      } else {
        rescanQueued = false;
      }
    }
  }

  function scheduleScan(): void {
    clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void processNewCards(), OBSERVER_DEBOUNCE_MS);
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

    await processNewCards();

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
