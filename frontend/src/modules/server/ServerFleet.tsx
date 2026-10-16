import { useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { cx, formatNumber, timeAgo } from '@/core/format';
import type { Installation } from '@/types/api';
import { BarList, Button, Card, CardHeader, EmptyState, FilterSelect, PageHeader, Table, Td, Th } from '@/components/ui';
import { HealthBadge } from '@/components/StatusBadges';
import { QueryState, RunBadge, editionLabel, policyLabel, ringLabel } from './serverUi';

type Segment = '' | 'on_latest' | 'below_minimum' | 'on_hold' | 'stale';

export function installationKind(i: Pick<Installation, 'mode' | 'offline_mode'>): string {
  const base = i.mode === 'MANAGED' ? 'Livezen-hosted' : 'Self-hosted';
  return i.offline_mode || i.mode === 'AIR_GAPPED' ? `${base} · offline mode` : base;
}

/** DL-4 Installations fleet: edition, channel, version, heartbeat, policy, ring, last run and health per installation. */
export default function ServerFleet() {
  useDocumentTitle('Installations · Update Center');
  const [sp, setSp] = useSearchParams();
  const segment = (sp.get('segment') ?? '') as Segment;
  const edition = sp.get('edition') ?? '';
  const version = sp.get('version') ?? '';
  const qText = sp.get('q') ?? '';
  const [search, setSearch] = useState(qText);
  const stats = useQuery({ queryKey: adminKeys.installationStats, queryFn: adminApi.installationStats });
  const seg = stats.data?.segments;
  const latest = seg?.latest_stable ?? null;
  const filters = {
    edition: edition || undefined,
    version: segment === 'on_latest' ? (latest ?? undefined) : version || undefined,
    segment: segment === 'on_latest' || segment === '' ? undefined : segment,
    q: qText || undefined,
    limit: 200,
  };
  const list = useQuery({ queryKey: adminKeys.installations(filters), queryFn: () => adminApi.installations(filters), enabled: segment !== 'on_latest' || !!latest });
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(sp);
    if (v) n.set(k, v);
    else n.delete(k);
    setSp(n, { replace: true });
  };
  const onSearch = (e: FormEvent) => {
    e.preventDefault();
    set('q', search.trim());
  };
  const versions = Array.from(new Set(Object.values(stats.data?.by_edition ?? {}).flatMap((l) => (l ?? []).map((v) => v.version)))).sort();
  const chips: { id: Segment; label: string }[] = [
    { id: '', label: `All ${formatNumber(seg?.all ?? 0)}` },
    ...(latest ? [{ id: 'on_latest' as Segment, label: `On ${latest} · ${formatNumber(seg?.on_latest ?? 0)}` }] : []),
    { id: 'below_minimum', label: `Below minimum · ${formatNumber(seg?.below_minimum ?? 0)}` },
    { id: 'on_hold', label: `Release on hold · ${formatNumber(seg?.on_hold ?? 0)}` },
    { id: 'stale', label: `No heartbeat 24 h · ${formatNumber(seg?.stale ?? 0)}` },
  ];
  const rows = list.data?.data ?? [];
  return (
    <>
      <PageHeader
        breadcrumb={
          <>
            Server › <span className="font-mono">lk.worksuite.server</span>
          </>
        }
        title="Installations"
        sub={`${formatNumber(seg?.all ?? list.data?.total ?? 0)} linked servers. Heartbeats carry versions, edition, policy and health only.`}
      />
      <Card>
        <div className="flex flex-wrap items-center gap-2 px-4 pt-3.5 pb-2">
          <form role="search" onSubmit={onSearch} className="flex min-w-0 flex-1 items-center gap-2 sm:max-w-xs">
            <label htmlFor="fleet-search" className="sr-only">
              Search installations
            </label>
            <input
              id="fleet-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search installations"
              className="h-8 w-full min-w-0 rounded-lg border border-line bg-white px-2.5 text-[12.5px] focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none"
            />
            <Button size="sm" type="submit" icon={<Search className="size-3.5" aria-hidden />} aria-label="Search">
              <span className="sr-only sm:not-sr-only">Search</span>
            </Button>
          </form>
          <FilterSelect label="Edition" value={edition} onChange={(v) => set('edition', v)} options={[{ value: '', label: 'All' }, ...(['FREE', 'BUSINESS', 'ENTERPRISE'] as const).map((e) => ({ value: e, label: editionLabel(e) }))]} />
          <FilterSelect label="Version" value={version} onChange={(v) => set('version', v)} options={[{ value: '', label: 'All' }, ...versions.map((v) => ({ value: v, label: v }))]} />
        </div>
        <div className="flex flex-wrap gap-1.5 px-4 pb-3" role="group" aria-label="Installation segments">
          {chips.map((c) => (
            <button
              key={c.id || 'all'}
              type="button"
              aria-pressed={segment === c.id}
              onClick={() => set('segment', c.id)}
              className={cx('rounded-full border px-3 py-1 text-[12px] font-semibold', segment === c.id ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-ground')}
            >
              {c.label}
            </button>
          ))}
        </div>
        {!list.data ? (
          <QueryState q={list} />
        ) : rows.length === 0 ? (
          <EmptyState title="No installations match">Change the search, filters or segment.</EmptyState>
        ) : (
          <Table label="Installations">
            <thead>
              <tr>
                <Th>Installation</Th>
                <Th>Edition</Th>
                <Th>Version</Th>
                <Th>Auto update</Th>
                <Th>Ring</Th>
                <Th>Heartbeat</Th>
                <Th>Last run</Th>
                <Th>Health</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((i) => (
                <tr key={i.installation_id}>
                  <Td>
                    <div className="font-semibold">{i.name || i.installation_id}</div>
                    <div className="text-[11px] text-subtle">{installationKind(i)}</div>
                  </Td>
                  <Td>{editionLabel(i.edition)}</Td>
                  <Td className={cx('font-mono', i.below_minimum && 'font-semibold text-warn')}>
                    {i.version ?? '—'}
                    {i.below_minimum && <span className="sr-only"> (below minimum)</span>}
                  </Td>
                  <Td className="text-[12px]">{policyLabel(i.policy_mode)}</Td>
                  <Td className="text-[12px]">{i.offline_mode ? '—' : ringLabel(i.ring)}</Td>
                  <Td className="text-[11.5px] text-subtle">{i.offline_mode ? 'offline' : timeAgo(i.last_report_at)}</Td>
                  <Td>
                    <RunBadge status={i.last_run_status} />
                  </Td>
                  <Td>{i.health ? <HealthBadge status={i.health} /> : <span className="text-subtle">—</span>}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
        {list.data && rows.length > 0 && (
          <p className="px-4 py-2 text-[11.5px] text-subtle">
            {formatNumber(rows.length)} of {formatNumber(list.data.total)} · sorted by last heartbeat
          </p>
        )}
      </Card>
      {stats.data?.adoption && stats.data.adoption.length > 0 && (
        <Card className="mt-4">
          <CardHeader title="Adoption per release" sub="Installations on each published version" />
          <div className="px-4 pb-4">
            <BarList label="Adoption per release" items={stats.data.adoption.map((a) => ({ label: a.version, value: a.installations, display: `${formatNumber(a.installations)} · ${a.pct}%`, color: a.status === 'WITHDRAWN' ? 'bg-bad' : undefined }))} />
          </div>
        </Card>
      )}
    </>
  );
}
