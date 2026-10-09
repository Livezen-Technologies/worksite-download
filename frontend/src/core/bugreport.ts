import type { AppKind, Platform } from '@/types/api';

/** Where the problem happened — maps to the Bug Trace project platform. */
export type ReportPlatform = 'web' | 'desktop' | 'mobile';

export const REPORT_PLATFORMS: { value: ReportPlatform; label: string; hint: string }[] = [
  { value: 'web', label: 'Web app', hint: 'In the browser' },
  { value: 'desktop', label: 'Desktop app', hint: 'Windows, macOS or Linux' },
  { value: 'mobile', label: 'Mobile app', hint: 'Android or iOS' },
];

export function isReportPlatform(v: string | null | undefined): v is ReportPlatform {
  return v === 'web' || v === 'desktop' || v === 'mobile';
}

/** Best guess from the application kind and the visitor's device. */
export function defaultReportPlatform(kind: AppKind | undefined, detected: Platform | null): ReportPlatform {
  if (kind === 'SERVER') return 'web';
  if (kind === 'MOBILE' || detected === 'android' || detected === 'ios') return 'mobile';
  return 'desktop';
}

/** Link to the public report form, pre-filled from a download-center page. */
export function reportProblemPath(opts: { app?: string; version?: string; platform?: ReportPlatform } = {}): string {
  const q = new URLSearchParams();
  if (opts.app) q.set('app', opts.app);
  if (opts.version) q.set('version', opts.version);
  if (opts.platform) q.set('platform', opts.platform);
  const s = q.toString();
  return s ? `/report-a-problem?${s}` : '/report-a-problem';
}
