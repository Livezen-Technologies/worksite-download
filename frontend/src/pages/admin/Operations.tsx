/** Distribution (Downloads, Rollouts, Mirrors/CDN = A5), Content, Devices and Server pages. */
import { useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import type { AdminRelease, HealthComponent, ReleaseListResponse } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { compactNumber, formatBytes, formatDate, formatDateTime, formatNumber, formatPct, PLATFORM_LABEL, timeAgo, titleCase } from '@/core/format';
import { Alert, Badge, BarList, Button, ButtonLink, Card, CardHeader, ColumnChart, EmptyState, ErrorState, FilterSelect, KeyValue, Kpi, PageHeader, ProgressBar, Spinner, Table, Td, Th } from '@/components/ui';
import { ChannelBadge, HealthBadge, ReleaseStatusBadge } from '@/components/StatusBadges';
import { useToast } from '@/components/ui/Toast';
import { DownloadPageEditor } from '@/modules/applications/AppDetail';

function Loading({ q }: { q: { isLoading: boolean; error: unknown; refetch: () => unknown } }) {
  if (q.isLoading) return <Spinner />;
  return <ErrorState error={q.error} retry={() => void q.refetch()} />;
}

// ---------------------------------------------------------------- Downloads analytics

export function DownloadsAnalytics() {
  useDocumentTitle('Downloads · Update Center');
  const [group, setGroup] = useState<'day' | 'week' | 'month'>('day');
  const [app, setApp] = useState('');
  const apps = useQuery({ queryKey: adminKeys.apps, queryFn: adminApi.apps });
  const filters = { group, app: app || undefined };
  const q = useQuery({ queryKey: adminKeys.analytics('downloads', filters), queryFn: () => adminApi.downloads(filters) });
  return (
    <>
      <PageHeader
        title="Downloads"
        sub="Completed resolver redirects (privacy-safe, bots excluded)."
        actions={
          <>
            <FilterSelect label="App" value={app} onChange={setApp} options={[{ value: '', label: 'All' }, ...(apps.data?.data ?? []).map((a) => ({ value: a.slug, label: a.name }))]} />
            <FilterSelect label="Group" value={group} onChange={(v) => setGroup(v as typeof group)} options={['day', 'week', 'month'].map((g) => ({ value: g, label: titleCase(g) }))} />
            <a className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-[12.5px] font-semibold hover:bg-ground" href={adminApi.downloadsCsvUrl(filters)} download>
              <Download className="size-3.5" aria-hidden /> Export CSV
            </a>
          </>
        }
      />
      {!q.data ? (
        <Loading q={q} />
      ) : (
        <div className="grid gap-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Kpi label="Downloads" value={compactNumber(q.data.total)} sub={`per ${group}`} />
            <Kpi label="Bots excluded" value={formatNumber(q.data.bots_excluded)} />
            <Kpi label="Top platform" value={PLATFORM_LABEL[q.data.by_platform[0]?.platform] ?? '—'} sub={formatNumber(q.data.by_platform[0]?.count)} />
            <Kpi label="Top country" value={q.data.by_country[0]?.country ?? '—'} sub={formatNumber(q.data.by_country[0]?.count)} />
          </div>
          <Card>
            <CardHeader title="Downloads over time" />
            <div className="px-4 pb-4">
              <ColumnChart label="Downloads over time" height={160} data={q.data.series.map((s) => ({ label: s.period, value: s.count }))} />
            </div>
          </Card>
          <div className="grid gap-4 lg:grid-cols-3">
            <Card>
              <CardHeader title="By platform" />
              <div className="px-4 pb-4">
                <BarList label="By platform" items={q.data.by_platform.map((p) => ({ label: <span className="font-sans">{PLATFORM_LABEL[p.platform]}</span>, value: p.count, display: formatNumber(p.count) }))} />
              </div>
            </Card>
            <Card>
              <CardHeader title="By package" />
              <div className="px-4 pb-4">
                <BarList label="By package" items={q.data.by_package.map((p) => ({ label: <span className="font-sans">{p.label}</span>, value: p.count, display: formatNumber(p.count) }))} />
              </div>
            </Card>
            <Card>
              <CardHeader title="By country" />
              <div className="px-4 pb-4">
                <BarList label="By country" color="bg-brand" items={q.data.by_country.map((c) => ({ label: c.country, value: c.count, display: formatNumber(c.count) }))} />
              </div>
            </Card>
          </div>
          <Card>
            <CardHeader title="Top releases" />
            <Table label="Top releases">
              <thead>
                <tr>
                  <Th>Application</Th>
                  <Th>Version</Th>
                  <Th className="text-right">Downloads</Th>
                </tr>
              </thead>
              <tbody>
                {q.data.top_releases.map((r) => (
                  <tr key={r.release_id}>
                    <Td>{r.app_name}</Td>
                    <Td>
                      <Link to={`/admin/releases/${r.release_id}/downloads`} className="font-mono text-indigo hover:underline">
                        {r.version}
                      </Link>
                    </Td>
                    <Td className="text-right font-mono">{formatNumber(r.count)}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </Card>
        </div>
      )}
    </>
  );
}

// ---------------------------------------------------------------- Rollouts overview

export function RolloutsOverview() {
  useDocumentTitle('Rollouts · Update Center');
  const q = useQuery({ queryKey: adminKeys.releases({ status: 'PUBLISHED,PAUSED', limit: 100 }), queryFn: () => adminApi.releases({ status: 'PUBLISHED,PAUSED', limit: 100 }) });
  const dash = useQuery({ queryKey: adminKeys.dashboard, queryFn: adminApi.dashboard });
  const rollouts = dash.data?.active_rollouts ?? [];
  return (
    <>
      <PageHeader title="Rollouts" sub="Staged rollouts across applications. Pause, resume, increase or complete from the release’s Rollout tab." />
      <div className="grid gap-4">
        <Card>
          <CardHeader title="Active and paused rollouts" />
          {dash.isLoading ? (
            <Spinner />
          ) : rollouts.length === 0 ? (
            <EmptyState title="No active rollouts" />
          ) : (
            <Table label="Active rollouts">
              <thead>
                <tr>
                  <Th>Release</Th>
                  <Th>Channel</Th>
                  <Th>Stage</Th>
                  <Th>Progress</Th>
                  <Th className="text-right">Update success</Th>
                  <Th>Status</Th>
                </tr>
              </thead>
              <tbody>
                {rollouts.map((r) => (
                  <tr key={r.release_id}>
                    <Td>
                      <Link to={`/admin/releases/${r.release_id}/rollout`} className="font-semibold text-indigo hover:underline">
                        {r.app_name} <span className="font-mono">{r.version}</span>
                      </Link>
                    </Td>
                    <Td>
                      <ChannelBadge channel={r.channel} />
                    </Td>
                    <Td className="whitespace-nowrap">
                      {r.stage_no} of {r.stages.length} · <span className="font-mono">{r.stages.join(' → ')}</span>
                    </Td>
                    <Td className="w-56">
                      <div className="flex items-center gap-2">
                        <ProgressBar value={r.percentage} tone={r.status === 'PAUSED' ? 'bg-[#d98a1c]' : 'bg-ok'} label={`${r.app_name} ${r.version}`} />
                        <span className="font-mono text-[11.5px]">{r.percentage}%</span>
                      </div>
                    </Td>
                    <Td className="text-right font-mono">{formatPct(r.success_rate, 1)}</Td>
                    <Td>
                      <Badge tone={r.status === 'PAUSED' ? 'amber' : 'green'}>{r.status}</Badge>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
        <Card>
          <CardHeader title="Published releases" />
          {!q.data ? <Loading q={q} /> : <ReleaseMiniTable rows={q.data.data} tab="rollout" />}
        </Card>
      </div>
    </>
  );
}

function ReleaseMiniTable({ rows, tab, extra }: { rows: AdminRelease[]; tab: string; extra?: (r: AdminRelease) => React.ReactNode }) {
  if (!rows.length) return <EmptyState title="Nothing here yet" />;
  return (
    <Table label="Releases">
      <thead>
        <tr>
          <Th>Application</Th>
          <Th>Version</Th>
          <Th>Channel</Th>
          <Th>Status</Th>
          <Th>Released</Th>
          {extra && <Th />}
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.id} className="hover:bg-ground/60">
            <Td>{r.app.name}</Td>
            <Td>
              <Link to={`/admin/releases/${r.id}/${tab}`} className="font-mono font-semibold text-indigo hover:underline">
                {r.version}
              </Link>
            </Td>
            <Td>
              <ChannelBadge channel={r.channel} />
            </Td>
            <Td>
              <ReleaseStatusBadge status={r.status} />
            </Td>
            <Td>{formatDate(r.release_date ?? r.published_at)}</Td>
            {extra && <Td className="text-right">{extra(r)}</Td>}
          </tr>
        ))}
      </tbody>
    </Table>
  );
}

// ---------------------------------------------------------------- Storage & health (A5)

function HealthTable({ components }: { components: HealthComponent[] }) {
  const NAME: Record<string, string> = { api: 'API', database: 'Database (MariaDB)', redis: 'Redis', storage: 'Object Storage', cdn: 'CDN', signing: 'Signing Service (KMS)', queue: 'Queue workers', export: 'Daily export (write-once)' };
  return (
    <Table label="Component health">
      <thead>
        <tr>
          <Th>Component</Th>
          <Th>Status</Th>
          <Th className="text-right">Latency</Th>
          <Th>Detail</Th>
          <Th>Checked</Th>
        </tr>
      </thead>
      <tbody>
        {components.map((c) => (
          <tr key={c.component}>
            <Td>{NAME[c.component] ?? c.component}</Td>
            <Td>
              <HealthBadge status={c.status} />
            </Td>
            <Td className="text-right font-mono text-[11.5px]">{c.latency_ms != null ? `${c.latency_ms} ms` : '—'}</Td>
            <Td className="text-[12px]">{c.detail}</Td>
            <Td className="text-[11.5px] text-subtle">{timeAgo(c.checked_at)}</Td>
          </tr>
        ))}
      </tbody>
    </Table>
  );
}

export function StorageHealth() {
  useDocumentTitle('Storage & System Health · Update Center');
  const { user } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: adminKeys.storage, queryFn: adminApi.storage });
  const h = useQuery({ queryKey: adminKeys.health, queryFn: adminApi.health, refetchInterval: 60_000 });
  const active = q.data?.providers.find((p) => p.is_active) ?? q.data?.providers[0];
  const test = useMutation({
    mutationFn: (id: string) => adminApi.testStorage(id),
    onSuccess: (r) => (r.ok ? toast.success(`Connection OK${r.latency_ms ? ` · ${r.latency_ms} ms` : ''}${r.detail ? ` — ${r.detail}` : ''}`) : toast.error(r.detail ?? 'Connection failed'), void qc.invalidateQueries({ queryKey: adminKeys.storage })),
    onError: (e) => toast.error(errorMessage(e)),
  });
  const probe = useMutation({ mutationFn: adminApi.probeStorage, onSuccess: () => toast.success('HEAD probe of every active revision queued'), onError: (e) => toast.error(errorMessage(e)) });
  const activate = useMutation({ mutationFn: (id: string) => adminApi.activateStorage(id), onSuccess: () => (toast.success('Copy-and-verify migration started'), void qc.invalidateQueries({ queryKey: adminKeys.storage })), onError: (e) => toast.error(errorMessage(e)) });
  const manage = can(user, 'storage.manage');
  return (
    <>
      <PageHeader
        title="Storage & System Health"
        actions={
          manage &&
          active && (
            <>
              <Button size="sm" loading={test.isPending} onClick={() => test.mutate(active.id)}>
                Test Connection
              </Button>
              <Button size="sm" loading={probe.isPending} onClick={() => probe.mutate()}>
                Probe all packages
              </Button>
            </>
          )
        }
      />
      {!q.data ? (
        <Loading q={q} />
      ) : (
        <div className="grid gap-4">
          <div className="grid gap-4 lg:grid-cols-2">
            {active && (
              <Card>
                <CardHeader title="Release storage" sub={active.name} />
                <div className="px-4 pb-4">
                  <KeyValue
                    rows={[
                      ['Provider', active.name],
                      ['Bucket', <span className="font-mono">{active.bucket}</span>],
                      ['Region', active.region ?? '—'],
                      ['Used storage', `${formatBytes(active.usage_bytes)} · ${active.release_count} releases · ${formatNumber(active.object_count)} revisions`],
                      ['Object versioning', `${active.versioning ? 'On' : 'Off'}${active.replication ? ` · replication to ${active.replication}` : ''}`],
                      ['Credentials', active.credentials === 'set' ? `Stored in secret service · last rotated ${formatDate(active.credentials_rotated_at)}` : <span className="text-bad">Missing</span>],
                      ['Connectivity', <HealthBadge status={active.status} />],
                      ['Last check', timeAgo(active.last_check_at)],
                    ]}
                  />
                </div>
              </Card>
            )}
            <Card>
              <CardHeader title="CDN" />
              <div className="px-4 pb-4">
                <KeyValue
                  rows={[
                    ['Package host', <span className="font-mono">{q.data.cdn.host}</span>],
                    ['Signed URL lifetime', `${Math.round(q.data.cdn.url_lifetime_s / 60)} minutes (${q.data.cdn.signing})`],
                    ['Cache hit ratio · 24 h', formatPct(q.data.cdn.hit_ratio, 1)],
                    ['Egress · 30 d', formatBytes(q.data.cdn.egress_30d_bytes, 1)],
                    ['Purge', `${titleCase(q.data.cdn.purge)} · last ${formatDateTime(q.data.cdn.last_purge_at)}`],
                    ['Status', <HealthBadge status={q.data.cdn.status} />],
                  ]}
                />
              </div>
            </Card>
          </div>
          {q.data.distribution.degraded_packages.length > 0 && (
            <Alert tone="bad" title="Distribution degraded:">
              {q.data.distribution.degraded_packages.map((d) => `${d.version} ${d.package} — ${d.detail}`).join(' · ')}
            </Alert>
          )}
          <Card>
            <CardHeader title="System health" sub={h.data ? `queue backlog ${h.data.queue.backlog} · oldest ${h.data.queue.oldest_age_s} s · failed 24 h ${h.data.queue.failed_24h}` : 'checked every 60 s'} />
            {h.data ? <HealthTable components={h.data.components} /> : <Loading q={h} />}
          </Card>
          <Card>
            <CardHeader title="Storage providers" sub="Credentials are never shown — only whether a secret reference is set." />
            <Table label="Storage providers">
              <thead>
                <tr>
                  <Th>Provider</Th>
                  <Th>Bucket</Th>
                  <Th>Region</Th>
                  <Th className="text-right">Usage</Th>
                  <Th>Credentials</Th>
                  <Th>Status</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {q.data.providers.map((p) => (
                  <tr key={p.id}>
                    <Td>
                      {p.name} {p.is_active && <Badge tone="green">Active</Badge>}
                    </Td>
                    <Td className="font-mono text-[11.5px]">{p.bucket}</Td>
                    <Td>{p.region}</Td>
                    <Td className="text-right font-mono">{formatBytes(p.usage_bytes)}</Td>
                    <Td>{p.credentials === 'set' ? <Badge tone="green">Set</Badge> : <Badge tone="red">Missing</Badge>}</Td>
                    <Td>
                      <HealthBadge status={p.status} />
                    </Td>
                    <Td className="text-right whitespace-nowrap">
                      {manage && (
                        <>
                          <Button size="sm" variant="ghost" onClick={() => test.mutate(p.id)}>
                            Test
                          </Button>
                          {!p.is_active && (
                            <Button size="sm" variant="ghost" onClick={() => activate.mutate(p.id)}>
                              Activate…
                            </Button>
                          )}
                        </>
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            {q.data.migrations.length > 0 && (
              <p className="px-4 py-2 text-[12px] text-subtle">
                Migrations: {q.data.migrations.map((m) => `${m.from} → ${m.to} ${m.status} ${m.progress ?? 0}%`).join(' · ')}
              </p>
            )}
          </Card>
        </div>
      )}
    </>
  );
}

export function HealthPage() {
  useDocumentTitle('Health · Update Center');
  const h = useQuery({ queryKey: adminKeys.health, queryFn: adminApi.health, refetchInterval: 30_000 });
  return (
    <>
      <PageHeader title="System health" sub="API, database, Redis, storage, CDN, signing and queue checks." badges={h.data && <HealthBadge status={h.data.ok ? 'OK' : 'DEGRADED'} />} />
      {!h.data ? (
        <Loading q={h} />
      ) : (
        <div className="grid gap-4">
          <div className="grid grid-cols-3 gap-3">
            <Kpi label="Queue backlog" value={formatNumber(h.data.queue.backlog)} tone={h.data.queue.backlog > 50 ? 'warn' : undefined} />
            <Kpi label="Oldest job" value={`${h.data.queue.oldest_age_s} s`} />
            <Kpi label="Failed jobs · 24 h" value={formatNumber(h.data.queue.failed_24h)} tone={h.data.queue.failed_24h ? 'bad' : 'ok'} />
          </div>
          <Card>
            <HealthTable components={h.data.components} />
          </Card>
        </div>
      )}
    </>
  );
}

// ---------------------------------------------------------------- Content

function useAllReleases(status?: string) {
  const f = { status, limit: 100 };
  return useQuery<ReleaseListResponse>({ queryKey: adminKeys.releases(f), queryFn: () => adminApi.releases(f) });
}

export function ChangelogsContent() {
  useDocumentTitle('Changelogs · Update Center');
  const q = useAllReleases('DRAFT,UPLOADING,READY_FOR_TEST,TESTING,APPROVED,PUBLISHED,PAUSED');
  return (
    <>
      <PageHeader title="Changelogs" sub="Markdown changelog per release: summary ≤ 280, up to 5 highlights, categorised items." />
      <Card>{!q.data ? <Loading q={q} /> : <ReleaseMiniTable rows={q.data.data} tab="changelog" extra={(r) => <ButtonLink size="sm" to={`/admin/releases/${r.id}/changelog`}>Edit</ButtonLink>} />}</Card>
    </>
  );
}

export function ReleaseNotesContent() {
  useDocumentTitle('Release Notes · Update Center');
  const q = useAllReleases('PUBLISHED,PAUSED,SUPERSEDED');
  return (
    <>
      <PageHeader title="Release Notes" sub="Public release-notes pages, RSS and Atom feeds." actions={<a className="link text-[12px]" href="/changelog/feed">RSS</a>} />
      <Card>
        {!q.data ? (
          <Loading q={q} />
        ) : (
          <ReleaseMiniTable
            rows={q.data.data}
            tab="changelog"
            extra={(r) => (
              <a className="link text-[12px]" href={`/apps/${r.app.slug}/releases/${r.version}`} target="_blank" rel="noopener">
                Public page
              </a>
            )}
          />
        )}
      </Card>
    </>
  );
}

export function DownloadPagesContent() {
  useDocumentTitle('Download Pages · Update Center');
  const apps = useQuery({ queryKey: adminKeys.apps, queryFn: adminApi.apps });
  const [sel, setSel] = useState('');
  const app = apps.data?.data.find((a) => a.id === sel) ?? apps.data?.data[0];
  return (
    <>
      <PageHeader
        title="Download Pages"
        sub="Light CMS per application: hero, description, requirements, installation help, FAQ, support link — previewed, versioned, audited."
        actions={apps.data && <FilterSelect label="Application" value={app?.id ?? ''} onChange={setSel} options={apps.data.data.map((a) => ({ value: a.id, label: a.name }))} />}
      />
      {app ? <DownloadPageEditor key={app.id} app={app} /> : <Loading q={apps} />}
    </>
  );
}

// ---------------------------------------------------------------- Devices

export function DeviceVersions() {
  useDocumentTitle('Device versions · Update Center');
  const apps = useQuery({ queryKey: adminKeys.apps, queryFn: adminApi.apps });
  const [app, setApp] = useState('desktop');
  const [channel, setChannel] = useState('');
  const f = { app, channel: channel || undefined };
  const q = useQuery({ queryKey: adminKeys.analytics('devices', f), queryFn: () => adminApi.devices(f) });
  return (
    <>
      <PageHeader
        title="Device versions"
        sub="From pseudonymous heartbeats (checked in ≤ 7 days)."
        actions={
          <>
            <FilterSelect label="App" value={app} onChange={setApp} options={(apps.data?.data ?? []).filter((a) => a.kind !== 'SERVER').map((a) => ({ value: a.slug, label: a.name }))} />
            <FilterSelect label="Channel" value={channel} onChange={setChannel} options={[{ value: '', label: 'All' }, ...['STABLE', 'BETA', 'DEV', 'INTERNAL'].map((c) => ({ value: c, label: titleCase(c) }))]} />
          </>
        }
      />
      {!q.data ? (
        <Loading q={q} />
      ) : (
        <div className="grid gap-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Kpi label="Active devices" value={formatNumber(q.data.active_devices)} />
            <Kpi label="Current" value={formatPct(q.data.current_pct)} tone="ok" />
            <Kpi label="Update available" value={formatPct(q.data.update_available_pct)} tone="warn" />
            <Kpi label="Unsupported" value={formatPct(q.data.unsupported_pct)} tone="bad" />
          </div>
          <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
            <Card>
              <CardHeader title="Distribution by version" />
              <div className="px-4 pb-4">
                <BarList
                  label="Distribution by version"
                  max={100}
                  items={q.data.by_version.map((v) => ({ label: v.version, value: v.pct, color: v.state === 'CURRENT' ? 'bg-indigo' : v.state === 'UPDATE_AVAILABLE' ? 'bg-[#d98a1c]' : 'bg-bad', display: `${v.pct}% · ${formatNumber(v.count)}` }))}
                />
                <p className="mt-3 flex flex-wrap gap-3 text-[11.5px] text-subtle">
                  <span><span className="mr-1 inline-block size-2 rounded-full bg-indigo" />Current</span>
                  <span><span className="mr-1 inline-block size-2 rounded-full bg-[#d98a1c]" />Update available</span>
                  <span><span className="mr-1 inline-block size-2 rounded-full bg-bad" />Unsupported</span>
                </p>
              </div>
            </Card>
            <div className="grid gap-4">
              <Card>
                <CardHeader title="By platform" />
                <div className="px-4 pb-4">
                  <BarList label="By platform" items={q.data.by_platform.map((p) => ({ label: <span className="font-sans">{PLATFORM_LABEL[p.platform]}</span>, value: p.count, display: formatNumber(p.count) }))} />
                </div>
              </Card>
              <Card>
                <CardHeader title="By channel" />
                <div className="px-4 pb-4">
                  <BarList label="By channel" items={q.data.by_channel.map((c) => ({ label: <span className="font-sans">{titleCase(c.channel)}</span>, value: c.count, display: formatNumber(c.count) }))} />
                </div>
              </Card>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export function UpdateStatus() {
  useDocumentTitle('Update status · Update Center');
  const q = useQuery({ queryKey: adminKeys.analytics('update-status', {}), queryFn: () => adminApi.updateStatus({}) });
  return (
    <>
      <PageHeader title="Update status" sub="Checked → downloaded → installed, failures by error code and rollbacks." />
      {!q.data ? (
        <Loading q={q} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
          <Card>
            <CardHeader title="Per release" />
            <Table label="Update status per release">
              <thead>
                <tr>
                  <Th>Release</Th>
                  <Th className="text-right">Checked</Th>
                  <Th className="text-right">Downloaded</Th>
                  <Th className="text-right">Installed</Th>
                  <Th className="text-right">Failed</Th>
                  <Th className="text-right">Rolled back</Th>
                  <Th className="text-right">Success</Th>
                </tr>
              </thead>
              <tbody>
                {q.data.releases.map((r) => (
                  <tr key={r.release_id}>
                    <Td>
                      <Link to={`/admin/releases/${r.release_id}/devices`} className="font-mono text-indigo hover:underline">
                        {r.version}
                      </Link>
                    </Td>
                    <Td className="text-right font-mono">{formatNumber(r.checked)}</Td>
                    <Td className="text-right font-mono">{formatNumber(r.downloaded)}</Td>
                    <Td className="text-right font-mono">{formatNumber(r.installed)}</Td>
                    <Td className="text-right font-mono text-bad">{formatNumber(r.failed)}</Td>
                    <Td className="text-right font-mono">{formatNumber(r.rolled_back)}</Td>
                    <Td className={`text-right font-mono ${(r.success_rate ?? 100) < 97 ? 'text-warn' : 'text-ok'}`}>{formatPct(r.success_rate, 1)}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </Card>
          <Card>
            <CardHeader title="Failures by code" />
            <div className="px-4 pb-4">
              <BarList label="Failures by code" color="bg-bad" items={q.data.failures_by_code.map((f) => ({ label: f.error_code, value: f.count, display: formatNumber(f.count) }))} />
            </div>
          </Card>
        </div>
      )}
    </>
  );
}

// ---------------------------------------------------------------- Server

export function ManagedFleet() {
  useDocumentTitle('Managed Fleet · Update Center');
  const { user } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const rel = useQuery({ queryKey: adminKeys.releases({ app: 'server', limit: 20 }), queryFn: () => adminApi.releases({ app: 'server', limit: 20 }) });
  const candidates = (rel.data?.data ?? []).filter((r) => ['APPROVED', 'PUBLISHED', 'PAUSED'].includes(r.status));
  const [sel, setSel] = useState('');
  const release = candidates.find((r) => r.id === sel) ?? candidates[0];
  const detail = useQuery({ queryKey: adminKeys.release(release?.id ?? ''), queryFn: () => adminApi.release(release!.id), enabled: !!release });
  const installs = useQuery({ queryKey: adminKeys.installations({ mode: 'MANAGED' }), queryFn: () => adminApi.installations({ mode: 'MANAGED' }) });
  const act = useMutation({
    mutationFn: (a: 'start' | 'pause' | 'resume' | 'advance') => adminApi.fleetAction(release!.id, a),
    onSuccess: (c) => (toast.success(`Fleet ${c.fleet_status?.toLowerCase()} · stage ${c.fleet_stage_no ?? 0}`), void qc.invalidateQueries({ queryKey: adminKeys.release(release!.id) })),
    onError: (e) => (e as { code?: string }).code !== 'MFA_CANCELLED' && toast.error(errorMessage(e)),
  });
  const c = detail.data?.campaign;
  const canManage = can(user, 'fleet.manage');
  return (
    <>
      <PageHeader
        title="Managed Fleet"
        sub="Hosted (managed) installations are updated by Livezen in the maintenance window, in stages. MFA required."
        actions={candidates.length > 0 && <FilterSelect label="Release" value={release?.id ?? ''} onChange={setSel} options={candidates.map((r) => ({ value: r.id, label: `${r.app.name} ${r.version}` }))} />}
      />
      {!release ? (
        rel.isLoading ? <Spinner /> : <EmptyState title="No server release is ready for the fleet" />
      ) : !c ? (
        <Loading q={detail} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
          <Card>
            <CardHeader title={`${release.app.name} ${release.version}`} actions={<ReleaseStatusBadge status={release.status} />} />
            <div className="px-4 pb-4">
              <KeyValue
                rows={[
                  ['Fleet status', <Badge tone={c.fleet_status === 'RUNNING' ? 'green' : c.fleet_status === 'PAUSED' ? 'amber' : 'grey'}>{c.fleet_status ?? 'NOT STARTED'}</Badge>],
                  ['Stage', `${c.fleet_stage_no ?? 0} of ${c.fleet_stages.length} · ${c.fleet_stages.join('% → ')}%`],
                  ['Window', c.fleet_window ?? '—'],
                  ['Eligible installations', formatNumber(c.eligible_count)],
                  ['Urgency', titleCase(c.urgency)],
                ]}
              />
              {canManage && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {(!c.fleet_status || c.fleet_status === 'NOT_STARTED') && (
                    <Button variant="primary" size="sm" loading={act.isPending} disabled={release.status !== 'PUBLISHED'} onClick={() => act.mutate('start')}>
                      Start fleet update
                    </Button>
                  )}
                  {c.fleet_status === 'RUNNING' && (
                    <>
                      <Button size="sm" loading={act.isPending} onClick={() => act.mutate('pause')}>
                        Pause
                      </Button>
                      <Button size="sm" variant="primary" loading={act.isPending} onClick={() => act.mutate('advance')}>
                        Advance stage
                      </Button>
                    </>
                  )}
                  {c.fleet_status === 'PAUSED' && (
                    <Button size="sm" variant="primary" loading={act.isPending} onClick={() => act.mutate('resume')}>
                      Resume
                    </Button>
                  )}
                </div>
              )}
              {release.status !== 'PUBLISHED' && <p className="mt-2 text-[11.5px] text-subtle">The fleet can start once the release is published.</p>}
            </div>
          </Card>
          <Card>
            <CardHeader title="Managed installations" />
            {!installs.data ? (
              <Loading q={installs} />
            ) : (
              <Table label="Managed installations">
                <thead>
                  <tr>
                    <Th>Installation</Th>
                    <Th>Edition</Th>
                    <Th>Version</Th>
                    <Th>Last run</Th>
                  </tr>
                </thead>
                <tbody>
                  {installs.data.data.map((i) => (
                    <tr key={i.installation_id}>
                      <Td>{i.name}</Td>
                      <Td>{titleCase(i.edition)}</Td>
                      <Td className="font-mono">{i.version}</Td>
                      <Td>{i.last_run_status ?? '—'}</Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        </div>
      )}
    </>
  );
}
