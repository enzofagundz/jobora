/**
 * Drag do painel de captura (Shadow Root). Math pura + controller de DOM
 * separados para permitir teste comportamental sem componente Vue.
 */

export interface PanelOffset {
  dx: number;
  dy: number;
}

export interface Size {
  width: number;
  height: number;
}

export interface PanelGeometry {
  panel: Size;
  viewport: Size;
}

/** Movimento mínimo (px) para um pointerdown virar drag em vez de clique. */
export const DRAG_THRESHOLD_PX = 4;

/** Inset do default (right/bottom) definido em CapturePanel.vue; px, não rem. */
export const PANEL_INSET_PX = 12;

/** Distância euclidiana entre o ponto inicial e o atual atinge o threshold. */
export function isPastThreshold(
  start: { x: number; y: number },
  current: { x: number; y: number },
  threshold: number = DRAG_THRESHOLD_PX,
): boolean {
  return Math.hypot(current.x - start.x, current.y - start.y) >= threshold;
}

/**
 * Limita o offset para o painel inteiro permanecer na viewport.
 * O default bottom-right deixa margem de PANEL_INSET_PX, logo o máximo
 * avanço para a direita/baixo é esse inset; para a esquerda/cima o limite
 * é o próprio tamanho do painel.
 */
export function clampOffset(offset: PanelOffset, geometry: PanelGeometry): PanelOffset {
  const [minDx, maxDx] = axisRange(geometry.panel.width, geometry.viewport.width);
  const [minDy, maxDy] = axisRange(geometry.panel.height, geometry.viewport.height);
  return {
    dx: Math.min(Math.max(offset.dx, minDx), maxDx),
    dy: Math.min(Math.max(offset.dy, minDy), maxDy),
  };
}

function axisRange(panelSize: number, viewportSize: number): [number, number] {
  const max = PANEL_INSET_PX;
  const min = panelSize + PANEL_INSET_PX - viewportSize;
  // Painel maior que a viewport: sem faixa válida, mantém a borda direita/inferior.
  return min <= max ? [min, max] : [max, max];
}

export interface PanelDragOptions {
  panel: HTMLElement;
  handle: HTMLElement;
  /** Chamado apenas no fim de um drag real (threshold vencido). */
  onCommit?: (offset: PanelOffset) => void;
  /** Injetável nos testes; por padrão mede o painel/viewport reais. */
  measure?: () => PanelGeometry;
  threshold?: number;
}

export interface PanelDrag {
  /** Posição inicial persistida; aplica clamp antes de renderizar. */
  setOffset(offset: PanelOffset): void;
  reclamp(): void;
  destroy(): void;
}

const DRAGGING_CLASS = 'vagas-panel--dragging';

/** Liga os Pointer Events do handle ao transform do painel. */
export function createPanelDrag(options: PanelDragOptions): PanelDrag {
  const { panel, handle, onCommit } = options;
  const threshold = options.threshold ?? DRAG_THRESHOLD_PX;
  const measure = options.measure ?? measureFromDom(panel);

  let offset: PanelOffset = { dx: 0, dy: 0 };
  let start: { x: number; y: number } | null = null;
  let startOffset: PanelOffset = { dx: 0, dy: 0 };
  let dragging = false;
  let activePointerId: number | null = null;

  function apply(next: PanelOffset): void {
    offset = next;
    panel.style.transform = `translate(${next.dx}px, ${next.dy}px)`;
  }

  function setOffset(next: PanelOffset): void {
    apply(clampOffset(next, measure()));
  }

  function reclamp(): void {
    apply(clampOffset(offset, measure()));
  }

  function onPointerDown(event: PointerEvent): void {
    if (event.button !== 0) return;
    const target = event.target;
    if (target instanceof Element && target.closest('.vagas-close')) return;
    start = { x: event.clientX, y: event.clientY };
    startOffset = offset;
    dragging = false;
    activePointerId = event.pointerId;
    try {
      handle.setPointerCapture(event.pointerId);
    } catch {
      // Pointer já inativo (ex.: ambiente de teste): drag segue sem capture.
    }
  }

  function onPointerMove(event: PointerEvent): void {
    if (!start || event.pointerId !== activePointerId) return;
    const current = { x: event.clientX, y: event.clientY };
    if (!dragging) {
      if (!isPastThreshold(start, current, threshold)) return;
      dragging = true;
      panel.classList.add(DRAGGING_CLASS);
    }
    setOffset({
      dx: startOffset.dx + (current.x - start.x),
      dy: startOffset.dy + (current.y - start.y),
    });
  }

  function finish(event: PointerEvent): void {
    if (!start || event.pointerId !== activePointerId) return;
    if (dragging) onCommit?.(offset);
    dragging = false;
    start = null;
    activePointerId = null;
    panel.classList.remove(DRAGGING_CLASS);
    try {
      handle.releasePointerCapture(event.pointerId);
    } catch {
      // Sem capture ativo: nada a liberar.
    }
  }

  handle.addEventListener('pointerdown', onPointerDown);
  handle.addEventListener('pointermove', onPointerMove);
  handle.addEventListener('pointerup', finish);
  handle.addEventListener('pointercancel', finish);
  window.addEventListener('resize', reclamp);

  return {
    setOffset,
    reclamp,
    destroy() {
      handle.removeEventListener('pointerdown', onPointerDown);
      handle.removeEventListener('pointermove', onPointerMove);
      handle.removeEventListener('pointerup', finish);
      handle.removeEventListener('pointercancel', finish);
      window.removeEventListener('resize', reclamp);
      panel.classList.remove(DRAGGING_CLASS);
      start = null;
      activePointerId = null;
    },
  };
}

function measureFromDom(panel: HTMLElement): () => PanelGeometry {
  return () => {
    const rect = panel.getBoundingClientRect();
    return {
      panel: { width: rect.width, height: rect.height },
      viewport: { width: window.innerWidth, height: window.innerHeight },
    };
  };
}

