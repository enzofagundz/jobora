import { storage } from 'wxt/utils/storage';

/**
 * Config persistente (local:) e estado temporário (session:).
 * Counters/WeakSet/Set de deduplicação NÃO entram aqui — vivem na memória
 * do content script; o backend é a fonte da verdade de duplicidade.
 */
export const apiBaseUrl = storage.defineItem<string>('local:apiBaseUrl', {
  fallback: 'https://vagas.test',
});

export const apiToken = storage.defineItem<string>('local:apiToken', {
  fallback: '',
});

export const captureEnabled = storage.defineItem<boolean>('local:captureEnabled', {
  fallback: false,
});

export interface LastApiStatus {
  ok: boolean;
  duplicate?: boolean;
  error?: string;
  status?: number;
  at: string;
}

export const lastApiStatus = storage.defineItem<LastApiStatus | null>('session:lastApiStatus', {
  fallback: null,
});

export interface PanelPosition {
  dx: number;
  dy: number;
}

/** Offset de arrasto do painel de captura (global aos três sites). */
export const panelPosition = storage.defineItem<PanelPosition>('local:panelPosition', {
  fallback: { dx: 0, dy: 0 },
});
