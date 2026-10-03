import { afterEach, describe, expect, it, vi } from 'vitest';
import { KeyValueQueueStore, MobileBugTrace, type KeyValueStore, type NativeCrash, type ReactNativeLike } from '../src/index';

const tpt = (policy: string) => Buffer.from(JSON.stringify({ payload: { bugtrace: { policy } } })).toString('base64url');

function kv(init: Record<string, string> = {}): KeyValueStore & { data: Record<string, string> } {
  const data = { ...init };
  return { data, getItem: async (k) => data[k] ?? null, setItem: async (k, v) => void (data[k] = v), removeItem: async (k) => void delete data[k] };
}

function rn(crashes: NativeCrash[] = []) {
  let handler: (e: unknown, fatal?: boolean) => void = vi.fn();
  const prev = handler;
  const native = { start: vi.fn(), getPendingCrashes: vi.fn(async () => crashes), clearPendingCrashes: vi.fn(async () => undefined) };
  const listeners: Record<string, (...a: unknown[]) => void> = {};
  const r: ReactNativeLike = {
    ErrorUtils: { getGlobalHandler: () => handler, setGlobalHandler: (h) => void (handler = h) },
    NativeModules: { WorkSuiteBugTrace: native },
    AppState: { currentState: 'active', addEventListener: (_t, fn) => ((listeners.appstate = fn as never), { remove() {} }) },
    DeviceEventEmitter: { addListener: (e, fn) => ((listeners[e] = fn), { remove() {} }) },
    Platform: { OS: 'android', Version: 34 },
  };
  return { r, native, prev, fire: (e: unknown, fatal?: boolean) => handler(e, fatal), listeners };
}

const fetch = vi.fn(async () => new Response('{"ids":[]}', { status: 202 })) as unknown as typeof globalThis.fetch;
let open: MobileBugTrace[] = [];
afterEach(() => {
  open.forEach((b) => b.close());
  open = [];
});

async function make(policy: string, store = kv(), crashes: NativeCrash[] = [], extra: Partial<ConstructorParameters<typeof MobileBugTrace>[0]> = {}) {
  const h = rn(crashes);
  const bt = new MobileBugTrace({ rn: h.r, storage: store, key: 'pk_mobile_test', release: '1.4.0+140', dist: '140', endpoint: 'https://uc.test', tpt: tpt(policy), fetch, sampleRates: { error: 1 }, ...extra });
  open.push(bt);
  const spy = vi.spyOn(bt.client, 'captureException');
  const msg = vi.spyOn(bt.client, 'captureMessage');
  await bt.start();
  return { bt, h, store, spy, msg };
}

describe('bugtrace-react-native', () => {
  it('reports fatal JS errors as crashes and still calls the previous global handler', async () => {
    const { h, spy, store } = await make('CRASHES_ONLY');
    h.fire(new TypeError('boom'), true);
    expect(spy).toHaveBeenCalledWith(expect.any(TypeError), expect.objectContaining({ type: 'crash', tags: expect.objectContaining({ dist: '140', fatal: 'true' }) }));
    expect(h.prev).toHaveBeenCalled();
    h.fire(new Error('soft'), false); // CRASHES_ONLY: non-fatal errors stay on the device
    expect(spy).toHaveBeenCalledTimes(1);
    expect(JSON.parse(store.data['bt:session']).crashed).toBe(true);
  });

  it('turns native crashes, NDK tombstones and ANRs into events and clears them', async () => {
    const crashes: NativeCrash[] = [
      { id: 'n1', kind: 'ndk_tombstone', type: 'SIGSEGV', value: 'Segmentation fault', frames: [{ function: 'libworksuite.so!decode' }] },
      { id: 'n2', kind: 'anr', type: 'ANR', value: 'Input dispatching timed out', duration_ms: 6200 },
    ];
    const onPreviousCrash = vi.fn();
    const { spy, h } = await make('CRASHES_ONLY', kv(), crashes, { onPreviousCrash });
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ name: 'SIGSEGV' }), expect.objectContaining({ type: 'crash', tags: expect.objectContaining({ native_kind: 'ndk_tombstone' }) }));
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ name: 'ANR' }), expect.objectContaining({ type: 'hang', tags: expect.objectContaining({ hang_ms: '6200' }) }));
    expect(h.native.clearPendingCrashes).toHaveBeenCalledWith(['n1', 'n2']);
    expect(onPreviousCrash).toHaveBeenCalledWith(expect.objectContaining({ kind: 'anr' }));
  });

  it('detects an out-of-memory termination from the previous session marker', async () => {
    const store = kv({ 'bt:session': JSON.stringify({ sid: 'old', release: '1.4.0+140', state: 'foreground', clean: false, crashed: false, at: '' }) });
    const { msg } = await make('CRASHES_ONLY', store);
    expect(msg).toHaveBeenCalledWith('Out-of-memory termination', 'fatal', expect.objectContaining({ type: 'crash', tags: expect.objectContaining({ termination: 'oom' }) }));
  });

  it('no OOM after a clean exit, a background kill or an upgrade', async () => {
    for (const m of [{ clean: true }, { state: 'background' }, { release: '1.3.0+130' }]) {
      const store = kv({ 'bt:session': JSON.stringify({ sid: 'old', release: '1.4.0+140', state: 'foreground', clean: false, crashed: false, at: '', ...m }) });
      const { msg } = await make('CRASHES_ONLY', store);
      expect(msg).not.toHaveBeenCalledWith('Out-of-memory termination', expect.anything(), expect.anything());
    }
  });

  it('policy OFF sends nothing and discards native crashes; opt-out persists', async () => {
    const { spy, h, bt, store } = await make('OFF', kv(), [{ id: 'n1', kind: 'ios_signal', type: 'SIGABRT', value: 'abort' }]);
    h.fire(new Error('x'), true);
    expect(spy).not.toHaveBeenCalled();
    expect(h.native.clearPendingCrashes).toHaveBeenCalledWith(['n1']);
    await bt.setOptOut(true);
    expect(store.data['bt:optout']).toBe('1');
  });

  it('shake opens the report flow', async () => {
    const onShake = vi.fn();
    const { h } = await make('CRASHES_AND_ERRORS', kv(), [], { onShake });
    h.listeners.WorkSuiteBugTraceShake();
    expect(onShake).toHaveBeenCalled();
  });

  it('queue survives restarts on the key/value store', async () => {
    const store = kv();
    const q = new KeyValueQueueStore(store);
    await q.add({ kind: 'envelope', body: {}, size: 1, created: 1, attempts: 0, next: 0 });
    expect((await new KeyValueQueueStore(store).all()).length).toBe(1);
  });
});
