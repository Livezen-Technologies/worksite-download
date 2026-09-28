import 'fake-indexeddb/auto';
import { describe, expect, it } from 'vitest';
import { IdbStore, MemoryStore, Queue } from '../src/queue';

describe('offline queue (FR-BT-SDK-07)', () => {
  it('caps the queue at 200 items, dropping the oldest', async () => {
    const q = new Queue(new MemoryStore());
    for (let i = 0; i < 205; i++) await q.push('envelope', { i }, 10);
    const all = await q.store.all();
    expect(all).toHaveLength(200);
    expect((all[0].body as { i: number }).i).toBe(5);
  });

  it('caps the queue at 20 MB', async () => {
    const q = new Queue(new MemoryStore());
    for (let i = 0; i < 5; i++) await q.push('report', { i }, 6 * 1024 * 1024);
    const { items, bytes } = await q.size();
    expect(items).toBe(3);
    expect(bytes).toBeLessThanOrEqual(20 * 1024 * 1024);
  });

  it('backs off exponentially and gives up after 12 attempts', async () => {
    let now = 0;
    const q = new Queue(new MemoryStore(), 200, 20 * 1024 * 1024, () => now);
    await q.push('envelope', {}, 1);
    let [item] = await q.due();
    await q.retry(item, () => 0.5);
    expect(await q.due()).toHaveLength(0);
    now = 1000;
    [item] = await q.due();
    expect(item.attempts).toBe(1);
    await q.retry(item, () => 0.5);
    now = 2500;
    expect(await q.due()).toHaveLength(0);
    now = 3000;
    [item] = await q.due();
    expect(item.attempts).toBe(2);
    await q.store.update({ ...item, attempts: 11 });
    await q.retry({ ...item, attempts: 11 });
    expect(await q.store.all()).toHaveLength(0);
  });

  it('persists in IndexedDB (including Blobs) across instances', async () => {
    const a = new Queue(new IdbStore('bt-test-db', indexedDB));
    await a.push('report', { title: 'x', screenshot: new Blob(['png']) }, 3);
    await a.push('envelope', { events: [1] }, 3);
    const b = new Queue(new IdbStore('bt-test-db', indexedDB));
    const items = await b.due();
    expect(items.map((i) => i.kind)).toEqual(['report', 'envelope']);
    await b.done(items[0]);
    expect(await b.store.all()).toHaveLength(1);
    await b.clear();
    expect(await a.store.all()).toHaveLength(0);
  });
});
