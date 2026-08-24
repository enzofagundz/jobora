import { ContentScriptContext, defineContentScript } from '#imports';
import { isIndeedRoute } from '@/sites/indeed/extract';
import { isCaptureReady } from '@/utils/capture-control';
import { apiToken, captureEnabled } from '@/utils/storage';
import { createIndeedController } from '@/sites/indeed/controller';
import './panel.css';

export default defineContentScript({
  // Host inteiro para detectar navegação SPA dentro de *.indeed.com;
  // o filtro de rota real (/jobs) roda em runtime.
  matches: ['https://*.indeed.com/*'],
  cssInjectionMode: 'ui',

  async main(ctx: ContentScriptContext) {
    const controller = createIndeedController();
    let active = false;

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
      const shouldCapture = isIndeedRoute(location) && (await isCaptureReady());
      if (shouldCapture && !active) {
        active = true; // marca antes do await: impede ativação concorrente
        await controller.activate(ctx);
        // Rota/token/flag podem ter ficado inválidos durante o activate.
        if (!(isIndeedRoute(location) && (await isCaptureReady()))) {
          active = false;
          controller.deactivate();
        }
      } else if (!shouldCapture && active) {
        active = false;
        controller.deactivate();
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
