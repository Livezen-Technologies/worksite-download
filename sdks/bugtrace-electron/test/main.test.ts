import { EventEmitter } from 'node:events';
import { mkdtempSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { findMinidumps, MainProcessBugTrace } from '../src/index';
import { FileStore } from '../src/file-store';

const tpt = (policy: string) => Buffer.from(JSON.stringify({ payload: { bugtrace: { policy } } })).toString('base64url');

function setup(policy = 'CRASHES_AND_ERRORS') {
  const dir = mkdtempSync(join(tmpdir(), 'bt-el-'));
  const dumps = join(dir, 'Crashpad', 'reports');
  mkdirSync(dumps, { recursive: true });
  const app = Object.assign(new EventEmitter(), {
    getPath: (n: string) => (n === 'crashDumps' ? join(dir, 'Crashpad') : join(dir, 'userData')),
    getVersion: () => '2.7.0',
  });
  const crashReporter = { start: vi.fn() };
  const calls: { url: string; init: RequestInit }[] = [];
  const fetch = vi.fn(async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return new Response(JSON.stringify({ ids: [] }), { status: url.includes('minidump') ? 202 : 202 });
  }) as unknown as typeof globalThis.fetch;
  const bt = new MainProcessBugTrace({ electron: { app, crashReporter }, key: 'pk_desktop_test', release: '2.7.0+27001', endpoint: 'https://uc.test', tpt: tpt(policy), fetch, exitOnUncaught: false, sampleRates: { error: 1 } });
  return { bt, app, crashReporter, calls, dumps, dir };
}

let open: MainProcessBugTrace[] = [];
afterEach(() => {
  open.forEach((b) => b.close());
  open = [];
});

describe('bugtrace-electron main process', () => {
  it('starts crashReporter without server upload and uploads leftover minidumps after the policy check', async () => {
    const s = setup();
    open.push(s.bt);
    writeFileSync(join(s.dumps, 'a.dmp'), 'MDMP-fake');
    s.bt.start();
    expect(s.crashReporter.start).toHaveBeenCalledWith(expect.objectContaining({ uploadToServer: false, submitURL: 'https://uc.test/api/v1/bugtrace/minidump' }));
    await s.bt.client.refreshPolicy();
    const r = await s.bt.uploadMinidumps();
    // start() may already have sent it; either way it is gone from disk and was posted with the key.
    expect(r.sent + s.calls.filter((c) => c.url.endsWith('/minidump')).length).toBeGreaterThan(0);
    expect(existsSync(join(s.dumps, 'a.dmp'))).toBe(false);
    const call = s.calls.find((c) => c.url.endsWith('/minidump'))!;
    expect((call.init.headers as Record<string, string>)['X-BT-Key']).toBe('pk_desktop_test');
    expect((call.init.body as FormData).get('release')).toBe('2.7.0+27001');
  });

  it('policy OFF deletes minidumps unsent and captures nothing (FR-BT-SDK-10)', async () => {
    const s = setup('OFF');
    open.push(s.bt);
    writeFileSync(join(s.dumps, 'b.dmp'), 'MDMP');
    await s.bt.client.refreshPolicy();
    expect(await s.bt.uploadMinidumps()).toEqual({ sent: 0, discarded: 1 });
    expect(s.bt.captureException(new Error('x'))).toBeNull();
    expect(s.bt.processGone('gpu', { reason: 'crashed', exitCode: 3 })).toBeNull();
    expect(s.calls.some((c) => c.url.endsWith('/minidump'))).toBe(false);
  });

  it('CRASHES_ONLY reports process crashes and hangs but not updater errors', async () => {
    const s = setup('CRASHES_ONLY');
    open.push(s.bt);
    await s.bt.client.refreshPolicy();
    expect(s.bt.processGone('gpu', { reason: 'crashed', exitCode: 3 })).toBeTypeOf('string');
    expect(s.bt.processGone('renderer', { reason: 'clean-exit' })).toBeNull();
    expect(s.bt.captureUpdaterFailure('UPD-E04', 'hash mismatch')).toBeNull();
  });

  it('records updater failures with their ENH-02 code and honours opt-out', async () => {
    const s = setup();
    open.push(s.bt);
    await s.bt.client.refreshPolicy();
    expect(s.bt.captureUpdaterFailure('UPD-E04', new Error('hash mismatch'))).toBeTypeOf('string');
    s.bt.setOptOut(true);
    expect(s.bt.isOptOut()).toBe(true);
    expect(s.bt.captureException(new Error('after opt-out'))).toBeNull();
  });

  it('reports a window hang once it exceeds the threshold', async () => {
    vi.useFakeTimers();
    try {
      const s = setup();
      open.push(s.bt);
      await s.bt.client.refreshPolicy();
      const spy = vi.spyOn(s.bt.client, 'captureMessage');
      s.bt.start();
      const win = new EventEmitter();
      s.app.emit('browser-window-created', {}, win);
      win.emit('unresponsive');
      vi.advanceTimersByTime(4000);
      win.emit('responsive');
      vi.advanceTimersByTime(2000);
      expect(spy).not.toHaveBeenCalledWith(expect.stringContaining('not responding'), expect.anything(), expect.anything());
      win.emit('unresponsive');
      vi.advanceTimersByTime(5100);
      expect(spy).toHaveBeenCalledWith(expect.stringContaining('not responding'), 'error', expect.objectContaining({ type: 'hang' }));
    } finally {
      vi.useRealTimers();
    }
  });

  it('finds Crashpad dumps in nested folders and persists the queue across restarts', async () => {
    const s = setup();
    open.push(s.bt);
    mkdirSync(join(s.dir, 'Crashpad', 'pending'), { recursive: true });
    writeFileSync(join(s.dir, 'Crashpad', 'pending', 'c.DMP'), 'x');
    writeFileSync(join(s.dir, 'Crashpad', 'settings.dat'), 'x');
    expect(findMinidumps(join(s.dir, 'Crashpad')).map((f) => f.split(/[\\/]/).pop())).toEqual(['c.DMP']);
    const f = join(s.dir, 'q.json');
    const a = new FileStore(f);
    await a.add({ kind: 'envelope', body: { n: 1 }, size: 1, created: 1, attempts: 0, next: 0 });
    expect((await new FileStore(f).all()).length).toBe(1);
  });
});
