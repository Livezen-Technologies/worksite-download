/** Public types of @worksuite/bugtrace-web (envelope format: SRS §18.4). */

export type EventType = 'crash' | 'error' | 'hang' | 'message';
export type Policy = 'OFF' | 'CRASHES_ONLY' | 'CRASHES_AND_ERRORS';

export interface Frame {
  file?: string;
  function?: string;
  line?: number;
  col?: number;
  in_app?: boolean;
  /** Native frames: module name, Breakpad debug id and module-relative offset (hex), or Java class. */
  module?: string;
  debug_id?: string;
  module_offset?: string;
  instruction_addr?: string;
}

export interface ExceptionValue {
  type: string;
  value: string;
  mechanism?: { type: string; handled: boolean };
  stacktrace: { frames: Frame[] };
}

export interface Breadcrumb {
  t: string;
  category: string;
  level?: 'info' | 'warning' | 'error';
  message?: string;
  data?: Record<string, string | number | boolean | null>;
}

export interface BugTraceEvent {
  event_id: string;
  type: EventType;
  app: string;
  platform: string;
  release: string;
  environment: string;
  installation_ref?: string;
  edition?: string;
  user_ref?: string;
  session_id: string;
  timestamp: string;
  level?: string;
  message?: string;
  sample_rate?: number;
  exception: ExceptionValue[];
  contexts: Record<string, Record<string, unknown>>;
  breadcrumbs: Breadcrumb[];
  tags: Record<string, string>;
  extra?: Record<string, unknown>;
  attachments?: { kind: string; upload_id: string }[];
}

export interface SessionUpdate {
  sid: string;
  init?: boolean;
  status: 'started' | 'ok' | 'exited' | 'crashed' | 'abnormal';
  release: string;
  user_ref?: string;
  started: string;
}

export interface EffectivePolicy {
  policy: Policy;
  user_reports: boolean;
  screenshots: boolean;
  logs: boolean;
  server_reporting?: boolean;
  user_opt_out_allowed: boolean;
  sample_rates?: Partial<SampleRates>;
  source?: string;
  fetched_at?: string;
  ttl?: number;
}

export interface SampleRates {
  /** Crashes (ErrorBoundary, fatal): default 1.0 */
  crash: number;
  /** Unhandled errors and rejections: default 1.0 */
  unhandled: number;
  /** Handled errors via captureException: default 0.25 */
  error: number;
  /** captureMessage: default 1.0 */
  message: number;
  hang: number;
}

export interface InitOptions {
  /** Per-project public ingest key, e.g. pk_web_prod_… */
  key: string;
  /** Release as published in the Update Center, e.g. "3.4.0+34012". */
  release: string;
  /** Tenant Policy Token (string or getter). Carries the `bugtrace` policy claim. */
  tpt?: string | null | (() => string | null | undefined);
  /** Update Center base URL. Default https://updates.worksuitecloud.com */
  endpoint?: string;
  environment?: string;
  app?: string;
  platform?: string;
  installationRef?: string;
  userRef?: string;
  edition?: string;
  sampleRates?: Partial<SampleRates>;
  /** Stale-chunk reload errors are recorded as breadcrumbs, not reported (default true). */
  ignoreChunkLoadErrors?: boolean;
  /** Hook window.onerror / unhandledrejection (default true). */
  captureGlobalErrors?: boolean;
  /** HTTP breadcrumbs for fetch (default true). */
  instrumentFetch?: boolean;
  /** Session tracking for crash-free rates (default true). */
  sessions?: boolean;
  /** Ctrl/Cmd+Shift+B opens the report dialog (default true). */
  reportShortcut?: boolean;
  /** Extra client-side scrub patterns (replaced by [Filtered]). */
  scrubPatterns?: RegExp[];
  /** Last chance to modify or drop an event. */
  beforeSend?: (event: BugTraceEvent) => BugTraceEvent | null;
  /** Provides diagnostic logs for reports when the user opts in (scrubbed before upload). */
  getLogs?: () => string | Promise<string>;
  /** Reporter e-mail shown (masked) in the contact-consent checkbox. */
  contactEmail?: string;
  /** Called when a queued report is accepted and gets its BR- reference. */
  onReportAccepted?: (receipt: ReportReceipt & { title: string }) => void;
  /** Custom fetch (tests, Electron main). */
  fetch?: typeof fetch;
  /** Queue storage override (tests). */
  storage?: QueueStore;
  /** Visual locale strings override for the dialog. */
  debug?: boolean;
}

export interface CaptureContext {
  area?: string;
  type?: EventType;
  handled?: boolean;
  tags?: Record<string, string>;
  extra?: Record<string, unknown>;
  level?: string;
}

export interface ReportInput {
  title: string;
  description?: string;
  steps?: string[];
  expected?: string;
  severity: 'MINOR' | 'ANNOYING' | 'BLOCKS_WORK' | 'DATA_WRONG';
  category: 'BUG' | 'PERFORMANCE' | 'DATA' | 'UI' | 'FEATURE_REQUEST';
  includeTechnical?: boolean;
  screenshot?: Blob | null;
  screenshotMeta?: { annotated: boolean; redacted_regions: number };
  logs?: string | null;
  contactConsent?: boolean;
}

export interface ReportReceipt {
  reference: string;
  status_url: string;
  status_token: string;
}

export interface QueueItem {
  id?: number;
  kind: 'envelope' | 'report';
  body: unknown;
  size: number;
  created: number;
  attempts: number;
  next: number;
}

export interface QueueStore {
  add(item: QueueItem): Promise<void>;
  all(): Promise<QueueItem[]>;
  remove(id: number): Promise<void>;
  update(item: QueueItem): Promise<void>;
  clear(): Promise<void>;
}
