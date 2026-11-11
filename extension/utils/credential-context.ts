import type { CaptureSource, CaptureSurface } from '@/types/capture';
import { storage } from 'wxt/utils/storage';

export interface CredentialContext {
  origin: string;
  credentialFingerprint: string;
  ownerUserId: number | null;
  executionId: string | null;
  source: CaptureSource | null;
  surface: CaptureSurface | null;
  createdAt: number;
}

export interface CredentialContextStore {
  put(id: string, context: CredentialContext): Promise<void>;
  get(id: string): Promise<CredentialContext | null>;
}

const MAX_CONTEXTS = 100;
const SESSION_CONTEXTS = storage.defineItem<Record<string, CredentialContext>>(
  'session:captureCredentialContexts',
  { fallback: {} },
);

// Storage de sessão é um único objeto: leitura-modificação-escrita concorrente
// (duas abas resolvendo execução ao mesmo tempo) perderia um contexto.
let writes: Promise<unknown> = Promise.resolve();

function serializeWrite<T>(operation: () => Promise<T>): Promise<T> {
  const result = writes.then(operation, operation);
  writes = result.catch(() => undefined);

  return result;
}

/** Contexto opaco por execução/credencial; nunca contém token. */
export function createSessionCredentialContextStore(): CredentialContextStore {
  return {
    put(id, context) {
      return serializeWrite(async () => {
        const all = await SESSION_CONTEXTS.getValue();
        const entries = Object.entries(all)
          .sort(([, a], [, b]) => a.createdAt - b.createdAt)
          .slice(-(MAX_CONTEXTS - 1));
        entries.push([id, context]);
        await SESSION_CONTEXTS.setValue(Object.fromEntries(entries));
      });
    },

    async get(id) {
      return (await SESSION_CONTEXTS.getValue())[id] ?? null;
    },
  };
}

export function newCredentialContextId(): string {
  return crypto.randomUUID();
}
