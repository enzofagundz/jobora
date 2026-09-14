import { isPost } from './extract';

export const JUMP_STABLE_CYCLES = 6;
export const JUMP_SETTLE_MS = 1200;
export const JUMP_MAX_CYCLES = 50;
export const JUMP_OVERSCROLL_PX = 200;

export function countPosts(root: ParentNode = document): number {
  return Array.from(root.querySelectorAll('[role="listitem"]')).filter(isPost).length;
}

export function getLastPost(root: ParentNode = document): Element | null {
  const posts = Array.from(root.querySelectorAll('[role="listitem"]')).filter(isPost);
  return posts.length > 0 ? posts[posts.length - 1] : null;
}

export type JumpResult = 'completed' | 'cancelled' | 'aborted' | 'empty';

export interface RunJumpOptions {
  isActive: () => boolean;
  onProgress?: (count: number) => void;
  signal?: AbortSignal;
  stableCycles?: number;
  settleMs?: number;
  maxCycles?: number;
  getHeight?: () => number;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function scrollHeight(): number {
  return document.scrollingElement?.scrollHeight ?? document.body.scrollHeight ?? 0;
}

export async function runJumpToEnd(options: RunJumpOptions): Promise<JumpResult> {
  const { isActive, onProgress, signal } = options;
  const stableCycles = options.stableCycles ?? JUMP_STABLE_CYCLES;
  const settleMs = options.settleMs ?? JUMP_SETTLE_MS;
  const maxCycles = options.maxCycles ?? JUMP_MAX_CYCLES;
  const getHeight = options.getHeight ?? scrollHeight;

  if (signal?.aborted) return 'cancelled';
  if (!isActive()) return 'aborted';
  if (countPosts() === 0) return 'empty';

  let stable = 0;
  let cycles = 0;
  while (isActive() && cycles < maxCycles) {
    if (signal?.aborted) return 'cancelled';
    const beforeCount = countPosts();
    const beforeHeight = getHeight();
    getLastPost()?.scrollIntoView({ block: 'end' });
    window.scrollBy(0, JUMP_OVERSCROLL_PX);
    await sleep(settleMs);
    if (signal?.aborted) return 'cancelled';
    if (!isActive()) return 'aborted';
    const afterCount = countPosts();
    const afterHeight = getHeight();
    onProgress?.(afterCount);
    stable = afterCount > beforeCount || afterHeight > beforeHeight ? 0 : stable + 1;
    cycles++;
    if (stable >= stableCycles) break;
  }

  if (signal?.aborted) return 'cancelled';
  if (!isActive()) return 'aborted';
  getLastPost()?.scrollIntoView({ block: 'end' });
  return 'completed';
}
