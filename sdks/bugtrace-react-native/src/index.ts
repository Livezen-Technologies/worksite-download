/**
 * @worksuite/bugtrace-react-native — Bug Trace for the WorkSuite mobile app
 * (SRS §18.7, FR-BT-SDK-03).
 *
 *   import { ErrorUtils, NativeModules, AppState, DeviceEventEmitter, Platform } from 'react-native';
 *   import AsyncStorage from '@react-native-async-storage/async-storage';
 *   import { initMobile } from '@worksuite/bugtrace-react-native';
 *   const bt = await initMobile({
 *     rn: { ErrorUtils, NativeModules, AppState, DeviceEventEmitter, Platform }, storage: AsyncStorage,
 *     key: 'pk_mobile_prod_…', release: '1.4.0+140', tpt: () => session.tpt,
 *     onShake: () => navigation.navigate('ReportBug'),
 *   });
 *
 * Captures JS errors through the global handler, native crashes recorded by
 * the `WorkSuiteBugTrace` native module (iOS signal/exception handler, Android
 * uncaught exceptions and NDK tombstones), ANRs / app hangs > 5 s (native
 * watchdog plus a JS-thread watchdog), and out-of-memory terminations
 * (detected on the next launch from the session marker). Hermes source maps
 * are uploaded by CI through the symbols API; events carry `release` + `dist`.
 * Nothing leaves the device when the policy is OFF or the user opted out.
 */
import { allowsAutomatic, BugTraceClient, type CaptureContext, type Frame, type InitOptions, type QueueItem, type QueueStore, type ReportInput } from '@worksuite/bugtrace-web';

/** AsyncStorage-compatible key/value store. */
export interface KeyValueStore {
  getItem(key: string): Promise<string | null>;
  setItem(key: string, value: string): Promise<void>;
  removeItem(key: string): Promise<void>;
}

/** Crash recorded natively in a previous run. */
export interface NativeCrash {
  id: string;
  kind: 'ios_signal' | 'ios_exception' | 'android_exception' | 'ndk_tombstone' | 'anr' | 'app_hang';
  type: string;
  value: string;
  timestamp?: string;
  frames?: Frame[];
  thread?: string;
  /** For ANR / app hang: how long the main thread was blocked. */
  duration_ms?: number;
  release?: string;
}

export interface NativeBugTraceModule {
  /** Installs the native signal / exception handlers, ANR watchdog and memory-warning observer. */
  start?(options: { hangThresholdMs: number }): void;
  getPendingCrashes(): Promise<NativeCrash[]>;
  clearPendingCrashes(ids: string[]): Promise<void>;
}

export interface ReactNativeLike {
  ErrorUtils: {
    getGlobalHandler(): (error: unknown, isFatal?: boolean) => void;
    setGlobalHandler(handler: (error: unknown, isFatal?: boolean) => void): void;
  };
  NativeModules?: { WorkSuiteBugTrace?: NativeBugTraceModule } & Record<string, unknown>;
  AppState?: { currentState?: string; addEventListener(type: 'change', fn: (state: string) => void): { remove(): void } };
  DeviceEventEmitter?: { addListener(event: string, fn: (...a: unknown[]) => void): { remove(): void } };
  Platform?: { OS: string; Version?: string | number };
}

export interface MobileInitOptions extends Omit<InitOptions, 'storage' | 'reportShortcut' | 'captureGlobalErrors' | 'instrumentFetch'> {
  rn: ReactNativeLike;
  storage: KeyValueStore;
  /** Bundle "dist" (build number) used with release to pick the Hermes source map. */
  dist?: string;
  hangThresholdMs?: number;
  /** Shake-to-report: called when the device is shaken (native module emits `WorkSuiteBugTraceShake`). */
  onShake?: () => void;
  /** Post-crash sheet: called on launch when the previous run crashed. */
  onPreviousCrash?: (info: PreviousCrash) => void;
}

export interface PreviousCrash {
  kind: NativeCrash['kind'] | 'js' | 'oom';
  title: string;
  event_id: string | null;
}

interface SessionMarker {
  sid: string;
  release: string;
  state: 'foreground' | 'background';
  clean: boolean;
  crashed: boolean;
  at: string;
}

const MARKER = 'bt:session';
const QUEUE = 'bt:queue';
const OPT_OUT = 'bt:optout';

/** Offline queue on AsyncStorage (single JSON value; the queue is capped by the client). */
export class KeyValueQueueStore implements QueueStore {
  private items: QueueItem[] = [];
  private seq = 1;
  private loaded: Promise<void>;
  private writing: Promise<void> = Promise.resolve();

  constructor(private readonly kv: KeyValueStore, private readonly key = QUEUE) {
    this.loaded = kv
      .getItem(key)
      .then((raw) => {
        const arr = raw ? (JSON.parse(raw) as QueueItem[]) : [];
        // Items added before the load finished stay; stored ones go first.
        this.items = [...arr.filter((i) => typeof i?.id === 'number'), ...this.items];
        this.seq = this.items.reduce((m, i) => Math.max(m, i.id ?? 0), 0) + 1;
      })
      .catch(() => undefined);
  }

  private save(): Promise<void> {
    const snapshot = JSON.stringify(this.items);
    this.writing = this.writing.then(() => this.kv.setItem(this.key, snapshot)).catch(() => undefined);
    return this.writing;
  }

  async add(item: QueueItem) {
    await this.loaded;
    this.items.push({ ...item, id: this.seq++ });
    await this.save();
  }
  async all() {
    await this.loaded;
    return this.items.map((i) => ({ ...i }));
  }
  async remove(id: number) {
    await this.loaded;
    this.items = this.items.filter((i) => i.id !== id);
    await this.save();
  }
  async update(item: QueueItem) {
    await this.loaded;
    this.items = this.items.map((i) => (i.id === item.id ? { ...item } : i));
    await this.save();
  }
  async clear() {
    await this.loaded;
    this.items = [];
    await this.save();
  }
}

export class MobileBugTrace {
  readonly client: BugTraceClient;
  private readonly off: (() => void)[] = [];
  private optedOut = false;
  private marker!: SessionMarker;
  private readonly hangMs: number;
  private readonly native: NativeBugTraceModule | undefined;

  constructor(private readonly o: MobileInitOptions) {
    const os = (o.rn.Platform?.OS ?? 'android').toLowerCase();
    this.hangMs = o.hangThresholdMs ?? 5000;
    this.native = o.rn.NativeModules?.WorkSuiteBugTrace;
    this.client = new BugTraceClient({
      app: 'lk.worksuite.mobile',
      platform: os === 'ios' ? 'ios' : 'android',
      ...o,
      storage: new KeyValueQueueStore(o.storage),
      reportShortcut: false,
    });
  }

  allows(type: 'crash' | 'error' | 'hang'): boolean {
    return allowsAutomatic(this.client.policy, type, this.optedOut);
  }

  private tags(extra: Record<string, string> = {}): Record<string, string> {
    const t: Record<string, string> = { ...extra };
    if (this.o.dist) t.dist = this.o.dist;
    const v = this.o.rn.Platform?.Version;
    if (v !== undefined) t.os_version = String(v);
    return t;
  }

  async start(): Promise<this> {
    this.optedOut = (await this.o.storage.getItem(OPT_OUT).catch(() => null)) === '1';
    await this.client.refreshPolicy();

    const previous = await this.readMarker();
    this.marker = { sid: this.client.sessionId, release: this.client.opts.release, state: this.o.rn.AppState?.currentState === 'background' ? 'background' : 'foreground', clean: false, crashed: false, at: new Date().toISOString() };
    await this.writeMarker();

    this.installJsHandler();
    this.native?.start?.({ hangThresholdMs: this.hangMs });
    this.watchJsThread();
    this.watchAppState();
    if (this.o.onShake && this.o.rn.DeviceEventEmitter) {
      const sub = this.o.rn.DeviceEventEmitter.addListener('WorkSuiteBugTraceShake', () => this.o.onShake?.());
      this.off.push(() => sub.remove());
    }

    if (this.o.sessions !== false && !this.optedOut) this.client.startSession();
    const crash = (await this.collectNativeCrashes()) ?? this.detectOom(previous);
    if (crash) this.o.onPreviousCrash?.(crash);
    await this.client.flush();
    return this;
  }

  private async readMarker(): Promise<SessionMarker | null> {
    try {
      const raw = await this.o.storage.getItem(MARKER);
      return raw ? (JSON.parse(raw) as SessionMarker) : null;
    } catch {
      return null;
    }
  }

  private writeMarker(): Promise<void> {
    return this.o.storage.setItem(MARKER, JSON.stringify(this.marker)).catch(() => undefined);
  }

  private installJsHandler(): void {
    const eu = this.o.rn.ErrorUtils;
    const prev = eu.getGlobalHandler();
    eu.setGlobalHandler((error, isFatal) => {
      try {
        if (this.allows(isFatal ? 'crash' : 'error')) {
          this.client.captureException(error, { type: isFatal ? 'crash' : 'error', handled: false, tags: this.tags({ mechanism: 'globalHandler', fatal: String(!!isFatal) }) });
        }
        if (isFatal) {
          this.marker.crashed = true;
          void this.writeMarker();
          void this.client.flush();
        }
      } catch {
        /* never interfere with the app's own handler (NFR-C21) */
      }
      prev(error, isFatal);
    });
    this.off.push(() => eu.setGlobalHandler(prev));
  }

  /** JS thread watchdog: a 1 s timer that fires late by more than the threshold means the JS thread hung. */
  private watchJsThread(): void {
    let last = Date.now();
    const tick = setInterval(() => {
      const now = Date.now();
      const lag = now - last - 1000;
      last = now;
      // Ignore background suspension: timers stop while the app is backgrounded.
      if (lag > this.hangMs && this.marker.state === 'foreground' && this.allows('hang')) {
        this.client.captureMessage(`JS thread blocked for ${Math.round(lag / 1000)} s`, 'error', { type: 'hang', handled: false, tags: this.tags({ hang_ms: String(lag), thread: 'js' }) });
      }
    }, 1000);
    this.off.push(() => clearInterval(tick));
  }

  private watchAppState(): void {
    const as = this.o.rn.AppState;
    if (!as) return;
    const sub = as.addEventListener('change', (state) => {
      this.marker.state = state === 'active' ? 'foreground' : 'background';
      void this.writeMarker();
      if (state === 'background') void this.client.flush();
    });
    this.off.push(() => sub.remove());
  }

  /** Reports crashes the native module recorded in earlier runs, then clears them. */
  async collectNativeCrashes(): Promise<PreviousCrash | null> {
    if (!this.native) return null;
    let crashes: NativeCrash[];
    try {
      crashes = await this.native.getPendingCrashes();
    } catch {
      return null;
    }
    if (!crashes.length) return null;
    const policyKnown = !!this.client.policy;
    let latest: PreviousCrash | null = null;
    for (const c of crashes) {
      const hang = c.kind === 'anr' || c.kind === 'app_hang';
      const type = hang ? 'hang' : 'crash';
      let id: string | null = null;
      if (this.allows(type)) {
        const error = Object.assign(new Error(c.value), { name: c.type });
        id = this.client.captureException(error, {
          type,
          handled: false,
          tags: this.tags({ native_kind: c.kind, ...(c.thread ? { thread: c.thread } : {}), ...(c.duration_ms ? { hang_ms: String(c.duration_ms) } : {}) }),
          extra: c.frames?.length ? { native_frames: c.frames.slice(0, 100) } : undefined,
        });
      }
      latest = { kind: c.kind, title: `${c.type}: ${c.value}`, event_id: id };
    }
    // Unknown policy (offline, no TPT): keep them for the next launch. Known: sent or deliberately discarded.
    if (policyKnown) await this.native.clearPendingCrashes(crashes.map((c) => c.id)).catch(() => undefined);
    return latest;
  }

  /**
   * Out-of-memory termination heuristic: the previous run of the same release
   * ended while in the foreground without a clean exit or a recorded crash.
   */
  detectOom(previous: SessionMarker | null): PreviousCrash | null {
    if (!previous || previous.clean || previous.crashed || previous.state !== 'foreground' || previous.release !== this.client.opts.release) return null;
    if (!this.allows('crash')) return null;
    const id = this.client.captureMessage('Out-of-memory termination', 'fatal', { type: 'crash', handled: false, tags: this.tags({ termination: 'oom', previous_session: previous.sid }) });
    return { kind: 'oom', title: 'The app was closed by the system (out of memory)', event_id: id };
  }

  captureException(error: unknown, ctx: CaptureContext = {}): string | null {
    const type = (ctx.type ?? 'error') as 'crash' | 'error' | 'hang';
    return this.allows(type) ? this.client.captureException(error, { ...ctx, tags: this.tags(ctx.tags) }) : null;
  }

  /** Report from the shake / post-crash sheet (FR-BT-REP). */
  sendReport(input: ReportInput) {
    return this.client.sendReport(input);
  }

  async setOptOut(v: boolean): Promise<void> {
    this.optedOut = v;
    await (v ? this.o.storage.setItem(OPT_OUT, '1') : this.o.storage.removeItem(OPT_OUT)).catch(() => undefined);
  }

  isOptOut(): boolean {
    return this.optedOut;
  }

  /** Call on an orderly shutdown so the next launch does not count an OOM. */
  async markCleanExit(): Promise<void> {
    this.marker.clean = true;
    await this.writeMarker();
    this.client.onPageHide(true);
  }

  close(): void {
    this.off.splice(0).forEach((f) => f());
    this.client.close();
  }
}

let current: MobileBugTrace | null = null;

export async function initMobile(options: MobileInitOptions): Promise<MobileBugTrace> {
  current?.close();
  current = await new MobileBugTrace(options).start();
  return current;
}

export function getMobile(): MobileBugTrace | null {
  return current;
}
