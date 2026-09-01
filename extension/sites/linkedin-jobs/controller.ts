import { createApp, type App as VueApp } from 'vue';
import type { ContentScriptContext, ShadowRootContentScriptUi } from '#imports';
import CapturePanel from '@/components/CapturePanel.vue';
import { createCaptureSession, type CaptureSession } from '@/utils/capture-session';
import { externalCaptureIdentity } from '@/utils/capture-identity';
import {
  OBSERVER_DEBOUNCE_MS,
  buildJobsPayload,
  extractLinkedInJob,
  frozenSearchUrl,
  isJobCard,
  isLinkedInJobsRoute,
  jobIdFromCard,
  waitForJobsPane,
} from './extract';

export interface LinkedInJobsController {
  activate(ctx: ContentScriptContext): Promise<void>;
  deactivate(): void;
}

export function createLinkedInJobsController(): LinkedInJobsController {
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
  let lifecycleGeneration = 0;

  function isGenerationActive(generation: number): boolean {
    return (
      generation === lifecycleGeneration &&
      !!context &&
      !context.isInvalid &&
      isLinkedInJobsRoute(location)
    );
  }

  async function processCard(cardEl: Element): Promise<void> {
    if (session.isInvalidElement(cardEl)) return;

    const jobId = jobIdFromCard(cardEl);
    if (!jobId) {
      session.markInvalidElement(cardEl);
      session.counters.errors++;
      return;
    }

    const linkEl = (cardEl.querySelector('a.job-card-container__link') ??
      cardEl.querySelector('a[href*="/jobs/view/"]')) as HTMLAnchorElement | null;
    const cardTitle = linkEl?.getAttribute('aria-label')?.trim()
      ?? linkEl?.querySelector('strong')?.textContent?.trim()
      ?? linkEl?.textContent?.trim()
      ?? null;

    const identity = externalCaptureIdentity('linkedin', `linkedin:job:${jobId}`);
    if (!session.claim(identity)) return;

    const generation = lifecycleGeneration;
    try {
      (cardEl as HTMLElement).click();
    } catch {
      session.applyResult(identity, { ok: false, error: 'invalid' });
      return;
    }

    const paneOk = await waitForJobsPane(generation, isGenerationActive, jobId, cardTitle);
    if (!isGenerationActive(generation)) return;
    if (!paneOk) {
      session.applyResult(identity, { ok: false, error: 'network' });
      return;
    }

    const raw = await extractLinkedInJob(jobId, cardEl);
    if (!isGenerationActive(generation)) return;
    if (!raw || !raw.description) {
      session.applyResult(identity, { ok: false, error: 'invalid' });
      return;
    }

    try {
      session.counters.phase = 'sending';
      const result = await browser.runtime.sendMessage({
        type: 'capture:submit',
        payload: buildJobsPayload(raw, searchUrl),
      });
      if (!isGenerationActive(generation)) return;
      session.applyResult(identity, result);
    } catch {
      session.applyResult(identity, { ok: false, error: 'network' });
    }
  }

  async function processNewJobs(): Promise<void> {
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

      const candidates = new Set<Element>();
      for (const el of document.querySelectorAll('div[data-job-id]')) {
        if (isJobCard(el)) candidates.add(el);
      }
      for (const el of document.querySelectorAll('li[data-occludable-job-id]')) {
        if (isJobCard(el)) candidates.add(el);
      }
      for (const a of document.querySelectorAll('a[href*="/jobs/view/"]')) {
        const card = a.closest('li[data-occludable-job-id], div[data-job-id], div.job-card-container, li.scaffold-layout__list-item');
        if (card && isJobCard(card as Element)) candidates.add(card as Element);
        else if (isJobCard(a as unknown as Element)) candidates.add(a as unknown as Element);
      }
      // Fallback: varre tudo e filtra por isJobCard para href-only isolado
      if (candidates.size === 0) {
        for (const el of document.querySelectorAll('*')) {
          if (isJobCard(el as Element)) candidates.add(el as Element);
        }
      }
      const cards = Array.from(candidates);
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
      if (rescanQueued && isGenerationActive(lifecycleGeneration)) {
        rescanQueued = false;
        void processNewJobs();
      } else {
        rescanQueued = false;
      }
    }
  }

  function scheduleScan(): void {
    clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void processNewJobs(), OBSERVER_DEBOUNCE_MS);
  }

  async function activate(ctx: ContentScriptContext): Promise<void> {
    const generation = lifecycleGeneration;
    context = ctx;

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

    if (!isGenerationActive(generation)) {
      removeUi();
      return;
    }

    observer = new MutationObserver(scheduleScan);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributeFilter: ['href', 'data-job-id', 'data-occludable-job-id', 'aria-expanded', 'style'],
    });

    await processNewJobs();

    if (!isGenerationActive(generation)) return;

    const removeInvalidatedListener = ctx.onInvalidated(deactivate);
    removeInvalidatedListeners.push(removeInvalidatedListener);
  }

  const removeInvalidatedListeners: Array<() => void> = [];

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
  }

  return { activate, deactivate };
}
