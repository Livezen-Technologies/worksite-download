import type { ReactNode } from 'react';
import type { Edition, PolicyMode, ServerSeverity } from '@/types/api';
import { Badge, Spinner, ErrorState, type Tone } from '@/components/ui';

/** "Professional" is the product name of the FREE edition (WS-CORE-SYSUPDATE §3). */
export function editionLabel(e: Edition | string): string {
  switch (e) {
    case 'FREE':
      return 'Professional';
    case 'ALL':
      return 'All editions (core patch)';
    default:
      return e.charAt(0) + e.slice(1).toLowerCase();
  }
}

export const SEVERITIES: ServerSeverity[] = ['PATCH', 'MINOR', 'MAJOR', 'SECURITY', 'CRITICAL'];

const SEVERITY_TONE: Record<ServerSeverity, Tone> = { PATCH: 'grey', MINOR: 'indigo', MAJOR: 'purple', SECURITY: 'red', CRITICAL: 'red' };
export function SeverityBadge({ severity }: { severity: ServerSeverity }) {
  return <Badge tone={SEVERITY_TONE[severity] ?? 'grey'}>{severity}</Badge>;
}

const POLICY_LABEL: Record<PolicyMode, string> = { OFF: 'Off', NOTIFY: 'Notify', AUTO_SECURITY: 'Security only', AUTO_MINOR: 'Patches and minors', AUTO_ALL: 'Everything' };
export function policyLabel(mode: PolicyMode | null | undefined): string {
  return mode ? (POLICY_LABEL[mode] ?? mode) : '—';
}

export function ringLabel(ring: number | null | undefined): string {
  return ring == null ? '—' : (['Canary', 'Early', 'Broad', 'Conservative'][ring] ?? `Ring ${ring}`);
}

const RUN: Record<string, [string, Tone]> = {
  SUCCEEDED: ['Succeeded', 'green'],
  COMPLETED: ['Succeeded', 'green'],
  ROLLED_BACK: ['Rolled back', 'amber'],
  FAILED: ['Failed', 'red'],
  STARTED: ['Running', 'blue'],
  CANCELLED: ['Cancelled', 'grey'],
};
export function RunBadge({ status }: { status: string | null | undefined }) {
  if (!status) return <span className="text-subtle">—</span>;
  const [label, tone] = RUN[status] ?? [status.replace(/_/g, ' ').toLowerCase(), 'grey' as Tone];
  return <Badge tone={tone}>{label}</Badge>;
}

export function QueryState({ q }: { q: { isLoading: boolean; error: unknown; refetch: () => unknown } }): ReactNode {
  if (q.isLoading) return <Spinner />;
  return <ErrorState error={q.error} retry={() => void q.refetch()} />;
}
