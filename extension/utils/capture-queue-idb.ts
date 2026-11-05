import type { CaptureQueueRecord, CaptureQueueStore } from './capture-queue';

const DB_NAME = 'jobora-capture-queue';
const DB_VERSION = 1;
const STORE_NAME = 'envelopes';

/** Fila durável nativa, sem dependência; sobrevive ao restart do service worker. */
export function createIndexedDbQueueStore(): CaptureQueueStore {
  let database: Promise<IDBDatabase> | null = null;

  function open(): Promise<IDBDatabase> {
    database ??= new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          db.createObjectStore(STORE_NAME, { keyPath: 'id' });
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('IndexedDB indisponível.'));
    });
    return database;
  }

  return {
    async getAll(): Promise<CaptureQueueRecord[]> {
      const db = await open();
      return new Promise((resolve, reject) => {
        const request = db.transaction(STORE_NAME, 'readonly').objectStore(STORE_NAME).getAll();
        request.onsuccess = () => resolve(request.result as CaptureQueueRecord[]);
        request.onerror = () => reject(request.error ?? new Error('Falha ao ler a fila.'));
      });
    },

    async put(record: CaptureQueueRecord): Promise<void> {
      const db = await open();
      const transaction = db.transaction(STORE_NAME, 'readwrite');
      transaction.objectStore(STORE_NAME).put(record);
      await transactionDone(transaction);
    },

    async delete(id: string): Promise<void> {
      const db = await open();
      const transaction = db.transaction(STORE_NAME, 'readwrite');
      transaction.objectStore(STORE_NAME).delete(id);
      await transactionDone(transaction);
    },

    async replace(oldId: string, record: CaptureQueueRecord): Promise<void> {
      const db = await open();
      const transaction = db.transaction(STORE_NAME, 'readwrite');
      const store = transaction.objectStore(STORE_NAME);
      if (oldId !== record.id) store.delete(oldId);
      store.put(record);
      await transactionDone(transaction);
    },
  };
}

function transactionDone(transaction: IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error ?? new Error('Falha ao gravar a fila.'));
    transaction.onabort = () => reject(transaction.error ?? new Error('Falha ao abortar a fila.'));
  });
}
