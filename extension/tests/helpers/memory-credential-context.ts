import { credentialFingerprint } from '@/utils/capture-queue';
import type { CaptureSource, CaptureSurface } from '@/types/capture';
import type {
  CredentialContext,
  CredentialContextStore,
} from '@/utils/credential-context';

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
  await store.put(id, {
    origin: options.origin ?? 'http://localhost:8000',
    credentialFingerprint: await credentialFingerprint(options.token),
    ownerUserId: options.ownerUserId ?? 1,
    executionId: options.executionId ?? null,
    source: options.source ?? null,
    surface: options.surface ?? null,
    createdAt: Date.now(),
  });
  return id;
}
