import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import type { QueueItem, QueueStore } from '@worksuite/bugtrace-web';

/**
 * Offline queue persisted as one JSON file under userData, so events captured
 * just before a crash or while offline survive a restart. Writes go through a
 * temp file + rename so a crash mid-write never corrupts the queue.
 */
export class FileStore implements QueueStore {
  private items: QueueItem[];
  private seq: number;

  constructor(private readonly file: string) {
    try {
      this.items = (JSON.parse(readFileSync(file, 'utf8')) as QueueItem[]).filter((i) => i && typeof i.id === 'number');
    } catch {
      this.items = [];
    }
    this.seq = this.items.reduce((m, i) => Math.max(m, i.id ?? 0), 0) + 1;
  }

  private save(): void {
    try {
      mkdirSync(dirname(this.file), { recursive: true });
      writeFileSync(this.file + '.tmp', JSON.stringify(this.items));
      renameSync(this.file + '.tmp', this.file);
    } catch {
      /* disk full / read-only profile: keep in memory */
    }
  }

  async add(item: QueueItem) {
    this.items.push({ ...item, id: this.seq++ });
    this.save();
  }
  async all() {
    return this.items.map((i) => ({ ...i }));
  }
  async remove(id: number) {
    this.items = this.items.filter((i) => i.id !== id);
    this.save();
  }
  async update(item: QueueItem) {
    this.items = this.items.map((i) => (i.id === item.id ? { ...item } : i));
    this.save();
  }
  async clear() {
    this.items = [];
    this.save();
  }
}

/** The user's opt-out (FR-BT-PRV-03), persisted next to the queue. */
export class OptOutFlag {
  constructor(private readonly file: string) {}
  get(): boolean {
    try {
      return readFileSync(this.file, 'utf8').trim() === '1';
    } catch {
      return false;
    }
  }
  set(v: boolean): void {
    try {
      mkdirSync(dirname(this.file), { recursive: true });
      writeFileSync(this.file, v ? '1' : '0');
    } catch {
      /* ignore */
    }
  }
}
