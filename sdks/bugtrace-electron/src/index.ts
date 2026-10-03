/**
 * @worksuite/bugtrace-electron — Bug Trace for the WorkSuite Desktop main
 * process (SRS §18.7, FR-BT-SDK-02). Renderers keep using
 * @worksuite/bugtrace-web (window errors, ErrorBoundary, report dialog).
 *
 *   import * as electron from 'electron';
 *   import { initMain } from '@worksuite/bugtrace-electron';
 *   const bt = initMain({ electron, key: 'pk_desktop_prod_…', release: '2.7.0+27001', tpt: () => license.tpt });
 *   updateAudit.on('failure', (code, detail) => bt.captureUpdaterFailure(code, detail));
 *
 * Captures: main-process uncaught exceptions and unhandled rejections, native
 * crashes as Crashpad minidumps (crashReporter with uploadToServer:false — the
 * SDK uploads after the policy check), GPU / renderer / utility process
 * crashes, window hangs and main-loop stalls > 5 s, and updater failures
 * (ENH-02 error codes). Nothing leaves the device when the effective policy is
 * OFF or the user opted out (FR-BT-SDK-10).
 */
import { readdirSync, readFileSync, statSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { allowsAutomatic, BugTraceClient, type CaptureContext, type EffectivePolicy, type InitOptions } from '@worksuite/bugtrace-web';
import type { ElectronLike, Emitter, GoneDetails } from './electron-types';
import { FileStore, OptOutFlag } from './file-store';

export type * from './electron-types';
export { FileStore, OptOutFlag };

export interface MainInitOptions extends Omit<InitOptions, 'storage' | 'reportShortcut' | 'captureGlobalErrors' | 'instrumentFetch'> {
  electron: ElectronLike;
  /** Hang threshold for windows and the main event loop (default 5000 ms). */
  hangThresholdMs?: number;
  /** Keep the default Node behaviour of exiting after an uncaught exception (default true). */
  exitOnUncaught?: boolean;
  /** Override the minidump directory (default app.getPath('crashDumps')). */
  crashDumpsDir?: string;
}

const MAX_DUMP_BYTES = 20 * 1024 * 1024;

function nodePlatform(): string {
  return process.platform === 'win32' ? 'windows' : process.platform === 'darwin' ? 'macos' : 'linux';
}

/** Recursively lists *.dmp files (Crashpad keeps them under reports/, pending/ or completed/). */
export function findMinidumps(dir: string, depth = 3): string[] {
  const out: string[] = [];
  let entries: string[];
  try {
    entries = readdirSync(dir);
  } catch {
    return out;
  }
  for (const name of entries) {
    const p = join(dir, name);
    try {
      const st = statSync(p);
      if (st.isDirectory() && depth > 0) out.push(...findMinidumps(p, depth - 1));
      else if (st.isFile() && name.toLowerCase().endsWith('.dmp')) out.push(p);
    } catch {
      /* vanished */
    }
  }
  return out;
}

export class MainProcessBugTrace {
  readonly client: BugTraceClient;
  private readonly off: (() => void)[] = [];
  private readonly optOut: OptOutFlag;
  private readonly hangMs: number;
  private readonly dumpsDir: string;
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly o: MainInitOptions) {
    const { app } = o.electron;
    const userData = app.getPath('userData');
    this.optOut = new OptOutFlag(join(userData, 'bugtrace', 'optout'));
    this.hangMs = o.hangThresholdMs ?? 5000;
    this.fetchImpl = o.fetch ?? ((...a: Parameters<typeof fetch>) => fetch(...a));
    this.dumpsDir = o.crashDumpsDir ?? app.getPath('crashDumps');
    this.client = new BugTraceClient({
      app: 'lk.worksuite.desktop',
      platform: nodePlatform(),
      ...o,
      fetch: this.fetchImpl,
      storage: new FileStore(join(userData, 'bugtrace', 'queue.json')),
      reportShortcut: false,
      // Opted-out users send no session pings either (takes effect from the next start).
      sessions: o.sessions !== false && !this.optOut.get(),
    });
  }

  /** Policy gate shared by every capture path (FR-BT-SDK-10). */
  allows(type: 'crash' | 'error' | 'hang'): boolean {
    return allowsAutomatic(this.client.policy, type, this.optOut.get());
  }

  start(): this {
    const { app, crashReporter } = this.o.electron;
    // Crashpad writes minidumps locally; the SDK uploads them after the policy check.
    crashReporter.start({
      submitURL: `${this.client.opts.endpoint}/api/v1/bugtrace/minidump`,
      uploadToServer: false,
      compress: true,
      extra: { release: this.client.opts.release },
    });

    const onUncaught = (err: Error) => {
      this.capture(err, { type: 'crash', handled: false, tags: { process_type: 'browser' } });
      void this.client.flush().finally(() => {
        if (this.o.exitOnUncaught !== false) process.exit(1);
      });
    };
    const onRejection = (reason: unknown) => this.capture(reason, { type: 'error', handled: false, tags: { process_type: 'browser', mechanism: 'unhandledrejection' } });
    process.on('uncaughtException', onUncaught);
    process.on('unhandledRejection', onRejection);
    this.off.push(() => process.removeListener('uncaughtException', onUncaught), () => process.removeListener('unhandledRejection', onRejection));

    this.listen(app, 'render-process-gone', (_e: unknown, _wc: unknown, d: GoneDetails) => this.processGone('renderer', d));
    this.listen(app, 'child-process-gone', (_e: unknown, d: GoneDetails) => this.processGone((d.type ?? 'utility').toLowerCase(), d));
    this.listen(app, 'browser-window-created', (_e: unknown, win: Emitter) => this.watchWindow(win));
    this.listen(app, 'before-quit', () => this.client.onPageHide(true));
    this.watchMainLoop();

    this.client.startSession();
    void this.client.refreshPolicy().then(async () => {
      await this.uploadMinidumps();
      await this.client.flush();
    });
    return this;
  }

  private listen(em: Emitter, event: string, fn: (...a: any[]) => void): void {
    const safe = (...a: any[]) => {
      try {
        fn(...a);
      } catch {
        /* Bug Trace must never break the app (NFR-C21) */
      }
    };
    em.on(event, safe);
    this.off.push(() => em.removeListener(event, safe));
  }

  private capture(error: unknown, ctx: CaptureContext): string | null {
    const type = (ctx.type ?? 'error') as 'crash' | 'error' | 'hang';
    if (!this.allows(type)) return null;
    try {
      return this.client.captureException(error, ctx);
    } catch {
      return null;
    }
  }

  private message(message: string, ctx: CaptureContext): string | null {
    const type = (ctx.type ?? 'error') as 'crash' | 'error' | 'hang';
    if (!this.allows(type)) return null;
    try {
      return this.client.captureMessage(message, ctx.level ?? 'error', ctx);
    } catch {
      return null;
    }
  }

  /** GPU / renderer / utility process crash (the minidump, if any, follows on next start). */
  processGone(processType: string, d: GoneDetails): string | null {
    if (d.reason === 'clean-exit') return null;
    const tags: Record<string, string> = { process_type: processType, reason: d.reason, exit_code: String(d.exitCode ?? '') };
    if (d.name || d.serviceName) tags.process_name = String(d.name ?? d.serviceName);
    const type = d.reason === 'killed' ? 'error' : 'crash';
    return this.message(`${processType} process gone: ${d.reason}`, { type, handled: false, tags });
  }

  private watchWindow(win: Emitter): void {
    let timer: ReturnType<typeof setTimeout> | null = null;
    // Electron emits `unresponsive` after the renderer stops responding; report once the hang exceeds the threshold.
    this.listen(win, 'unresponsive', () => {
      if (timer) return;
      const since = Date.now();
      timer = setTimeout(() => this.message(`Window not responding for more than ${Math.round(this.hangMs / 1000)} s`, { type: 'hang', handled: false, tags: { process_type: 'renderer', hang_ms: String(Date.now() - since) } }), this.hangMs);
    });
    const clear = () => {
      if (timer) clearTimeout(timer);
      timer = null;
    };
    this.listen(win, 'responsive', clear);
    this.listen(win, 'closed', clear);
  }

  /** Main event loop watchdog: a 1 s tick that arrives late by more than the threshold is a hang. */
  private watchMainLoop(): void {
    let last = Date.now();
    const tick = setInterval(() => {
      const now = Date.now();
      const lag = now - last - 1000;
      last = now;
      if (lag > this.hangMs) this.message(`Main process blocked for ${Math.round(lag / 1000)} s`, { type: 'hang', handled: false, tags: { process_type: 'browser', hang_ms: String(lag) } });
    }, 1000);
    (tick as { unref?: () => void }).unref?.();
    this.off.push(() => clearInterval(tick));
  }

  /** ENH-02 updater failures (UPD-E01 …) as handled errors (FR-BT-SDK-02). */
  captureUpdaterFailure(code: string, detail?: string | Error, extra?: Record<string, unknown>): string | null {
    const msg = `Updater failure ${code}${detail ? ': ' + (detail instanceof Error ? detail.message : detail) : ''}`;
    return this.message(msg, { type: 'error', handled: true, level: 'error', area: 'Updater', tags: { updater_code: code, process_type: 'browser' }, extra });
  }

  captureException(error: unknown, ctx: CaptureContext = {}): string | null {
    return this.capture(error, { tags: { process_type: 'browser' }, ...ctx });
  }

  setOptOut(v: boolean): void {
    this.optOut.set(v);
  }

  isOptOut(): boolean {
    return this.optOut.get();
  }

  /**
   * Uploads Crashpad minidumps left by previous runs, then deletes them. With
   * the policy OFF (or the user opted out) they are deleted unsent — nothing
   * leaves the device (WF-C38).
   */
  async uploadMinidumps(): Promise<{ sent: number; discarded: number }> {
    let sent = 0;
    let discarded = 0;
    const policy: EffectivePolicy | null = this.client.policy;
    for (const file of findMinidumps(this.dumpsDir)) {
      if (!policy) break; // policy unknown (offline, no TPT): keep dumps for a later start
      if (!this.allows('crash')) {
        this.remove(file);
        discarded++;
        continue;
      }
      const status = await this.postMinidump(file);
      if (status === null || status === 429 || status >= 500) continue; // retry next start
      this.remove(file); // accepted, or permanently rejected (4xx)
      if (status < 300) sent++;
      else discarded++;
    }
    return { sent, discarded };
  }

  private remove(file: string): void {
    try {
      unlinkSync(file);
    } catch {
      /* ignore */
    }
  }

  private async postMinidump(file: string): Promise<number | null> {
    let bytes: Buffer;
    try {
      if (statSync(file).size > MAX_DUMP_BYTES) return 413;
      bytes = readFileSync(file);
    } catch {
      return null;
    }
    const c = this.client.opts;
    const form = new FormData();
    form.append('upload_file_minidump', new Blob([new Uint8Array(bytes)], { type: 'application/octet-stream' }), 'crash.dmp');
    const fields: Record<string, string | undefined> = {
      release: c.release,
      platform: c.platform,
      environment: c.environment,
      installation_ref: c.installationRef,
      edition: c.edition,
      user_ref: c.userRef,
      session_id: this.client.sessionId,
      process_type: 'browser',
    };
    for (const [k, v] of Object.entries(fields)) if (v) form.append(k, v);
    const headers: Record<string, string> = { 'X-BT-Key': c.key };
    const tpt = this.client.tpt();
    if (tpt) headers['X-WorkSuite-Tenant-Policy'] = tpt;
    try {
      const res = await this.fetchImpl(`${c.endpoint}/api/v1/bugtrace/minidump`, { method: 'POST', headers, body: form });
      return res.status;
    } catch {
      return null;
    }
  }

  close(): void {
    this.off.splice(0).forEach((f) => f());
    this.client.close();
  }
}

let current: MainProcessBugTrace | null = null;

/** Initialise in the main process before creating windows. */
export function initMain(options: MainInitOptions): MainProcessBugTrace {
  current?.close();
  current = new MainProcessBugTrace(options).start();
  return current;
}

export function getMain(): MainProcessBugTrace | null {
  return current;
}
