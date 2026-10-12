import { Link, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { formatNumber, formatPct } from '@/core/format';
import type { ServerReleaseRow } from '@/types/api';
import { ButtonLink, Card, CardHeader, EmptyState, FilterSelect, Kpi, PageHeader, ProgressBar, Table, Td, Th } from '@/components/ui';
import { ChannelBadge, ReleaseStatusBadge } from '@/components/StatusBadges';
import { QueryState, SeverityBadge } from './serverUi';

const CHANNEL_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'STABLE', label: 'Stable' },
  { value: 'BETA', label: 'Beta' },
  { value: 'LTS', label: 'LTS' },
];
const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'testing', label: 'Testing' },
  { value: 'published', label: 'Published' },
  { value: 'paused', label: 'Paused' },
  { value: 'withdrawn', label: 'Withdrawn' },
];

/** Published-side releases open the rollout (DL-3); everything before publication opens the release form (DL-2). */
export function serverReleaseHref(r: Pick<ServerReleaseRow, 'id' | 'status'>): string {
  return ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN'].includes(r.status) ? `/admin/server/releases/${r.id}/rollout` : `/admin/server/releases/${r.id}`;
}

/** DL-1 Server releases: fleet KPIs and the release list filtered by channel and status. */
export default function ServerReleases() {
  useDocumentTitle('Server releases · Update Center');
  const { user } = useAuth();
  const [sp, setSp] = useSearchParams();
  const channel = sp.get('channel') ?? '';
  const status = sp.get('status') ?? '';
  const filters = { channel: channel || undefined, status: status || undefined };
  const q = useQuery({ queryKey: adminKeys.serverReleases(filters), queryFn: () => adminApi.serverReleases(filters) });
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(sp);
    if (v) n.set(k, v);
    else n.delete(k);
    setSp(n, { replace: true });
  };
  const k = q.data?.kpis;
  return (
    <>
      <PageHeader
        breadcrumb={
          <>
            Server › <span className="font-mono">lk.worksuite.server</span>
          </>
        }
        title="Server Releases"
        sub="Edition packages, rings and tenant notices for WorkSuite Server."
        actions={
          can(user, 'release.create') && (
            <ButtonLink to="/admin/server/releases/new" variant="primary" size="sm" icon={<Plus className="size-3.5" aria-hidden />}>
              New server release
            </ButtonLink>
          )
        }
      />
      {k && (
        <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Kpi label="Linked installations" value={formatNumber(k.linked)} sub={`${formatNumber(k.online)} online, ${formatNumber(k.offline)} offline mode`} />
          <Kpi label="On latest stable" value={formatPct(k.on_latest_pct)} sub={k.latest_stable ? `${formatNumber(k.on_latest_stable)} of ${formatNumber(k.linked)} on ${k.latest_stable}` : 'No stable release yet'} tone={k.on_latest_pct >= 80 ? 'ok' : undefined} />
          <Kpi label="Below minimum" value={formatNumber(k.below_minimum)} sub={k.minimum_stable ? `Minimum stable is ${k.minimum_stable}` : 'No minimum set'} tone={k.below_minimum > 0 ? 'warn' : undefined} />
          <Kpi label="Rollbacks, 30 days" value={formatNumber(k.rollbacks_30d)} sub={k.rollback_versions.length ? `On ${k.rollback_versions.join(', ')}` : 'None'} tone={k.rollbacks_30d > 0 ? 'bad' : undefined} />
        </div>
      )}
      <Card>
        <CardHeader
          title="Releases"
          sub={q.data ? `${q.data.data.length} shown` : undefined}
          actions={
            <div className="flex flex-wrap gap-2">
              <FilterSelect label="Channel" value={channel} onChange={(v) => set('channel', v)} options={CHANNEL_OPTIONS} />
              <FilterSelect label="Status" value={status} onChange={(v) => set('status', v)} options={STATUS_OPTIONS} />
            </div>
          }
        />
        {!q.data ? (
          <QueryState q={q} />
        ) : q.data.data.length === 0 ? (
          <EmptyState title="No server releases match these filters">Change the channel or status, or create a new server release.</EmptyState>
        ) : (
          <Table label="Server releases">
            <thead>
              <tr>
                <Th>Version</Th>
                <Th>Channel</Th>
                <Th>Severity</Th>
                <Th>Editions</Th>
                <Th>Status</Th>
                <Th>Rollout</Th>
                <Th className="min-w-[120px]">Adoption</Th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((r) => (
                <tr key={r.id}>
                  <Td>
                    <Link to={serverReleaseHref(r)} className="link font-mono font-semibold">
                      {r.version}
                    </Link>
                    <div className="font-mono text-[11px] text-subtle">build {r.build}</div>
                  </Td>
                  <Td>
                    <ChannelBadge channel={r.channel} />
                  </Td>
                  <Td>
                    <SeverityBadge severity={r.severity} />
                  </Td>
                  <Td className="text-[12px]">{r.editions_label}</Td>
                  <Td>
                    <ReleaseStatusBadge status={r.status} />
                  </Td>
                  <Td className="text-[12px] whitespace-nowrap">{r.ring_summary}</Td>
                  <Td>
                    <div className="flex items-center gap-2">
                      <ProgressBar value={r.adoption_pct} label={`Adoption of ${r.version}`} className="w-20" />
                      <span className="font-mono text-[11.5px]">{formatPct(r.adoption_pct)}</span>
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {q.data && q.data.minimums.length > 0 && (
        <Card className="mt-4">
          <CardHeader
            title="Minimum supported version"
            sub="Installations below it get a REQUIRED notice; nothing is disabled remotely."
            actions={
              can(user, 'minimum_version.manage') && (
                <Link className="link text-[12.5px]" to="/admin/applications/server/minimums">
                  Change minimums
                </Link>
              )
            }
          />
          <dl className="grid gap-3 px-4 pb-4 sm:grid-cols-3">
            {q.data.minimums.map((m) => (
              <div key={m.channel} className="rounded-lg border border-line px-3 py-2">
                <dt className="label-caps">{m.channel}</dt>
                <dd className="font-mono text-[14px] font-semibold">{m.minimum_version ?? 'none'}</dd>
              </div>
            ))}
          </dl>
        </Card>
      )}
    </>
  );
}
