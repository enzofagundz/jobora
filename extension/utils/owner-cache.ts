import { storage } from 'wxt/utils/storage';

export interface CachedOwner {
  ownerUserId: number;
  validatedAt: number;
}

export interface OwnerCache {
  get(origin: string, credentialFingerprint: string): Promise<CachedOwner | null>;
  put(origin: string, credentialFingerprint: string, ownerUserId: number): Promise<void>;
}

const MAX_OWNERS = 100;
const OWNER_CACHE = storage.defineItem<Record<string, CachedOwner>>(
  'local:captureOwnerCache',
  { fallback: {} },
);

export function ownerCacheKey(origin: string, credentialFingerprint: string): string {
  return `${origin}|${credentialFingerprint}`;
}

export function createSessionOwnerCache(): OwnerCache {
  return {
    async get(origin, credentialFingerprint) {
      return (await OWNER_CACHE.getValue())[ownerCacheKey(origin, credentialFingerprint)] ?? null;
    },

    async put(origin, credentialFingerprint, ownerUserId) {
      const all = await OWNER_CACHE.getValue();
      const entries = Object.entries(all)
        .sort(([, a], [, b]) => a.validatedAt - b.validatedAt)
        .slice(-(MAX_OWNERS - 1));
      entries.push([
        ownerCacheKey(origin, credentialFingerprint),
        { ownerUserId, validatedAt: Date.now() },
      ]);
      await OWNER_CACHE.setValue(Object.fromEntries(entries));
    },
  };
}
