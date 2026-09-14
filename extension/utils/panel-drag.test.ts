// @vitest-environment happy-dom
import { describe, expect, it, vi } from 'vitest';
import {
  clampOffset,
  createPanelDrag,
  isPastThreshold,
  type PanelGeometry,
} from './panel-drag';

const GEOMETRY: PanelGeometry = {
  panel: { width: 200, height: 100 },
  viewport: { width: 1000, height: 800 },
};

function makeDom() {
  const panel = document.createElement('div');
  panel.className = 'vagas-panel';
  const handle = document.createElement('div');
  handle.className = 'vagas-title-row';
  const close = document.createElement('button');
  close.className = 'vagas-close';
  handle.appendChild(close);
  panel.appendChild(handle);
  document.body.appendChild(panel);
  return { panel, handle, close };
}

function fire(
  type: string,
  target: Element,
  x: number,
  y: number,
  init: PointerEventInit = {},
): void {
  target.dispatchEvent(
    new PointerEvent(type, {
      bubbles: true,
      cancelable: true,
      composed: true,
      button: 0,
      pointerId: 1,
      pointerType: 'mouse',
      clientX: x,
      clientY: y,
      ...init,
    }),
  );
}

describe('isPastThreshold', () => {
  it('movimento abaixo de 4px não conta como drag', () => {
    expect(isPastThreshold({ x: 0, y: 0 }, { x: 3, y: 0 })).toBe(false);
  });

  it('a partir de 4px conta como drag, inclusive na diagonal', () => {
    expect(isPastThreshold({ x: 0, y: 0 }, { x: 4, y: 0 })).toBe(true);
    expect(isPastThreshold({ x: 0, y: 0 }, { x: 3, y: 3 })).toBe(true);
  });

  it('respeita threshold customizado', () => {
    expect(isPastThreshold({ x: 0, y: 0 }, { x: 5, y: 0 }, 10)).toBe(false);
  });
});

describe('clampOffset', () => {
  const geometry: PanelGeometry = {
    panel: { width: 200, height: 100 },
    viewport: { width: 1000, height: 800 },
  };

  it('offset dentro da viewport é preservado', () => {
    expect(clampOffset({ dx: -50, dy: -20 }, geometry)).toEqual({ dx: -50, dy: -20 });
  });

  it('não deixa passar da borda direita/inferior (inset de 12px do default)', () => {
    expect(clampOffset({ dx: 999, dy: 999 }, geometry)).toEqual({ dx: 12, dy: 12 });
  });

  it('não deixa passar da borda esquerda/superior mantendo o painel inteiro visível', () => {
    // dx min = 200 + 12 - 1000 = -788; dy min = 100 + 12 - 800 = -688
    expect(clampOffset({ dx: -9999, dy: -9999 }, geometry)).toEqual({ dx: -788, dy: -688 });
  });
});

describe('createPanelDrag', () => {
  it('pointerdown no botão fechar nunca inicia drag', () => {
    const { panel, handle, close } = makeDom();
    const onCommit = vi.fn();
    createPanelDrag({ panel, handle, measure: () => GEOMETRY, onCommit });

    fire('pointerdown', close, 100, 100);
    fire('pointermove', handle, 200, 200);
    fire('pointerup', handle, 200, 200);

    expect(panel.style.transform).toBe('');
    expect(onCommit).not.toHaveBeenCalled();
  });

  it('movimento abaixo do threshold não move nem persiste', () => {
    const { panel, handle } = makeDom();
    const onCommit = vi.fn();
    createPanelDrag({ panel, handle, measure: () => GEOMETRY, onCommit });

    fire('pointerdown', handle, 100, 100);
    fire('pointermove', handle, 103, 100);
    fire('pointerup', handle, 103, 100);

    expect(panel.style.transform).toBe('');
    expect(onCommit).not.toHaveBeenCalled();
  });

  it('arrastar move o painel e só persiste no pointerup', () => {
    const { panel, handle } = makeDom();
    const onCommit = vi.fn();
    createPanelDrag({ panel, handle, measure: () => GEOMETRY, onCommit });

    fire('pointerdown', handle, 100, 100);
    fire('pointermove', handle, 50, 60);

    expect(panel.style.transform).toBe('translate(-50px, -40px)');
    expect(onCommit).not.toHaveBeenCalled();

    fire('pointerup', handle, 50, 60);
    expect(onCommit).toHaveBeenCalledWith({ dx: -50, dy: -40 });
  });

  it('clamp segura o painel na viewport durante o arrasto', () => {
    const { panel, handle } = makeDom();
    const narrow: PanelGeometry = {
      panel: { width: 200, height: 100 },
      viewport: { width: 320, height: 200 },
    };
    createPanelDrag({ panel, handle, measure: () => narrow, onCommit: vi.fn() });

    fire('pointerdown', handle, 100, 100);
    fire('pointermove', handle, -200, -300);

    // dx min = 200 + 12 - 320 = -108; dy min = 100 + 12 - 200 = -88
    expect(panel.style.transform).toBe('translate(-108px, -88px)');
  });

  it('resize re-clampa a posição atual', () => {
    const { panel, handle } = makeDom();
    let geometry = GEOMETRY;
    const drag = createPanelDrag({ panel, handle, measure: () => geometry, onCommit: vi.fn() });

    drag.setOffset({ dx: -500, dy: -600 });
    expect(panel.style.transform).toBe('translate(-500px, -600px)');

    geometry = { panel: { width: 200, height: 100 }, viewport: { width: 400, height: 300 } };
    window.dispatchEvent(new Event('resize'));

    // dx min = 200 + 12 - 400 = -188; dy min = 100 + 12 - 300 = -188
    expect(panel.style.transform).toBe('translate(-188px, -188px)');
  });

  it('destroy remove o listener de resize e encerra o drag', () => {
    const { panel, handle } = makeDom();
    const drag = createPanelDrag({ panel, handle, measure: () => GEOMETRY, onCommit: vi.fn() });

    drag.setOffset({ dx: -50, dy: -40 });
    drag.destroy();
    window.dispatchEvent(new Event('resize'));

    expect(panel.style.transform).toBe('translate(-50px, -40px)');
  });
});
