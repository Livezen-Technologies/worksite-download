import type { BugTraceClient } from './client';
import { templatePath } from './scrub';

/**
 * Global hooks (FR-BT-SDK-01/06): window.onerror, unhandledrejection, fetch
 * breadcrumbs (method, templated path without query, status, duration),
 * navigation, UI element labels and lifecycle. Never input values or bodies.
 * Returns an uninstall function.
 */
export function instrument(client: BugTraceClient): () => void {
  if (typeof window === 'undefined') return () => undefined;
  const off: (() => void)[] = [];
  const on = <K extends keyof WindowEventMap>(t: K, fn: (e: WindowEventMap[K]) => void, target: Window | Document = window, capture = false) => {
    target.addEventListener(t, fn as EventListener, capture);
    off.push(() => target.removeEventListener(t, fn as EventListener, capture));
  };
  const o = client.opts;

  if (o.captureGlobalErrors !== false) {
    on('error', (e: ErrorEvent) => {
      if (!(e instanceof ErrorEvent) || (!e.error && !e.message)) return; // resource load errors
      client.captureException(e.error ?? new Error(e.message), { handled: false, type: 'error' });
    });
    on('unhandledrejection', (e: PromiseRejectionEvent) => {
      client.captureException(e.reason ?? new Error('Unhandled rejection'), { handled: false, type: 'error', tags: { mechanism: 'unhandledrejection' } });
    });
  }

  if (o.instrumentFetch !== false && typeof window.fetch === 'function') {
    const orig = window.fetch;
    const endpoint = o.endpoint;
    const wrapped = async function (this: unknown, input: RequestInfo | URL, init?: RequestInit) {
      const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
      const method = (init?.method || (typeof input === 'object' && 'method' in input ? input.method : 'GET') || 'GET').toUpperCase();
      if (url.startsWith(endpoint + '/api/v1/bugtrace')) return orig.call(window, input, init);
      const t0 = performance.now();
      let path = url;
      try {
        path = templatePath(new URL(url, location.href).pathname);
      } catch {
        path = templatePath(url.split(/[?#]/)[0]);
      }
      try {
        const res = await orig.call(window, input, init);
        client.addBreadcrumb({ category: 'http', level: res.status >= 500 ? 'error' : res.status >= 400 ? 'warning' : 'info', data: { method, path, status: res.status, ms: Math.round(performance.now() - t0) } });
        return res;
      } catch (err) {
        client.addBreadcrumb({ category: 'http', level: 'error', data: { method, path, status: 0, ms: Math.round(performance.now() - t0) } });
        throw err;
      }
    } as typeof fetch;
    window.fetch = wrapped;
    off.push(() => {
      if (window.fetch === wrapped) window.fetch = orig;
    });
  }

  // Navigation (SPA history + popstate).
  let last = templatePath(location.pathname);
  const nav = () => {
    const to = templatePath(location.pathname);
    if (to !== last) client.addBreadcrumb({ category: 'navigation', data: { from: last, to } });
    last = to;
  };
  for (const m of ['pushState', 'replaceState'] as const) {
    const orig = history[m];
    history[m] = function (this: History, ...args: Parameters<History['pushState']>) {
      const r = orig.apply(this, args);
      nav();
      return r;
    };
    off.push(() => (history[m] = orig));
  }
  on('popstate', nav);

  // UI element labels (buttons/links/menu items) — never input values.
  on('click', (e: MouseEvent) => {
    const el = (e.target as Element | null)?.closest?.('button,a,[role=button],[role=menuitem],[role=tab]');
    if (!el) return;
    const label = (el.getAttribute('aria-label') || el.getAttribute('title') || (el.textContent || '').trim()).replace(/\s+/g, ' ').slice(0, 60);
    client.addBreadcrumb({ category: 'ui.click', data: { label, target: el.tagName.toLowerCase() } });
  }, document, true);

  // Lifecycle.
  on('online', () => {
    client.addBreadcrumb({ category: 'lifecycle', data: { online: true } });
    void client.flush();
  });
  on('offline', () => client.addBreadcrumb({ category: 'lifecycle', data: { online: false } }));
  on('pagehide', () => client.onPageHide());
  on('visibilitychange', () => {
    if (document.visibilityState === 'hidden') client.onPageHide(false);
  }, document);

  return () => off.forEach((f) => f());
}
