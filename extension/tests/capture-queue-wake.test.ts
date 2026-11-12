// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import {
  CAPTURE_QUEUE_ALARM,
  createAlarmQueueWake,
} from '@/utils/capture-queue-wake';

describe('wake durável da fila', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  it('agenda alarme nativo e o remove quando não há próximo retry', async () => {
    const onWake = vi.fn();
    const wake = createAlarmQueueWake(onWake);
    const when = Date.now() + 5_000;

    wake.schedule(when);

    const alarm = await browser.alarms.get(CAPTURE_QUEUE_ALARM);
    expect(alarm?.scheduledTime).toBeGreaterThanOrEqual(when);

    wake.schedule(null);
    expect(await browser.alarms.get(CAPTURE_QUEUE_ALARM)).toBeUndefined();
  });
});
