// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { apiToken, captureEnabled } from '@/utils/storage';
import { hasConfiguredToken, isCaptureReady } from './capture-control';

describe('hasConfiguredToken', () => {
  it('vazio ou só espaços não conta como configurado', () => {
    expect(hasConfiguredToken('')).toBe(false);
    expect(hasConfiguredToken('   ')).toBe(false);
    expect(hasConfiguredToken(' tok ')).toBe(true);
  });
});

describe('isCaptureReady', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  it.each([
    // enabled | token          | pronto
    [false, '', false],
    [false, 'tok', false],
    [true, '', false],
    [true, '   ', false],
    [true, 'tok', true],
  ])('flag=%j token=%j → %j', async (enabled, token, ready) => {
    await captureEnabled.setValue(enabled);
    await apiToken.setValue(token);
    expect(await isCaptureReady()).toBe(ready);
  });

  it('padrão sem nada gravado é desligado', async () => {
    expect(await isCaptureReady()).toBe(false);
  });

  it('flag persiste no storage local', async () => {
    await captureEnabled.setValue(true);
    expect(await captureEnabled.getValue()).toBe(true);
  });
});
