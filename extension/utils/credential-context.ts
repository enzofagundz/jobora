import type { CaptureSource, CaptureSurface, EnvelopeResult } from '@/types/capture';
import { storage } from 'wxt/utils/storage';

export interface CredentialContext {
  origin: string;
  credentialFingerprint: string;
  ownerUserId: number | null;
  executionId: string | null;
  source: CaptureSource | null;
  surface: CaptureSurface | null;
  createdAt: number;
  senderKey?: string;
  scopeKey?: string;
  contextId?: string;
  revision?: string;
  observedUrl?: string;
  expiresAt?: string | null;
  acceptedPayloadHash?: string;
  receipt?: EnvelopeResult;
  blocked?: boolean;
}

export interface CredentialContextStore {
  put(id: string, context: CredentialContext): Promise<void>;
  get(id: string): Promise<CredentialContext | null>;
  putIfRevision(id: string, revision: string | undefined, context: CredentialContext): Promise<boolean>;
  removeTab(tabId: number): Promise<void>;
}

const MAX_CONTEXTS = 100;
let writes: Promise<void> = Promise.resolve();
const SESSION_CONTEXTS = storage.defineItem<Record<string, CredentialContext>>(
  'session:captureCredentialContexts',
  { fallback: {} },
);

/** Contexto opaco por execução/credencial; nunca contém token. */
export function createSessionCredentialContextStore(): CredentialContextStore {
  return {
    async put(id, context) {
      const write = writes.then(async () => {
        const all = await SESSION_CONTEXTS.getValue();
        if (!(id in all) && Object.keys(all).length >= MAX_CONTEXTS) {
          throw new Error('Capture context capacity reached');
        }
        await SESSION_CONTEXTS.setValue({ ...all, [id]: context });
      });
      writes = write.catch(() => {});
      await write;
    },

    async get(id) {
      await writes;
      return (await SESSION_CONTEXTS.getValue())[id] ?? null;
    },
    async putIfRevision(id, revision, context) {
      let replaced = false;
      const write = writes.then(async () => {
        const all = await SESSION_CONTEXTS.getValue();
        if (!all[id] || all[id].revision !== revision) return;
        await SESSION_CONTEXTS.setValue({ ...all, [id]: context });
        replaced = true;
      });
      writes = write.catch(() => {});
      await write;
      return replaced;
    },
    async removeTab(tabId) {
      const write = writes.then(async () => {
        const all = await SESSION_CONTEXTS.getValue();
        await SESSION_CONTEXTS.setValue(Object.fromEntries(Object.entries(all)
          .filter(([, context]) => !context.senderKey?.startsWith(`${tabId}:`))));
      });
      writes = write.catch(() => {});
      await write;
    },
  };
}

export function newCredentialContextId(): string {
  return crypto.randomUUID();
}
