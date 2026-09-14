// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { panelPosition } from './storage';

describe('panelPosition', () => {
  beforeEach(() => {
    fakeBrowser.reset();
  });

  it('sem nada gravado começa no default bottom-right {0,0}', async () => {
    expect(await panelPosition.getValue()).toEqual({ dx: 0, dy: 0 });
  });

  it('persiste o offset entre leituras', async () => {
    await panelPosition.setValue({ dx: -120, dy: -80 });
    expect(await panelPosition.getValue()).toEqual({ dx: -120, dy: -80 });
  });
});
