import { permalinkFromExternalId } from './envelope';

const MAX_HYDRATION_CHARS = 4 * 1024 * 1024;
const MAX_LINES = 5000;
const MAX_IDENTITIES = 500;
const IDENTITY_TTL_MS = 30 * 60_000;
const SCRIPT_ID = 'rehydrate-data';

export interface HydratedIdentity {
  activityId: string;
  permalink: string;
}

export interface HydrationIdentityCollector {
  resolve(componentKey: string): HydratedIdentity | null;
  reset(): void;
  dispose(): void;
}

interface StoredIdentity {
  activityId: string;
  expiresAt: number;
}

/** Parser inerte do array RSC; nunca executa JS da página. */
export function parseHydrationIdentities(text: string): Map<string, string> {
  const marker = text.indexOf('window.__como_rehydration__');
  const start = text.indexOf('[', marker >= 0 ? marker : 0);
  const end = text.lastIndexOf(']');
  if (start < 0 || end <= start) return new Map();

  let parsed: unknown;
  try {
    parsed = JSON.parse(text.slice(start, end + 1));
  } catch {
    return new Map();
  }
  if (!Array.isArray(parsed)) return new Map();

  const byKey = new Map<string, string>();
  for (const entry of parsed) {
    if (typeof entry !== 'string') continue;
    const match = entry.match(/^([0-9a-f]+):/);
    if (match?.[1] && byKey.size < MAX_LINES) byKey.set(match[1], entry);
  }

  const result = new Map<string, string>();
  for (const line of byKey.values()) {
    for (const postMatch of line.matchAll(/"role":"listitem"/g)) {
      const window = line.slice(postMatch.index, postMatch.index + 500);
      const componentKey = window.match(/"componentkey":"([^"]+)"/)?.[1];
      if (!componentKey || result.has(componentKey)) continue;

      const refs = new Set<string>();
      for (const ref of line.matchAll(/\$L?([0-9a-f]+)\b/g)) {
        if (refs.size >= 200) break;
        if (ref[1]) refs.add(ref[1]);
      }

      const candidates = new Set<string>();
      for (const ref of refs) {
        const target = byKey.get(ref);
        if (!target || !target.includes('feedUpdateUrn')) continue;
        const ids = new Set(
          Array.from(target.matchAll(/"activityId":"(\d{19})"/g), (match) => match[1]!),
        );
        if (ids.size === 1) candidates.add(Array.from(ids)[0]!);
      }
      if (candidates.size !== 1) continue;

      const activityId = Array.from(candidates)[0]!;
      const sameThread = Array.from(refs).some((ref) => {
        const target = byKey.get(ref);
        return target !== undefined && relatedActivityIds(target).has(activityId);
      });
      if (sameThread) result.set(componentKey, activityId);
    }
  }

  return result;
}

function relatedActivityIds(line: string): Set<string> {
  const ids = new Set<string>();
  for (const match of line.matchAll(
    /"threadUrn":\{"threadUrnActivityThreadUrn":\{"activityUrn":\{"activityId":"(\d{19})"/g,
  )) {
    if (match[1]) ids.add(match[1]);
  }
  for (const match of line.matchAll(/"rootUrn":"urn:li:(?:activity|share):(\d{19})"/g)) {
    if (match[1]) ids.add(match[1]);
  }
  return ids;
}

export function createHydrationIdentityCollector(options?: {
  now?: () => number;
  maxChars?: number;
  ttlMs?: number;
}): HydrationIdentityCollector {
  const now = options?.now ?? Date.now;
  const maxChars = options?.maxChars ?? MAX_HYDRATION_CHARS;
  const ttlMs = options?.ttlMs ?? IDENTITY_TTL_MS;
  const identities = new Map<string, StoredIdentity>();
  const conflicts = new Set<string>();
  let observer: MutationObserver | null = null;
  let disposed = false;

  function ingest(text: string): void {
    if (disposed || text.length === 0 || text.length > maxChars) return;
    const parsed = parseHydrationIdentities(text);
    const expiresAt = now() + ttlMs;
    for (const [componentKey, activityId] of parsed) {
      if (conflicts.has(componentKey)) continue;
      const existing = identities.get(componentKey);
      if (existing && existing.activityId !== activityId) {
        identities.delete(componentKey);
        conflicts.add(componentKey);
        continue;
      }
      identities.set(componentKey, { activityId, expiresAt });
    }
    while (identities.size > MAX_IDENTITIES) {
      const oldest = identities.keys().next().value;
      if (!oldest) break;
      identities.delete(oldest);
    }
  }

  function scan(node: Node): void {
    if (node instanceof Element && node.id === SCRIPT_ID) {
      ingest(node.textContent ?? '');
    }
    if (!(node instanceof Element) && node.nodeType !== Node.DOCUMENT_NODE && node.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
      return;
    }
    const scripts = (node as ParentNode).querySelectorAll?.(`script#${SCRIPT_ID}`) ?? [];
    for (const script of scripts) ingest(script.textContent ?? '');
  }

  observer = new MutationObserver((records) => {
    for (const record of records) {
      for (const node of Array.from(record.addedNodes)) scan(node);
      for (const node of Array.from(record.removedNodes)) scan(node);
    }
  });
  observer.observe(document, { childList: true, subtree: true });
  scan(document);

  return {
    resolve(componentKey) {
      const stored = identities.get(componentKey);
      if (!stored) return null;
      if (stored.expiresAt <= now()) {
        identities.delete(componentKey);
        return null;
      }
      const permalink = permalinkFromExternalId(`urn:li:activity:${stored.activityId}`);
      return permalink ? { activityId: stored.activityId, permalink } : null;
    },
    reset() {
      identities.clear();
      conflicts.clear();
      if (!disposed) scan(document);
    },
    dispose() {
      disposed = true;
      observer?.disconnect();
      observer = null;
      identities.clear();
      conflicts.clear();
    },
  };
}
