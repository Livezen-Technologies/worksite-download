import { Deduper, DEFAULT_RATES, rateFor, sampled } from './limits';
import { allowsAutomatic, cachedPolicy, isFresh, isOptedOut, policyFromTpt, storePolicy } from './policy';
import { defaultStore, Queue } from './queue';
import { Scrubber, templatePath } from './scrub';
import { fingerprint, isChunkLoadError, toException } from './stack';
import type {
  Breadcrumb, BugTraceEvent, CaptureContext, EffectivePolicy, EventType, InitOptions, QueueItem, ReportInput, ReportReceipt, SampleRates, SessionUpdate,
} from './types';

const ENVELOPE_LIMIT = 900_000;
const REPORTS_KEY = 'bt:reports';

export class PolicyDisabledError extends Error {
  constructor(what: string) {
    super(`${what} are turned off by your organisation`);
    this.name = 'PolicyDisabledError';
  }
}

function uid(): string {
  const c = globalThis.crypto;
  if (c?.randomUUID) return c.randomUUID().replace(/-/g, '');
  let s = '';
  for (let i = 0; i < 32; i++) s += Math.floor(Math.random() * 16).toString(16);
  return s;
}

function iso(t = Date.now()): string {
  return new Date(t).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** Coarse client environment for contexts (FR-BT-SDK-05). */
export function environmentContexts(): Record<string, Record<string, unknown>> {
  const nav = typeof navigator !== 'undefined' ? navigator : ({} as Navigator);
  const ua = nav.userAgent || '';
  const pick = (re: RegExp) => re.exec(ua)?.[1];
  const browser =
    (pick(/Edg\/(\d+)/) && { name: 'Edge', version: pick(/Edg\/(\d+)/) }) ||
    (pick(/Electron\/(\d+)/) && { name: 'Electron', version: pick(/Electron\/(\d+)/) }) ||
    (pick(/Firefox\/(\d+)/) && { name: 'Firefox', version: pick(/Firefox\/(\d+)/) }) ||
    (pick(/Chrome\/(\d+)/) && { name: 'Chrome', version: pick(/Chrome\/(\d+)/) }) ||
    (pick(/Version\/(\d+)[\d.]* .*Safari/) && { name: 'Safari', version: pick(/Version\/(\d+)[\d.]* .*Safari/) }) || { name: 'Unknown' };
  const os = /Windows NT/.test(ua) ? { name: 'Windows', version: pick(/Windows NT ([\d.]+)/) }
    : /iPhone|iPad/.test(ua) ? { name: 'iOS', version: pick(/OS (\d+)[_\d]* like/) }
    : /Mac OS X/.test(ua) ? { name: 'macOS', version: pick(/Mac OS X (\d+)/) }
    : /Android/.test(ua) ? { name: 'Android', version: pick(/Android (\d+)/) }
    : /Linux/.test(ua) ? { name: 'Linux' } : { name: 'Unknown' };
  const n = nav as Navigator & { deviceMemory?: number };
  return {
    os,
    browser,
    device: { memory_gb: n.deviceMemory ?? null, cores: nav.hardwareConcurrency ?? null, online: nav.onLine ?? null },
  };
}

export class BugTraceClient {
  readonly opts: Required<Pick<InitOptions, 'key' | 'release' | 'endpoint' | 'environment' | 'app' | 'platform'>> & InitOptions;
  readonly queue: Queue;
  readonly deduper = new Deduper();
  policy: EffectivePolicy | null = null;
  lastEventId: string | null = null;
  lastIssueTitle: string | null = null;
  readonly sessionId = uid();
  private crumbs: Breadcrumb[] = [];
  private pending: { events: BugTraceEvent[]; sessions: SessionUpdate[] } = { events: [], sessions: [] };
  private rates: SampleRates;
  private crashed = false;
  private ended = false;
  private timer: ReturnType<typeof setTimeout> | null = null;
  private flushing: Promise<void> | null = null;
  private area: string | undefined;
  private closed = false;
  private readonly started = iso();
  private readonly fetchImpl: typeof fetch;

  constructor(options: InitOptions) {
    this.opts = {
      endpoint: 'https://download.worksuitecloud.com',
      environment: 'production',
      app: 'lk.worksuite.web',
      platform: 'web',
      ...options,
    };
    this.opts.endpoint = this.opts.endpoint.replace(/\/+$/, '');
    this.fetchImpl = options.fetch ?? ((...a: Parameters<typeof fetch>) => fetch(...a));
    this.queue = new Queue(options.storage ?? defaultStore());
    this.rates = { ...DEFAULT_RATES, ...options.sampleRates };
    this.policy = policyFromTpt(this.tpt()) ?? cachedPolicy(this.opts.key);
    this.applyPolicyRates();
  }

  tpt(): string | null {
    const t = this.opts.tpt;
    return (typeof t === 'function' ? t() : t) ?? null;
  }

  private applyPolicyRates(): void {
    if (this.policy?.sample_rates) this.rates = { ...DEFAULT_RATES, ...this.policy.sample_rates, ...this.opts.sampleRates };
  }

  setUser(userRef: string | undefined): void {
    this.opts.userRef = userRef;
  }

  setArea(area: string | undefined): void {
    this.area = area;
  }

  // ------------------------------------------------------------ breadcrumbs

  addBreadcrumb(b: Omit<Breadcrumb, 't'> & { t?: string }): void {
    const s = new Scrubber(this.opts.scrubPatterns);
    const crumb: Breadcrumb = { t: b.t ?? iso(), category: b.category };
    if (b.level) crumb.level = b.level;
    if (b.message) crumb.message = s.str(String(b.message)).slice(0, 300);
    if (b.data) crumb.data = s.value(b.data) as Breadcrumb['data'];
    this.crumbs.push(crumb);
    if (this.crumbs.length > 50) this.crumbs.shift();
  }

  breadcrumbs(): Breadcrumb[] {
    return this.crumbs.slice();
  }

  // --------------------------------------------------------------- capture

  captureException(error: unknown, ctx: CaptureContext = {}): string | null {
    const handled = ctx.handled ?? true;
    if (isChunkLoadError(error) && this.opts.ignoreChunkLoadErrors !== false && !handled) {
      this.addBreadcrumb({ category: 'chunk-reload', level: 'warning', message: 'Stale chunk after deploy' });
      return null;
    }
    const type: EventType = ctx.type ?? 'error';
    return this.capture(type, handled, toException(error, handled, handled ? 'generic' : ctx.type === 'crash' ? 'errorboundary' : 'onerror'), undefined, ctx);
  }

  captureMessage(message: string, level = 'info', ctx: CaptureContext = {}): string | null {
    return this.capture(ctx.type ?? 'message', ctx.handled ?? true, undefined, message, { ...ctx, level });
  }

  private capture(type: EventType, handled: boolean, ex: BugTraceEvent['exception'][0] | undefined, message: string | undefined, ctx: CaptureContext): string | null {
    if (this.closed) return null;
    if (this.policy && !allowsAutomatic(this.policy, type, isOptedOut())) return null;
    if (!this.policy && isOptedOut()) return null;
    const rate = rateFor(type, handled, this.rates);
    if (!sampled(rate)) return null;
    const key = type + ':' + fingerprint(ex, message);
    if (!this.deduper.admit(key)) return null;

    const s = new Scrubber(this.opts.scrubPatterns);
    const contexts = environmentContexts();
    contexts.app = { area: ctx.area ?? this.area ?? null, route: typeof location !== 'undefined' ? templatePath(location.pathname) : null };
    if (ex) {
      ex.value = s.str(ex.value).slice(0, 2000);
      ex.stacktrace.frames = ex.stacktrace.frames.map((f) => (f.file ? { ...f, file: s.url(f.file) } : f));
    }
    let ev: BugTraceEvent | null = {
      event_id: uid(),
      type,
      app: this.opts.app,
      platform: this.opts.platform,
      release: this.opts.release,
      environment: this.opts.environment,
      installation_ref: this.opts.installationRef,
      edition: this.opts.edition,
      user_ref: this.opts.userRef,
      session_id: this.sessionId,
      timestamp: iso(),
      sample_rate: rate,
      exception: ex ? [ex] : [],
      contexts: s.value(contexts) as BugTraceEvent['contexts'],
      breadcrumbs: this.breadcrumbs(),
      tags: {
        locale: typeof navigator !== 'undefined' ? navigator.language : 'und',
        ...Object.fromEntries(Object.entries(ctx.tags ?? {}).map(([k, v]) => [k, s.str(String(v)).slice(0, 200)])),
      },
    };
    if (message !== undefined) ev.message = s.str(message).slice(0, 2000);
    if (ctx.level) ev.level = ctx.level;
    if (ctx.extra) ev.extra = s.value(ctx.extra) as Record<string, unknown>;
    if (this.opts.beforeSend) ev = this.opts.beforeSend(ev);
    if (!ev) return null;

    this.lastEventId = ev.event_id;
    this.lastIssueTitle = ex ? `${ex.type}: ${ex.value}` : ev.message ?? null;
    this.pending.events.push(ev);
    if (type === 'crash' && !this.crashed && this.opts.sessions !== false) {
      this.crashed = true;
      this.pending.sessions.push({ sid: this.sessionId, status: 'crashed', release: this.opts.release, user_ref: this.opts.userRef, started: this.started });
    }
    this.schedule(type === 'crash' ? 0 : 1000);
    return ev.event_id;
  }

  startSession(): void {
    if (this.opts.sessions === false) return;
    this.pending.sessions.push({ sid: this.sessionId, init: true, status: 'started', release: this.opts.release, user_ref: this.opts.userRef, started: this.started });
    this.schedule(5000);
  }

  // ------------------------------------------------------------- transport

  private schedule(ms: number): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => void this.flush(), ms);
  }

  private headers(json = true): Record<string, string> {
    const h: Record<string, string> = { 'X-BT-Key': this.opts.key };
    if (json) h['Content-Type'] = 'application/json';
    const tpt = this.tpt();
    if (tpt) h['X-WorkSuite-Tenant-Policy'] = tpt;
    return h;
  }

  /** Effective policy: TPT claim, else GET /bugtrace/policy (cached 24 h). */
  async refreshPolicy(force = false): Promise<EffectivePolicy | null> {
    const fromTpt = policyFromTpt(this.tpt());
    if (fromTpt) return this.setPolicy(fromTpt);
    if (!force && this.policy && isFresh(this.opts.key)) return this.policy;
    try {
      const res = await this.fetchImpl(`${this.opts.endpoint}/api/v1/bugtrace/policy`, { headers: this.headers(false), credentials: 'omit' });
      if (res.ok) return this.setPolicy((await res.json()) as EffectivePolicy);
    } catch {
      /* offline — keep the cached policy */
    }
    return this.policy;
  }

  private setPolicy(p: EffectivePolicy): EffectivePolicy {
    this.policy = p;
    storePolicy(this.opts.key, p);
    this.applyPolicyRates();
    if (p.policy === 'OFF') void this.discardAutomatic(); // WF-C38: queued unsent events are discarded
    return p;
  }

  private async discardAutomatic(): Promise<void> {
    this.pending = { events: [], sessions: [] };
    for (const item of await this.queue.store.all()) if (item.kind === 'envelope') await this.queue.done(item);
  }

  private async body(json: string): Promise<{ body: BodyInit; gzip: boolean }> {
    const CS = (globalThis as { CompressionStream?: typeof CompressionStream }).CompressionStream;
    if (CS && typeof Blob !== 'undefined' && typeof Response !== 'undefined') {
      try {
        const stream = new Blob([json]).stream().pipeThrough(new CS('gzip'));
        return { body: await new Response(stream).arrayBuffer(), gzip: true };
      } catch {
        /* fall back to plain JSON */
      }
    }
    return { body: json, gzip: false };
  }

  /** Move pending data to the persistent queue and send what is due. Never throws. */
  flush(): Promise<void> {
    // Serialised: a flush requested while one runs is chained after it (never lost).
    const run = (this.flushing ?? Promise.resolve()).then(() => this.doFlush()).catch(() => undefined);
    this.flushing = run;
    void run.then(() => {
      if (this.flushing === run) this.flushing = null;
    });
    return run;
  }

  private async doFlush(): Promise<void> {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    const batch = this.pending;
    this.pending = { events: [], sessions: [] };
    if (batch.events.length || batch.sessions.length) {
      const json = JSON.stringify(batch);
      await this.queue.push('envelope', batch, json.length);
    }
    const policy = await this.refreshPolicy();
    for (const item of await this.queue.due()) {
      if (item.kind === 'report') {
        if (policy && !policy.user_reports) {
          await this.queue.done(item);
          continue;
        }
        await this.sendQueuedReport(item);
        continue;
      }
      if (!policy) return; // nothing leaves the device before the policy is known
      const env = item.body as { events: BugTraceEvent[]; sessions: SessionUpdate[] };
      const optedOut = isOptedOut();
      const events = env.events.filter((e) => allowsAutomatic(policy, e.type, optedOut));
      const sessions = policy.policy === 'OFF' || (optedOut && policy.user_opt_out_allowed) ? [] : env.sessions;
      if (!events.length && !sessions.length) {
        await this.queue.done(item);
        continue;
      }
      const status = await this.post('/api/v1/bugtrace/envelope', JSON.stringify({ events, sessions }));
      await this.settle(item, status);
    }
  }

  private async settle(item: QueueItem, status: number): Promise<void> {
    if (status === 0 || status === 429 || status >= 500) await this.queue.retry(item);
    else await this.queue.done(item); // 2xx or permanent 4xx (never retried)
  }

  private async post(path: string, json: string): Promise<number> {
    try {
      const { body, gzip } = await this.body(json);
      const headers = this.headers();
      if (gzip) headers['Content-Encoding'] = 'gzip';
      const res = await this.fetchImpl(this.opts.endpoint + path, { method: 'POST', headers, body, credentials: 'omit', keepalive: json.length < 60_000 });
      if (res.status === 202) {
        const j = (await res.json().catch(() => null)) as { policy?: EffectivePolicy } | null;
        if (j?.policy && !policyFromTpt(this.tpt())) this.setPolicy(j.policy);
      }
      return res.status;
    } catch {
      return 0;
    }
  }

  /** Page is going away: end the session and beacon pending events (no gzip possible synchronously). */
  onPageHide(final = true): void {
    if (final && this.opts.sessions !== false && !this.crashed && !this.ended) {
      this.ended = true;
      this.pending.sessions.push({ sid: this.sessionId, status: 'exited', release: this.opts.release, user_ref: this.opts.userRef, started: this.started });
    }
    const batch = this.pending;
    if (!batch.events.length && !batch.sessions.length) return;
    const p = this.policy;
    if (!p || p.policy === 'OFF') return;
    const optedOut = isOptedOut();
    const payload = {
      events: batch.events.filter((e) => allowsAutomatic(p, e.type, optedOut)),
      sessions: optedOut && p.user_opt_out_allowed ? [] : batch.sessions,
      tpt: this.tpt() ?? undefined,
    };
    const json = JSON.stringify(payload);
    const nav = typeof navigator !== 'undefined' ? navigator : null;
    if (json.length < ENVELOPE_LIMIT && nav?.sendBeacon?.(`${this.opts.endpoint}/api/v1/bugtrace/envelope?bt_key=${encodeURIComponent(this.opts.key)}`, new Blob([json], { type: 'text/plain' }))) {
      this.pending = { events: [], sessions: [] };
    } else {
      void this.flush();
    }
  }

  // --------------------------------------------------------------- reports

  /**
   * Send a user bug report (FR-BT-REP-01..08). Offline → queued; the BR-
   * reference arrives later through onReportAccepted / myReports().
   */
  async sendReport(input: ReportInput): Promise<ReportReceipt | { queued: true }> {
    const policy = this.policy ?? (await this.refreshPolicy());
    if (policy && !policy.user_reports) throw new PolicyDisabledError('Bug reports');
    const s = new Scrubber(this.opts.scrubPatterns);
    const tech = input.includeTechnical !== false;
    const body = {
      client_report_id: uid(),
      app: this.opts.app,
      release: this.opts.release,
      title: s.str(input.title).slice(0, 255),
      description: s.str(input.description ?? ''),
      steps: (input.steps ?? []).map((x) => s.str(x)).slice(0, 30),
      expected: s.str(input.expected ?? ''),
      severity: input.severity,
      category: input.category,
      related_event_id: tech ? this.lastEventId : null,
      context: tech ? this.technicalDetails() : {},
      user_ref: this.opts.userRef,
      installation_ref: this.opts.installationRef,
      contact: input.contactConsent && this.opts.contactEmail ? { consent: true, email: this.opts.contactEmail } : { consent: false },
      attachments: [] as { kind: string; upload_id: string; annotated?: boolean; redacted_regions?: number }[],
    };
    const item: QueueItem = {
      kind: 'report',
      body: {
        report: body,
        screenshot: policy?.screenshots === false ? null : input.screenshot ?? null,
        meta: input.screenshotMeta,
        logs: input.logs && policy?.logs !== false ? new Scrubber(this.opts.scrubPatterns).str(input.logs).slice(0, 2_000_000) : null,
      },
      size: JSON.stringify(body).length + (input.screenshot?.size ?? 0) + (input.logs?.length ?? 0),
      created: Date.now(),
      attempts: 0,
      next: 0,
    };
    const receipt = await this.trySendReport(item);
    if (receipt) return receipt;
    await this.queue.push('report', item.body, item.size);
    return { queued: true };
  }

  /** Context attached to reports and shown under "What is sent?". */
  technicalDetails(): Record<string, unknown> {
    const s = new Scrubber(this.opts.scrubPatterns);
    const ctx = environmentContexts();
    return s.value({
      ...ctx,
      app: { app: this.opts.app, release: this.opts.release, area: this.area ?? null, route: typeof location !== 'undefined' ? templatePath(location.pathname) : null, locale: typeof navigator !== 'undefined' ? navigator.language : null },
      last_error: this.lastEventId ? { event_id: this.lastEventId, title: this.lastIssueTitle } : null,
      breadcrumbs: this.breadcrumbs().slice(-20),
    }) as Record<string, unknown>;
  }

  private async sendQueuedReport(item: QueueItem): Promise<void> {
    try {
      const receipt = await this.trySendReport(item);
      if (receipt) await this.queue.done(item);
      else await this.queue.retry(item); // keeps uploaded attachment ids (_att/_log) for the retry
    } catch {
      await this.queue.done(item); // permanently rejected (policy / validation)
    }
  }

  private async upload(kind: string, blob: Blob | string, type: string): Promise<string | null> {
    try {
      const res = await this.fetchImpl(`${this.opts.endpoint}/api/v1/bugtrace/attachments?kind=${kind}`, { method: 'POST', headers: { ...this.headers(false), 'Content-Type': type }, body: blob, credentials: 'omit' });
      if (res.status === 201) return ((await res.json()) as { id: string }).id;
      if (res.status >= 400 && res.status < 500 && res.status !== 429) return '';
      return null;
    } catch {
      return null;
    }
  }

  /** Returns the receipt, or null to retry later. Permanent rejections resolve to a synthetic receipt-less drop. */
  private async trySendReport(item: QueueItem): Promise<ReportReceipt | null> {
    const b = item.body as { report: Record<string, unknown> & { attachments: unknown[]; title: string }; screenshot: Blob | null; meta?: { annotated: boolean; redacted_regions: number }; logs: string | null; _att?: string; _log?: string };
    if (b.screenshot && b._att === undefined) {
      const id = await this.upload('screenshot', b.screenshot, b.screenshot.type || 'image/png');
      if (id === null) return null;
      b._att = id;
    }
    if (b.logs && b._log === undefined) {
      const id = await this.upload('log', b.logs, 'text/plain');
      if (id === null) return null;
      b._log = id;
    }
    const attachments = [];
    if (b._att) attachments.push({ kind: 'screenshot', upload_id: b._att, ...(b.meta ?? {}) });
    if (b._log) attachments.push({ kind: 'log', upload_id: b._log });
    const tpt = this.tpt();
    try {
      const { body, gzip } = await this.body(JSON.stringify({ ...b.report, attachments, tpt: tpt ?? undefined }));
      const headers = this.headers();
      if (gzip) headers['Content-Encoding'] = 'gzip';
      const res = await this.fetchImpl(`${this.opts.endpoint}/api/v1/bugtrace/reports`, { method: 'POST', headers, body, credentials: 'omit' });
      if (res.status === 201 || res.status === 200) {
        const r = (await res.json()) as ReportReceipt;
        rememberReport({ ...r, title: b.report.title, created_at: iso() });
        this.opts.onReportAccepted?.({ ...r, title: b.report.title });
        return r;
      }
      if (res.status === 403) throw new PolicyDisabledError('Bug reports');
      if (res.status >= 400 && res.status < 500 && res.status !== 429) throw new Error(`Report rejected (${res.status})`);
      return null;
    } catch (e) {
      if (e instanceof Error && (e.name === 'PolicyDisabledError' || e.message.startsWith('Report rejected'))) throw e;
      return null;
    }
  }

  /** Reporter status for "My bug reports" (FR-BT-REP-07). */
  async reportStatus(r: Pick<ReportReceipt, 'status_url' | 'status_token'>): Promise<{ status: string; fixed_in: string | null; updates: { status: string; message: string; created_at: string }[] } | null> {
    try {
      const res = await this.fetchImpl(this.opts.endpoint + r.status_url, { headers: { 'X-BT-Status-Token': r.status_token }, credentials: 'omit' });
      return res.ok ? await res.json() : null;
    } catch {
      return null;
    }
  }

  close(): void {
    this.closed = true;
    if (this.timer) clearTimeout(this.timer);
  }
}

export interface StoredReport extends ReportReceipt {
  title: string;
  created_at: string;
}

function rememberReport(r: StoredReport): void {
  try {
    const list = myReports().filter((x) => x.reference !== r.reference);
    localStorage.setItem(REPORTS_KEY, JSON.stringify([r, ...list].slice(0, 50)));
  } catch {
    /* storage unavailable */
  }
}

/** Reports sent from this device (reference, status URL and token). */
export function myReports(): StoredReport[] {
  try {
    return JSON.parse(localStorage.getItem(REPORTS_KEY) || '[]');
  } catch {
    return [];
  }
}
