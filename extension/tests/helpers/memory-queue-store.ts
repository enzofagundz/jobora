import type { CaptureQueueRecord, CaptureQueueStore } from '@/utils/capture-queue';

/** Store de teste com a mesma semântica de persistência do IndexedDB real. */
export function createMemoryQueueStore(): CaptureQueueStore & {
  records: Map<string, CaptureQueueRecord>;
} {
  const records = new Map<string, CaptureQueueRecord>();

  return {
    records,
    async getAll() {
      return Array.from(records.values(), (record) => structuredClone(record));
    },
    async put(record) {
      records.set(record.id, structuredClone(record));
    },
    async delete(id) {
      records.delete(id);
    },
    async replace(oldId, record) {
      records.set(record.id, structuredClone(record));
      if (oldId !== record.id) records.delete(oldId);
    },
  };
}
