import type { ChannelCode, Edition, HealthStatus, ReleaseStatus, ReleaseType, SupportState } from '@/types/api';
import { Badge, type Tone } from './ui';
import { cx, titleCase } from '@/core/format';

const STATUS_TONE: Record<ReleaseStatus, Tone> = {
  DRAFT: 'grey',
  UPLOADING: 'blue',
  READY_FOR_TEST: 'purple',
  TESTING: 'blue',
  APPROVED: 'indigo',
  PUBLISHED: 'green',
  PAUSED: 'amber',
  WITHDRAWN: 'red',
  SUPERSEDED: 'grey',
};

export function ReleaseStatusBadge({ status }: { status: ReleaseStatus }) {
  return <Badge tone={STATUS_TONE[status] ?? 'grey'}>{status.replace(/_/g, ' ')}</Badge>;
}

const SUPPORT_TONE: Record<SupportState, Tone> = { SUPPORTED: 'grey', DEPRECATED: 'amber', UNSUPPORTED: 'red', BLOCKED: 'red' };
export function SupportStateBadge({ state }: { state: SupportState }) {
  return <Badge tone={SUPPORT_TONE[state]}>{state}</Badge>;
}

export function ChannelBadge({ channel }: { channel: ChannelCode }) {
  return <Badge tone={channel === 'STABLE' ? 'grey' : channel === 'BETA' ? 'purple' : channel === 'DEV' ? 'amber' : 'navy'}>{channel}</Badge>;
}

const TYPE_TONE: Record<ReleaseType, Tone> = { OPTIONAL: 'grey', RECOMMENDED: 'teal', MANDATORY: 'amber', CRITICAL: 'red' };
export function ReleaseTypeBadge({ type }: { type: ReleaseType }) {
  return <Badge tone={TYPE_TONE[type]}>{type}</Badge>;
}

export function VersionBadge({ version, className }: { version: string; className?: string }) {
  return <span className={cx('font-mono font-semibold', className)}>{version}</span>;
}

export function HealthBadge({ status }: { status: HealthStatus | string }) {
  const tone: Tone = status === 'OK' || status === 'HEALTHY' ? 'green' : status === 'DEGRADED' || status === 'BUSY' ? 'amber' : status === 'DOWN' ? 'red' : 'grey';
  const label = status === 'OK' ? 'Healthy' : status === 'DEGRADED' ? 'Busy' : titleCase(String(status));
  return <Badge tone={tone}>{label}</Badge>;
}

export function EditionBadge({ edition }: { edition: Edition | null }) {
  if (!edition) return null;
  return <Badge tone={edition === 'ENTERPRISE' ? 'navy' : edition === 'BUSINESS' ? 'indigo' : edition === 'ALL' ? 'purple' : 'grey'}>{edition}</Badge>;
}
