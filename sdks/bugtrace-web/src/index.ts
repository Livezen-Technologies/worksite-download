/**
 * @worksuite/bugtrace-web — WorkSuite Bug Trace SDK (SRS §18.7).
 *
 *   import * as bugtrace from '@worksuite/bugtrace-web';
 *   bugtrace.init({ key: 'pk_web_prod_…', release: import.meta.env.VITE_RELEASE, tpt: () => session.tpt });
 *   // ErrorBoundary.componentDidCatch(error, info):
 *   const id = bugtrace.reportBoundaryError(error, info, 'Mail');
 */
import { BugTraceClient, myReports, PolicyDisabledError } from './client';
import { instrument } from './instrument';
import { isOptedOut, setOptOut as storeOptOut } from './policy';
import { openReportDialog, type DialogOptions } from './report-dialog';
import type { Breadcrumb, CaptureContext, InitOptions, ReportInput } from './types';

export type * from './types';
export { BugTraceClient, myReports, PolicyDisabledError, openReportDialog };
export { Annotator, captureScreen, maskEmail } from './report-dialog';
export { Scrubber, templatePath } from './scrub';
export { parseStack, isChunkLoadError } from './stack';
export { MemoryStore, IdbStore, Queue } from './queue';
export { Deduper } from './limits';
export { allowsAutomatic, policyFromTpt } from './policy';

let client: BugTraceClient | null = null;
let uninstall: (() => void) | null = null;

/** Initialise before the app renders. Safe to call once; later calls replace the client. */
export function init(options: InitOptions): BugTraceClient {
  close();
  const c = new BugTraceClient(options);
  client = c;
  const off = [instrument(c)];
  if (options.reportShortcut !== false && typeof document !== 'undefined') {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'B' || e.key === 'b')) {
        e.preventDefault();
        void showReportDialog();
      }
    };
    document.addEventListener('keydown', onKey);
    off.push(() => document.removeEventListener('keydown', onKey));
  }
  uninstall = () => off.forEach((f) => f());
  c.startSession();
  void c.refreshPolicy().then(() => c.flush());
  return c;
}

export function getClient(): BugTraceClient | null {
  return client;
}

/** Report an error. Returns the event id (for "Report this problem"), or null when not sent. */
export function captureException(error: unknown, ctx?: CaptureContext): string | null {
  try {
    return client?.captureException(error, ctx) ?? null;
  } catch {
    return null; // Bug Trace must never break the app (NFR-C21)
  }
}

export function captureMessage(message: string, level?: string, ctx?: CaptureContext): string | null {
  try {
    return client?.captureMessage(message, level, ctx) ?? null;
  } catch {
    return null;
  }
}

export function addBreadcrumb(b: Omit<Breadcrumb, 't'> & { t?: string }): void {
  try {
    client?.addBreadcrumb(b);
  } catch {
    /* never throw */
  }
}

/**
 * React ErrorBoundary helper (FR-BT-SDK-01, WF-C30): call from
 * componentDidCatch before rendering the fallback. Framework-free — pass the
 * `errorInfo` React gives you. Returns the event id for the fallback's
 * "Report this problem" button.
 */
export function reportBoundaryError(error: unknown, errorInfo?: { componentStack?: string | null }, area?: string): string | null {
  const componentStack = errorInfo?.componentStack?.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 15).join('\n');
  return captureException(error, { type: 'crash', handled: false, area, extra: componentStack ? { component_stack: componentStack } : undefined });
}

/** Section of the app (Mail, CRM …) attached to later events. */
export function setArea(area: string | undefined): void {
  client?.setArea(area);
}

export function setUser(userRef: string | undefined): void {
  client?.setUser(userRef);
}

/** The user's own opt-out of automatic reports (FR-BT-PRV-03). Ignored when the tenant requires reporting. */
export function setOptOut(optOut: boolean): void {
  storeOptOut(optOut);
}

export function isOptOut(): boolean {
  return isOptedOut();
}

export function canOptOut(): boolean {
  return client?.policy?.user_opt_out_allowed ?? true;
}

export function lastEventId(): string | null {
  return client?.lastEventId ?? null;
}

/** Opens the "Report a bug" dialog (Help menu, Ctrl/Cmd+Shift+B, ErrorBoundary fallback). */
export function showReportDialog(o?: DialogOptions) {
  if (!client) return Promise.resolve(null);
  return openReportDialog(client, o);
}

/** Programmatic report (mobile/desktop shells, custom forms). */
export function sendReport(input: ReportInput) {
  if (!client) return Promise.reject(new Error('bugtrace.init() was not called'));
  return client.sendReport(input);
}

export function flush(): Promise<void> {
  return client?.flush() ?? Promise.resolve();
}

export function close(): void {
  uninstall?.();
  uninstall = null;
  client?.close();
  client = null;
}
