import type { CaptureMessage, CaptureResult } from '@/types/capture';
import { submitCapture } from '@/api/captures';
import { isCaptureReady } from '@/utils/capture-control';
import { lastApiStatus } from '@/utils/storage';

export default defineBackground(() => {
  // Único canal v1: content script → background (capture:submit).
  // O popup fala direto com o storage; sem tabs.sendMessage.
  browser.runtime.onMessage.addListener(
    (message: unknown): Promise<CaptureResult> | undefined => {
      if (!isCaptureMessage(message)) return undefined;

      return (async () => {
        // Gate central: flag explícita + token; bloqueia sem tocar a rede
        // nem gravar lastApiStatus. Mensagens estranhas seguem fora.
        if (!(await isCaptureReady())) return { ok: false, error: 'auth' };
        if (!message.payload || typeof message.payload !== 'object') {
          return { ok: false, error: 'invalid' };
        }
        const result = await submitCapture(message.payload);
        await lastApiStatus.setValue({
          ok: result.ok,
          duplicate: result.duplicate,
          error: result.error,
          status: result.status,
          at: new Date().toISOString(),
        });
        return result;
      })();
    },
  );
});

/** Type guard do canal tipado; mantém mensagens externas/estranhas fora. */
function isCaptureMessage(message: unknown): message is CaptureMessage {
  return (
    typeof message === 'object' &&
    message !== null &&
    (message as CaptureMessage).type === 'capture:submit'
  );
}
