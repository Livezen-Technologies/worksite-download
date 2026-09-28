import { gunzipSync } from 'node:zlib';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as bugtrace from '../src/index';
import { MemoryStore } from '../src/queue';
import type { EffectivePolicy } from '../src/types';

interface Call { url: string; init: RequestInit; json: unknown }

const policy = (p: EffectivePolicy['policy'], extra: Partial<EffectivePolicy> = {}): EffectivePolicy => ({ policy: p, user_reports: true, screenshots: true, logs: true, user_opt_out_allowed: true, ...extra });

async function decode(body: BodyInit | null | undefined): Promise<unknown> {
  if (body == null) return null;
  if (typeof body === 'string') return JSON.parse(body);
  if (body instanceof ArrayBuffer) return JSON.parse(gunzipSync(Buffer.from(body)).toString());
  if (body instanceof Blob) return (body.type || '').startsWith('image') ? '<blob>' : await body.text();
  return String(body);
}

function server(opts: { policy?: EffectivePolicy; offline?: () => boolean } = {}) {
  const calls: Call[] = [];
  let ref = 20000;
  const fetchMock = vi.fn(async (url: string, init: RequestInit = {}) => {
    if (opts.offline?.()) throw new TypeError('Failed to fetch');
    const json = await decode(init.body);
    calls.push({ url, init, json });
    const reply = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    if (url.endsWith('/policy')) return reply(200, opts.policy ?? policy('CRASHES_AND_ERRORS'));
    if (url.endsWith('/envelope')) return reply(202, { ids: [], accepted: 1, policy: opts.policy ?? policy('CRASHES_AND_ERRORS') });
    if (url.includes('/attachments')) return reply(201, { id: 'att_01TEST' });
    if (url.endsWith('/reports')) {
      ref++;
      return reply(201, { reference: `BR-${ref}`, status_url: `/api/v1/bugtrace/reports/BR-${ref}/status`, status_token: 'tok' });
    }
    return reply(404, {});
  });
  return { calls, fetchMock };
}

describe('BugTraceClient end-to-end (mocked Update Center)', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => bugtrace.close());

  it('captures, scrubs and sends an envelope with release, route and breadcrumbs', async () => {
    const s = server();
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '3.4.0+34012', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: new MemoryStore(), userRef: 'u_hmac:1', installationRef: 'inst_hmac:1', edition: 'BUSINESS' });
    bugtrace.addBreadcrumb({ category: 'http', data: { method: 'GET', path: '/api/mail/folders/88?q=1', status: 200, ms: 184 } });
    const err = new TypeError("Cannot read properties of undefined (reading 'folderId') for kasun@example.lk");
    const id = bugtrace.reportBoundaryError(err, { componentStack: '\n    at MailFolderList\n    at MailPage' }, 'Mail');
    expect(id).toMatch(/^[0-9a-f]{32}$/);
    await c.flush();
    const env = s.calls.find((x) => x.url.endsWith('/envelope'))!;
    expect((env.init.headers as Record<string, string>)['X-BT-Key']).toBe('pk_web_test_000001');
    expect(env.init.credentials).toBe('omit');
    const body = env.json as { events: Record<string, any>[]; sessions: Record<string, any>[] };
    const ev = body.events[0];
    expect(ev).toMatchObject({ event_id: id, type: 'crash', release: '3.4.0+34012', app: 'lk.worksuite.web', user_ref: 'u_hmac:1', edition: 'BUSINESS' });
    expect(ev.exception[0].value).not.toContain('kasun@example.lk');
    expect(ev.exception[0].mechanism).toEqual({ type: 'errorboundary', handled: false });
    expect(ev.contexts.app.area).toBe('Mail');
    expect(ev.breadcrumbs[0].data.path).toBe('/api/mail/folders/:id');
    expect(ev.extra.component_stack).toContain('MailFolderList');
    expect(body.sessions.map((x) => x.status)).toEqual(['started', 'crashed']);
  });

  it('policy OFF: nothing leaves the device and queued events are discarded (AC-C72)', async () => {
    const s = server({ policy: policy('OFF') });
    const store = new MemoryStore();
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '1.0.0', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: store });
    await c.refreshPolicy(true);
    expect(bugtrace.captureException(new Error('boom'), { type: 'crash', handled: false })).toBeNull();
    await c.flush();
    expect(s.calls.filter((x) => x.url.endsWith('/envelope'))).toHaveLength(0);
    expect(await store.all()).toHaveLength(0);
  });

  it('respects the user opt-out and CRASHES_ONLY', async () => {
    const s = server({ policy: policy('CRASHES_ONLY') });
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '1.0.0', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: new MemoryStore(), sessions: false });
    await c.refreshPolicy(true);
    expect(bugtrace.captureException(new Error('handled'))).toBeNull();
    expect(bugtrace.captureException(new Error('crash'), { type: 'crash' })).not.toBeNull();
    bugtrace.setOptOut(true);
    expect(bugtrace.captureException(new Error('crash2'), { type: 'crash' })).toBeNull();
    bugtrace.setOptOut(false);
  });

  it('ignores stale chunk reload errors but reports other unhandled rejections (AC-C60)', async () => {
    const s = server();
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '1.0.0', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: new MemoryStore(), sessions: false });
    await c.refreshPolicy(true);
    const reject = (reason: unknown) => {
      const e = new Event('unhandledrejection') as PromiseRejectionEvent;
      Object.defineProperty(e, 'reason', { value: reason });
      window.dispatchEvent(e);
    };
    reject(new TypeError('Failed to fetch dynamically imported module: /assets/mail-1a2.js'));
    reject(new Error('Mailbox sync failed'));
    await c.flush();
    const events = s.calls.filter((x) => x.url.endsWith('/envelope')).flatMap((x) => (x.json as { events: { exception: { value: string }[] }[] }).events);
    expect(events.map((e) => e.exception[0].value)).toEqual(['Mailbox sync failed']);
    expect(c.breadcrumbs().some((b) => b.category === 'chunk-reload')).toBe(true);
  });

  it('de-duplicates identical errors within 60 s', async () => {
    const s = server();
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '1.0.0', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: new MemoryStore(), sessions: false, sampleRates: { error: 1 } });
    await c.refreshPolicy(true);
    const make = () => Object.assign(new Error('same'), { stack: 'Error: same\n    at f (https://x/assets/a-1b2.js:1:10)' });
    expect(bugtrace.captureException(make())).not.toBeNull();
    expect(bugtrace.captureException(make())).toBeNull();
  });

  it('queues offline and retries with backoff; reports get their reference on acceptance (AC-C65)', async () => {
    let offline = true;
    const s = server({ offline: () => offline });
    const accepted: string[] = [];
    const store = new MemoryStore();
    localStorage.setItem('bt:policy:pk_web_test_000001', JSON.stringify({ ...policy('CRASHES_AND_ERRORS'), _at: Date.now() }));
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '1.0.0', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: store, sessions: false, onReportAccepted: (r) => accepted.push(r.reference) });
    bugtrace.captureException(new Error('offline crash'), { type: 'crash' });
    const res = await c.sendReport({ title: 'Mail crashed', severity: 'BLOCKS_WORK', category: 'BUG', screenshot: new Blob(['png'], { type: 'image/png' }), screenshotMeta: { annotated: true, redacted_regions: 1 } });
    expect(res).toEqual({ queued: true });
    await c.flush();
    expect((await store.all()).length).toBe(2);

    offline = false;
    for (const item of await store.all()) await store.update({ ...item, next: 0 });
    await c.flush();
    expect(await store.all()).toHaveLength(0);
    expect(accepted).toEqual(['BR-20001']);
    const report = s.calls.find((x) => x.url.endsWith('/reports'))!.json as Record<string, any>;
    expect(report.attachments).toEqual([{ kind: 'screenshot', upload_id: 'att_01TEST', annotated: true, redacted_regions: 1 }]);
    expect(report.client_report_id).toMatch(/^[0-9a-f]{32}$/);
    expect(bugtrace.myReports()[0]).toMatchObject({ reference: 'BR-20001', title: 'Mail crashed' });
  });

  it('refuses reports when the tenant turned them off', async () => {
    const s = server({ policy: policy('CRASHES_ONLY', { user_reports: false }) });
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '1.0.0', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: new MemoryStore(), sessions: false });
    await c.refreshPolicy(true);
    await expect(c.sendReport({ title: 'x', severity: 'MINOR', category: 'BUG' })).rejects.toThrow(/turned off/);
  });

  it('opens the report dialog with Ctrl+Shift+B and sends from it', async () => {
    const s = server();
    const c = bugtrace.init({ key: 'pk_web_test_000001', release: '1.0.0', endpoint: 'https://uc.test', fetch: s.fetchMock as unknown as typeof fetch, storage: new MemoryStore(), sessions: false, contactEmail: 'hasini@example.lk' });
    await c.refreshPolicy(true);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'B', ctrlKey: true, shiftKey: true }));
    const host = document.querySelector('[data-worksuite-bugtrace]')!;
    const root = host.shadowRoot!;
    expect(root.querySelector('h2')!.textContent).toBe('Report a bug');
    expect(root.textContent).toMatch(/h•{3,6}@example\.lk/);
    (root.querySelector('#bt-t') as HTMLInputElement).value = 'Invoice PDF shows wrong currency';
    [...root.querySelectorAll('.seg button')].find((b) => b.textContent === 'Data looks wrong')!.dispatchEvent(new MouseEvent('click'));
    [...root.querySelectorAll('a')].find((a) => a.textContent === 'What is sent?')!.dispatchEvent(new MouseEvent('click'));
    expect(root.querySelector('pre')!.textContent).toContain('"release": "1.0.0"');
    [...root.querySelectorAll('button')].find((b) => b.textContent === 'Send report')!.dispatchEvent(new MouseEvent('click'));
    await vi.waitFor(() => expect(root.textContent).toContain('BR-20001'));
    const report = s.calls.find((x) => x.url.endsWith('/reports'))!.json as Record<string, any>;
    expect(report).toMatchObject({ title: 'Invoice PDF shows wrong currency', severity: 'DATA_WRONG', category: 'BUG', contact: { consent: false } });
  });
});
