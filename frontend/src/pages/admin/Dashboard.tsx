import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Plus } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import { compactNumber, cx, formatNumber, formatPct } from '@/core/format';
import { BarList, ButtonLink, Card, CardHeader, ErrorState, Kpi, PageHeader, ProgressBar, Spinner, Table, Td, Th } from '@/components/ui';
import { ReleaseStatusBadge } from '@/components/StatusBadges';
import { EnvBadge } from '@/components/admin/EnvBadge';
import { AuditTimeline } from '@/components/admin/AuditTimeline';
import { useDocumentTitle } from '@/hooks/useDocumentHead';

const DIST_COLORS = ['bg-indigo', 'bg-brand', 'bg-[#d98a1c]', 'bg-bad'];

export default function Dashboard() {
  useDocumentTitle('Dashboard · Update Center');
  const { user } = useAuth();
  const q = useQuery({ queryKey: adminKeys.dashboard, queryFn: adminApi.dashboard, refetchInterval: 60_000 });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  const d = q.data;
  const k = d.kpis;
  return (
    <>
      <PageHeader
        title="WorkSuite Update Center"
        badges={<EnvBadge env={d.environment} />}
        actions={
          <>
            <Link to="/admin/health" className={cx('text-[12px]', d.health.ok ? 'text-subtle' : 'font-semibold text-bad')}>
              {d.health.ok ? 'All systems healthy' : `${d.health.components.filter((c) => c.status !== 'OK').length} components degraded`}
            </Link>
            {can(user, 'release.create') && (
              <ButtonLink to="/admin/releases/new" variant="primary" size="sm" icon={<Plus className="size-3.5" aria-hidden />}>
                New Release
              </ButtonLink>
            )}
          </>
        }
      />
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <Kpi label="Apps" value={k.apps} sub={`${k.apps_public} with public downloads`} />
        <Kpi label="Releases" value={k.releases} sub={`${k.releases_testing} in testing · ${k.releases_draft} draft`} />
        <Kpi label="Downloads · 30 d" value={compactNumber(k.downloads_30d)} sub={`${formatPct((k.downloads_redirect_ok_30d / Math.max(1, k.downloads_30d)) * 100, 1)} redirects completed`} />
        <Kpi label="Active devices" value={formatNumber(k.active_devices)} sub="checked in ≤ 7 days" />
      </div>

      <div className="mt-3 grid gap-3 xl:grid-cols-2">
        <Card>
          <CardHeader title="Latest releases" actions={<Link className="link text-[12px]" to="/admin/releases?group=published">All releases</Link>} />
          <Table label="Latest releases">
            <thead>
              <tr>
                <Th>Application</Th>
                <Th>Version</Th>
                <Th>Channel</Th>
                <Th>Status</Th>
              </tr>
            </thead>
            <tbody>
              {d.latest_releases.map((r) => (
                <tr key={r.id} className="hover:bg-ground/60">
                  <Td>
                    <Link to={`/admin/releases/${r.id}`} className="hover:text-brand">
                      {r.app_name}
                    </Link>
                  </Td>
                  <Td className="font-mono">{r.version}</Td>
                  <Td>{r.channel.charAt(0) + r.channel.slice(1).toLowerCase()}</Td>
                  <Td>
                    <ReleaseStatusBadge status={r.status} />
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>

        <Card>
          <CardHeader title={`Version distribution · ${d.version_distribution.app_name.replace(/^WorkSuite\s+/, '')}`} actions={<Link className="link text-[12px]" to="/admin/devices/versions">Details</Link>} />
          <div className="px-4 pb-4">
            <BarList
              label="Version distribution"
              max={100}
              items={d.version_distribution.items.map((it, i) => ({ label: it.version, value: it.pct, color: DIST_COLORS[Math.min(i, 3)], display: `${it.pct}%` }))}
            />
            <p className="mt-3 text-[11.5px] text-subtle">
              Current {d.version_distribution.current_pct}% · Update available {d.version_distribution.update_available_pct}% · Unsupported {d.version_distribution.unsupported_pct}%
            </p>
          </div>
        </Card>

        {d.downloads && (
          <Card>
            <CardHeader title={`Downloads · ${d.downloads.app_name} ${d.downloads.version}`} actions={<Link className="link text-[12px]" to="/admin/distribution/downloads">Analytics</Link>} />
            <div className="px-4 pb-4">
              <BarList label="Downloads by package" items={d.downloads.items.map((i) => ({ label: <span className="font-sans">{i.label}</span>, value: i.count, display: formatNumber(i.count) }))} />
            </div>
          </Card>
        )}

        <Card className="grid gap-4 p-4 sm:grid-cols-2">
          <div>
            <h2 className="mb-2 text-[14px] font-bold">Active rollouts</h2>
            {d.active_rollouts.length === 0 && <p className="text-[12.5px] text-subtle">No active rollouts.</p>}
            <ul className="space-y-3">
              {d.active_rollouts.map((r) => (
                <li key={r.release_id} className="text-[12.5px]">
                  <Link to={`/admin/releases/${r.release_id}/rollout`} className="hover:text-brand">
                    {r.app_name} <span className="font-mono">{r.version}</span> · {r.channel.charAt(0) + r.channel.slice(1).toLowerCase()}
                  </Link>
                  <ProgressBar className="my-1" value={r.percentage} tone={r.status === 'PAUSED' ? 'bg-[#d98a1c]' : 'bg-ok'} label={`${r.app_name} ${r.version} rollout`} />
                  <span className="text-[11.5px] text-subtle">
                    Stage {r.stage_no} · {r.percentage}% · {r.status === 'PAUSED' ? 'paused · ' : ''}update success {formatPct(r.success_rate, 1)}
                  </span>
                </li>
              ))}
            </ul>
          </div>
          <div>
            <h2 className="mb-2 text-[14px] font-bold">Recent activity</h2>
            <AuditTimeline entries={d.recent_activity.slice(0, 4)} compact />
          </div>
        </Card>
      </div>
    </>
  );
}
