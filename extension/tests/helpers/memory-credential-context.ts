import { credentialFingerprint } from '@/utils/capture-queue';
import { createCaptureMessageHandler, type CaptureSender } from '@/api/capture-message-handler';
import type { CaptureSource, CaptureSurface } from '@/types/capture';
import type {
  CredentialContext,
  CredentialContextStore,
} from '@/utils/credential-context';

export const CAPTURE_SENDER: CaptureSender = { tab: { id: 1 }, frameId: 0 };

export function createTestCaptureMessageHandler(...args: Parameters<typeof createCaptureMessageHandler>) {
  const handler = createCaptureMessageHandler(...args);
  return Object.assign((message: unknown, sender: CaptureSender = CAPTURE_SENDER) => handler(message, sender), { flush: handler.flush, tabClosed: handler.tabClosed });
}

export function createMemoryCredentialContextStore(): CredentialContextStore & {
  contexts: Map<string, CredentialContext>;
} {
  const contexts = new Map<string, CredentialContext>();
  return {
    contexts,
    async put(id, context) {
      contexts.set(id, structuredClone(context));
    },
    async get(id) {
      return contexts.get(id) ?? null;
    },
    async putIfRevision(id, revision, context) {
      if (!contexts.has(id) || contexts.get(id)!.revision !== revision) return false;
      contexts.set(id, structuredClone(context));
      return true;
    },
    async removeTab(tabId) {
      for (const [id, context] of contexts) if (context.senderKey?.startsWith(`${tabId}:`)) contexts.delete(id);
    },
  };
}

export async function seedCredentialContext(
  store: CredentialContextStore,
  options: {
    id?: string;
    token: string;
    origin?: string;
    executionId?: string | null;
    source?: CaptureSource | null;
    surface?: CaptureSurface | null;
    ownerUserId?: number | null;
  },
): Promise<string> {
  const id = options.id ?? `ctx-${Math.random().toString(36).slice(2)}`;
  const fingerprint = await credentialFingerprint(options.token);
  const scopeKey = JSON.stringify(['capture-scope', '1:0', options.origin ?? 'http://localhost:8000', fingerprint, options.ownerUserId ?? 1]);
  const binding: CredentialContext = {
    origin: options.origin ?? 'http://localhost:8000',
    credentialFingerprint: fingerprint,
    ownerUserId: options.ownerUserId ?? 1,
    executionId: options.executionId ?? null,
    source: options.source ?? null,
    surface: options.surface ?? null,
    createdAt: Date.now(),
    senderKey: '1:0', scopeKey,
  };
  await store.put(id, binding);
  await store.put(scopeKey, { ...binding, contextId: id });
  return id;
}
