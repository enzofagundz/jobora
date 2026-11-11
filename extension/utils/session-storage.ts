import { storage } from 'wxt/utils/storage';

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
