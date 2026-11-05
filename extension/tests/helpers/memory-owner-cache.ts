import type { CachedOwner, OwnerCache } from '@/utils/owner-cache';
import { ownerCacheKey } from '@/utils/owner-cache';

export function createMemoryOwnerCache(): OwnerCache & {
  entries: Map<string, CachedOwner>;
} {
  const entries = new Map<string, CachedOwner>();
  return {
    entries,
    async get(origin, credentialFingerprint) {
      return entries.get(ownerCacheKey(origin, credentialFingerprint)) ?? null;
    },
    async put(origin, credentialFingerprint, ownerUserId) {
      entries.set(ownerCacheKey(origin, credentialFingerprint), {
        ownerUserId,
        validatedAt: Date.now(),
      });
    },
  };
}
