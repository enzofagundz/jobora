export const CAPTURE_QUEUE_ALARM = 'jobora-capture-queue-retry';

export interface CaptureQueueWake {
  schedule(nextAttemptAt: number | null): void;
}

/**
 * Acorda a fila via alarme nativo do navegador. Timers do service worker MV3
 * morrem na suspensão; o alarme persiste e dispara `flush` no background.
 */
export function createAlarmQueueWake(onWake: () => void): CaptureQueueWake {
  listenQueueAlarm(onWake);
  return {
    schedule(nextAttemptAt) {
      if (nextAttemptAt === null) {
        void browser.alarms.clear(CAPTURE_QUEUE_ALARM);
        return;
      }
      browser.alarms.create(CAPTURE_QUEUE_ALARM, {
        when: Math.max(Date.now() + 1_000, nextAttemptAt),
      });
    },
  };
}

export function listenQueueAlarm(onWake: () => void): void {
  browser.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === CAPTURE_QUEUE_ALARM) void onWake();
  });
}
