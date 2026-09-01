import { ContentScriptContext, defineContentScript } from '#imports';
import { isLinkedInRoute } from '@/sites/linkedin/extract';
import { isLinkedInJobsRoute } from '@/sites/linkedin-jobs/extract';
import { isCaptureReady } from '@/utils/capture-control';
import { apiToken, captureEnabled } from '@/utils/storage';
import { createLinkedInController } from '@/sites/linkedin/controller';
import { createLinkedInJobsController } from '@/sites/linkedin-jobs/controller';
import './panel.css';

export default defineContentScript({
  // Host inteiro para detectar entrada/saída da rota SPA; filtro real em runtime.
  matches: ['https://www.linkedin.com/*'],
  cssInjectionMode: 'ui',

  async main(ctx: ContentScriptContext) {
    const publicationsController = createLinkedInController();
    const jobsController = createLinkedInJobsController();
    let activePublications = false;
    let activeJobs = false;

    // Serializa sincronizações: mudanças de rota/storage durante uma leitura
    // apenas enfileiram a reavaliação; nenhuma transição concorrente.
    let syncing = false;
    let syncQueued = false;
    async function requestSync(): Promise<void> {
      if (syncing) {
        syncQueued = true;
        return;
      }
      syncing = true;
      try {
        do {
          syncQueued = false;
          await syncRoute();
        } while (syncQueued);
      } finally {
        syncing = false;
      }
    }

    async function syncRoute(): Promise<void> {
      const ready = await isCaptureReady();
      const isPublicationsRoute = isLinkedInRoute(location);
      const isJobsRoute = isLinkedInJobsRoute(location);

      const shouldCapturePublications = isPublicationsRoute && ready;
      const shouldCaptureJobs = isJobsRoute && ready;

      if (shouldCapturePublications && !activePublications) {
        activePublications = true;
        await publicationsController.activate(ctx);
        if (!(isLinkedInRoute(location) && (await isCaptureReady()))) {
          activePublications = false;
          publicationsController.deactivate();
        }
      } else if (!shouldCapturePublications && activePublications) {
        activePublications = false;
        publicationsController.deactivate();
      }

      if (shouldCaptureJobs && !activeJobs) {
        activeJobs = true;
        await jobsController.activate(ctx);
        if (!(isLinkedInJobsRoute(location) && (await isCaptureReady()))) {
          activeJobs = false;
          jobsController.deactivate();
        }
      } else if (!shouldCaptureJobs && activeJobs) {
        activeJobs = false;
        jobsController.deactivate();
      }
    }

    const unwatchEnabled = captureEnabled.watch(() => void requestSync());
    const unwatchToken = apiToken.watch(() => void requestSync());
    ctx.onInvalidated(() => {
      unwatchEnabled();
      unwatchToken();
    });
    ctx.addEventListener(window, 'wxt:locationchange', () => void requestSync());
    await requestSync();
  },
});
