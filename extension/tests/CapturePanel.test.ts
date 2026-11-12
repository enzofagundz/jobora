// @vitest-environment happy-dom
import { createApp, nextTick, ref } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
import CapturePanel from '@/components/CapturePanel.vue';

describe('CapturePanel reservation alert', () => {
  let app: ReturnType<typeof createApp> | undefined;

  afterEach(() => {
    app?.unmount();
    app = undefined;
    document.body.innerHTML = '';
  });

  it('shows an accessible blocked-reservation message and clears it when state recovers', async () => {
    const error = ref<'reservation_changed' | null>('reservation_changed');
    const container = document.createElement('div');
    document.body.append(container);
    app = createApp(CapturePanel, {
      siteLabel: 'Jobora Job Capture',
      counters: { found: 1, sent: 0, duplicates: 0, errors: 0 },
      error,
    });

    app.mount(container);
    await nextTick();

    const alert = container.querySelector('[role="alert"]');
    expect(alert?.textContent?.trim()).toBe(
      'Captura bloqueada para preservar a reserva. Verifique a pesquisa e a validade da execução.',
    );
    expect(alert?.getAttribute('aria-live')).toBe('assertive');
    expect(container.querySelector('.vagas-counters')?.textContent).toContain('1');

    error.value = null;
    await nextTick();
    expect(container.querySelector('[role="alert"]')).toBeNull();
  });

  it('keeps other panels unchanged when no reservation error is supplied', async () => {
    const container = document.createElement('div');
    document.body.append(container);
    app = createApp(CapturePanel, {
      siteLabel: 'Jobora Job Capture',
      counters: { found: 0, sent: 0, duplicates: 0, errors: 0 },
    });

    app.mount(container);
    await nextTick();

    expect(container.querySelector('[role="alert"]')).toBeNull();
  });
});
