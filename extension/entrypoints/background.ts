import {
  createCaptureMessageHandler,
  type CaptureMessageResult,
} from '@/api/capture-message-handler';
import { createCaptureQueue } from '@/utils/capture-queue';
import { createIndexedDbQueueStore } from '@/utils/capture-queue-idb';
import { createSessionCredentialContextStore } from '@/utils/credential-context';
import { createAlarmQueueWake } from '@/utils/capture-queue-wake';
import { createSessionOwnerCache } from '@/utils/owner-cache';
import { apiBaseUrl, apiToken, captureEnabled } from '@/utils/storage';

export default defineBackground(() => {
  const queue = createCaptureQueue(createIndexedDbQueueStore());
  let handler: ReturnType<typeof createCaptureMessageHandler> | undefined;
  const wake = createAlarmQueueWake(() => void handler?.flush());
  handler = createCaptureMessageHandler(
    queue,
    createSessionCredentialContextStore(),
    wake,
    createSessionOwnerCache(),
  );

  // Único canal content → background. O token só existe no storage/background.
  browser.runtime.onMessage.addListener(
    (message: unknown): Promise<CaptureMessageResult | undefined> | undefined =>
      handler(message),
  );

  // Restart do service worker ou troca de destino/credencial retomam a fila.
  void handler.flush();
  apiBaseUrl.watch(() => void handler.flush());
  apiToken.watch(() => void handler.flush());
  captureEnabled.watch(() => void handler.flush());
});
