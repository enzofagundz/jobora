import { reactive } from 'vue';
import type { CaptureResult } from '@/types/capture';

export type CapturePhase = 'waiting' | 'processing' | 'sending' | 'completed' | 'error';
export type CaptureOutcome = 'created' | 'duplicate' | 'error' | null;

export interface CaptureCounters {
  found: number;
  processed: number;
  sent: number;
  duplicates: number;
  errors: number;
  phase: CapturePhase;
  lastOutcome: CaptureOutcome;
}

export interface CaptureSession {
  counters: CaptureCounters;
  /** Identidades com resultado terminal (201/200/erro) — nunca reenviadas na sessão. */
  claimedIds: Set<string>;
  /**
   * Registra a identidade ANTES da rede. Retorna false para clone, re-render,
   * scroll ou reentrada SPA já vista — nesses casos nada é enviado nem contado.
   */
  claim(identity: string): boolean;
  /** Só aceita identidade reivindicada; `processed` incrementa exatamente uma vez. */
  applyResult(identity: string, result: CaptureResult): void;
  /** Falha de captura sem payload/identidade útil (elemento inválido). */
  markInvalidElement(el: Element): void;
  isInvalidElement(el: Element): boolean;
}

/**
 * Estado de sessão por aba, em memória. `reactive()` é obrigatório: os
 * controllers mutam os contadores de forma assíncrona e o painel Vue precisa
 * refletir as mudanças sem remontagem.
 */
export function createCaptureSession(): CaptureSession {
  const claimed = new Set<string>();
  // WeakSet só para candidatos realmente inválidos — nunca é identidade de captura válida.
  const invalidElements = new WeakSet<Element>();

  const counters = reactive<CaptureCounters>({
    found: 0,
    processed: 0,
    sent: 0,
    duplicates: 0,
    errors: 0,
    phase: 'waiting',
    lastOutcome: null,
  });

  // Identidades com resultado terminal (201/200/erro): nunca mais reivindicadas.
  const settled = new Set<string>();
  function claim(identity: string): boolean {
    if (claimed.has(identity) || settled.has(identity)) return false;
    claimed.add(identity);
    counters.found++;
    return true;
  }

  function applyResult(identity: string, result: CaptureResult): void {
    if (!claimed.has(identity)) return;
    claimed.delete(identity);
    settled.add(identity);
    counters.processed++;
    if (result.ok) {
      if (result.duplicate) counters.duplicates++;
      else counters.sent++;
      counters.lastOutcome = result.duplicate ? 'duplicate' : 'created';
    } else {
      counters.errors++;
      counters.lastOutcome = 'error';
    }
  }

  return {
    counters,
    claimedIds: claimed,
    claim,
    applyResult,
    markInvalidElement: (el: Element) => invalidElements.add(el),
    isInvalidElement: (el: Element) => invalidElements.has(el),
  };
}
