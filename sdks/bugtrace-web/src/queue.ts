import type { QueueItem, QueueStore } from './types';

export const MAX_ITEMS = 200;
export const MAX_BYTES = 20 * 1024 * 1024;

/** In-memory fallback (private windows, IndexedDB blocked). */
export class MemoryStore implements QueueStore {
  private items: QueueItem[] = [];
  private seq = 1;
  async add(item: QueueItem) {
    this.items.push({ ...item, id: this.seq++ });
  }
  async all() {
    return this.items.map((i) => ({ ...i }));
  }
  async remove(id: number) {
    this.items = this.items.filter((i) => i.id !== id);
  }
  async update(item: QueueItem) {
    this.items = this.items.map((i) => (i.id === item.id ? { ...item } : i));
  }
  async clear() {
    this.items = [];
  }
}

/** IndexedDB queue ("worksuite-bugtrace" / "queue") — survives reloads and offline periods. */
export class IdbStore implements QueueStore {
  private db: Promise<IDBDatabase>;
  constructor(name = 'worksuite-bugtrace', idb: IDBFactory = indexedDB) {
    this.db = new Promise((resolve, reject) => {
      const req = idb.open(name, 1);
      req.onupgradeneeded = () => req.result.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }
  private async tx<T>(mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await this.db;
    return new Promise((resolve, reject) => {
      const req = fn(db.transaction('queue', mode).objectStore('queue'));
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }
  async add(item: QueueItem) {
    const { id: _ignored, ...rest } = item;
    await this.tx('readwrite', (s) => s.add(rest));
  }
  all() {
    return this.tx<QueueItem[]>('readonly', (s) => s.getAll() as IDBRequest<QueueItem[]>);
  }
  async remove(id: number) {
    await this.tx('readwrite', (s) => s.delete(id));
  }
  async update(item: QueueItem) {
    await this.tx('readwrite', (s) => s.put(item));
  }
  async clear() {
    await this.tx('readwrite', (s) => s.clear());
  }
}

export function defaultStore(): QueueStore {
  try {
    if (typeof indexedDB !== 'undefined') return new IdbStore();
  } catch {
    /* fall through */
  }
  return new MemoryStore();
}

/**
 * Bounded persistent queue with exponential backoff (FR-BT-SDK-07):
 * default cap 200 items / 20 MB, oldest dropped first.
 */
export class Queue {
  constructor(public store: QueueStore, private maxItems = MAX_ITEMS, private maxBytes = MAX_BYTES, private now: () => number = Date.now) {}

  async push(kind: QueueItem['kind'], body: unknown, size: number): Promise<void> {
    await this.store.add({ kind, body, size, created: this.now(), attempts: 0, next: 0 });
    await this.trim();
  }

  private async trim(): Promise<void> {
    const items = (await this.store.all()).sort((a, b) => (a.id ?? 0) - (b.id ?? 0));
    let bytes = items.reduce((n, i) => n + i.size, 0);
    let count = items.length;
    for (const i of items) {
      if (count <= this.maxItems && bytes <= this.maxBytes) break;
      await this.store.remove(i.id!);
      count--;
      bytes -= i.size;
    }
  }

  /** Items whose backoff has elapsed. */
  async due(): Promise<QueueItem[]> {
    const t = this.now();
    return (await this.store.all()).filter((i) => i.next <= t).sort((a, b) => (a.id ?? 0) - (b.id ?? 0));
  }

  async done(item: QueueItem): Promise<void> {
    await this.store.remove(item.id!);
  }

  /** 1 s, 2 s, 4 s … capped at 1 h, with ±20 % jitter. Gives up after 12 attempts. */
  async retry(item: QueueItem, random: () => number = Math.random): Promise<void> {
    const attempts = item.attempts + 1;
    if (attempts >= 12) return this.store.remove(item.id!);
    const delay = Math.min(3_600_000, 1000 * 2 ** (attempts - 1)) * (0.8 + 0.4 * random());
    await this.store.update({ ...item, attempts, next: this.now() + delay });
  }

  clear(): Promise<void> {
    return this.store.clear();
  }

  async size(): Promise<{ items: number; bytes: number }> {
    const all = await this.store.all();
    return { items: all.length, bytes: all.reduce((n, i) => n + i.size, 0) };
  }
}
