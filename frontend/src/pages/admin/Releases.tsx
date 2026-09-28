import { useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { adminApi, adminKeys, type ReleaseFilters } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { formatDate, timeAgo } from '@/core/format';
import { isSemver } from '@/core/semver';
import { RELEASE_GROUPS } from '@/layouts/adminNav';
import type { ChannelCode, CreateReleaseBody, ReleaseStatus, ReleaseType } from '@/types/api';
import { Alert, Button, ButtonLink, Card, Checkbox, EmptyState, ErrorState, FilterSelect, PageHeader, Select, Spinner, Table, Td, TextArea, TextField, Th } from '@/components/ui';
import { ChannelBadge, ReleaseStatusBadge, ReleaseTypeBadge } from '@/components/StatusBadges';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';

const CHANNELS: ChannelCode[] = ['STABLE', 'BETA', 'DEV', 'INTERNAL', 'CUSTOM'];
const TYPES: ReleaseType[] = ['OPTIONAL', 'RECOMMENDED', 'MANDATORY', 'CRITICAL'];

export function ReleasesList() {
  const { user } = useAuth();
  const [sp, setSp] = useSearchParams();
  const group = sp.get('group') ?? (sp.get('status') ? '' : 'draft');
  const statuses = sp.get('status') ?? RELEASE_GROUPS[group]?.statuses ?? '';
  const [q, setQ] = useState(sp.get('q') ?? '');
  const filters: ReleaseFilters = { status: statuses || undefined, app: sp.get('app') || undefined, channel: sp.get('channel') || undefined, platform: sp.get('platform') || undefined, q: sp.get('q') || undefined, limit: 50 };
  useDocumentTitle(`${RELEASE_GROUPS[group]?.label ?? 'All'} releases · Update Center`);
  const apps = useQuery({ queryKey: adminKeys.apps, queryFn: adminApi.apps });
  const list = useQuery({ queryKey: adminKeys.releases(filters), queryFn: () => adminApi.releases(filters) });
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(sp);
    if (v) n.set(k, v);
    else n.delete(k);
    setSp(n, { replace: true });
  };
  const counts = list.data?.counts ?? {};
  const groupCount = (g: string) => RELEASE_GROUPS[g].statuses.split(',').reduce((s, st) => s + (counts[st as ReleaseStatus] ?? 0), 0);
  return (
    <>
      <PageHeader
        title="Releases"
        sub="Every release across applications and channels."
        actions={
          can(user, 'release.create') && (
            <ButtonLink to="/admin/releases/new" variant="primary" size="sm" icon={<Plus className="size-3.5" aria-hidden />}>
              New Release
            </ButtonLink>
          )
        }
      />
      <Tabs
        label="Release status"
        active={group}
        items={Object.entries(RELEASE_GROUPS).map(([key, g]) => ({ id: key, label: g.label, to: `/admin/releases?group=${key}`, count: list.data ? groupCount(key) : undefined }))}
        className="mb-3"
      />
      <form
        className="mb-3 flex flex-wrap items-center gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          set('q', q);
        }}
        role="search"
      >
        <label htmlFor="rel-q" className="sr-only">
          Search releases
        </label>
        <input id="rel-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search app or version…" className="h-8 w-56 rounded-lg border border-line bg-white px-3 text-[12.5px] focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none" />
        <FilterSelect label="App" value={sp.get('app') ?? ''} onChange={(v) => set('app', v)} options={[{ value: '', label: 'All' }, ...(apps.data?.data ?? []).map((a) => ({ value: a.slug, label: a.name }))]} />
        <FilterSelect label="Channel" value={sp.get('channel') ?? ''} onChange={(v) => set('channel', v)} options={[{ value: '', label: 'All' }, ...CHANNELS.map((c) => ({ value: c, label: c }))]} />
        <FilterSelect
          label="Platform"
          value={sp.get('platform') ?? ''}
          onChange={(v) => set('platform', v)}
          options={[{ value: '', label: 'All' }, ...['windows', 'macos', 'linux', 'server'].map((p) => ({ value: p, label: p }))]}
        />
      </form>
      <Card>
        {list.isLoading ? (
          <Spinner />
        ) : list.error ? (
          <div className="p-4">
            <ErrorState error={list.error} retry={list.refetch} />
          </div>
        ) : list.data!.data.length === 0 ? (
          <EmptyState title="No releases here">Try another status or clear the filters.</EmptyState>
        ) : (
          <Table label="Releases">
            <thead>
              <tr>
                <Th>Application</Th>
                <Th>Version</Th>
                <Th>Channel</Th>
                <Th>Type</Th>
                <Th>Status</Th>
                <Th>Packages</Th>
                <Th>Release date</Th>
                <Th>Updated</Th>
              </tr>
            </thead>
            <tbody>
              {list.data!.data.map((r) => (
                <tr key={r.id} className="hover:bg-ground/60">
                  <Td>{r.app.name}</Td>
                  <Td>
                    <Link to={`/admin/releases/${r.id}`} className="font-mono font-semibold text-indigo hover:underline">
                      {r.version}
                    </Link>
                    <span className="ml-1 text-[11px] text-subtle">build {r.build}</span>
                  </Td>
                  <Td>
                    <ChannelBadge channel={r.channel} />
                  </Td>
                  <Td>
                    <ReleaseTypeBadge type={r.release_type} />
                  </Td>
                  <Td>
                    <ReleaseStatusBadge status={r.status} />
                  </Td>
                  <Td className="font-mono">
                    {r.packages_ready}/{r.packages_total}
                  </Td>
                  <Td>{formatDate(r.release_date)}</Td>
                  <Td className="text-subtle">{timeAgo(r.updated_at)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}

export function NewRelease() {
  useDocumentTitle('New release · Update Center');
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const [sp] = useSearchParams();
  const apps = useQuery({ queryKey: adminKeys.apps, queryFn: adminApi.apps });
  const [f, setF] = useState<CreateReleaseBody & { mandatory: boolean }>({
    app_id: sp.get('app') ?? '',
    version: '',
    build: 0,
    channel: 'STABLE',
    release_type: 'RECOMMENDED',
    release_date: '',
    minimum_supported_version: '',
    mandatory: false,
    mandatory_after: '',
    grace_minutes: null,
    notes: '',
    known_issues: '',
  });
  const app = useMemo(() => apps.data?.data.find((a) => a.id === f.app_id) ?? apps.data?.data[0], [apps.data, f.app_id]);
  const m = useMutation({
    mutationFn: () =>
      adminApi.createRelease({
        ...f,
        app_id: app!.id,
        build: Number(f.build),
        release_date: f.release_date || null,
        minimum_supported_version: f.minimum_supported_version || null,
        mandatory_after: f.mandatory ? f.mandatory_after || null : null,
        notes: f.notes || null,
        known_issues: f.known_issues || null,
      }),
    onSuccess: (r) => {
      toast.success(`${r.app.name} ${r.version} created as draft`);
      void qc.invalidateQueries({ queryKey: ['admin', 'releases'] });
      navigate(`/admin/releases/${r.id}/files`);
    },
  });
  const errs = m.error instanceof ApiError ? m.error.fieldErrors : {};
  const set = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => setF((x) => ({ ...x, [k]: v }));
  const submit = (e: FormEvent) => {
    e.preventDefault();
    m.mutate();
  };
  if (apps.isLoading) return <Spinner />;
  return (
    <>
      <PageHeader title="New release" breadcrumb={<Link className="link" to="/admin/releases">Releases</Link>} sub="Creates a DRAFT. Upload packages and write the changelog next." />
      <Card className="max-w-3xl p-5">
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
          {m.error && !Object.keys(errs).length && (
            <Alert tone="bad" className="sm:col-span-2">
              {errorMessage(m.error)}
            </Alert>
          )}
          <Select label="Application" required value={app?.id ?? ''} onChange={(e) => set('app_id', e.target.value)} options={(apps.data?.data ?? []).map((a) => ({ value: a.id, label: a.name }))} />
          <Select label="Channel" required value={f.channel} onChange={(e) => set('channel', e.target.value as ChannelCode)} options={(app?.channels.filter((c) => c.enabled).map((c) => c.code) ?? CHANNELS).map((c) => ({ value: c, label: c }))} />
          <TextField label="Version (SemVer)" required mono placeholder="2.7.0" value={f.version} onChange={(e) => set('version', e.target.value)} error={errs.version ?? (f.version && !isSemver(f.version) ? 'Use MAJOR.MINOR.PATCH, e.g. 2.7.0 or 2.8.0-beta.3' : null)} />
          <TextField label="Build number" required mono type="number" min={1} value={f.build || ''} onChange={(e) => set('build', Number(e.target.value))} error={errs.build} hint="Must be greater than the previous build on this channel." />
          <Select label="Release type" value={f.release_type} onChange={(e) => set('release_type', e.target.value as ReleaseType)} options={TYPES.map((t) => ({ value: t, label: t.charAt(0) + t.slice(1).toLowerCase() }))} />
          <TextField label="Release date" type="date" value={f.release_date ?? ''} onChange={(e) => set('release_date', e.target.value)} />
          <TextField label="Minimum supported version" mono placeholder="2.5.0" value={f.minimum_supported_version ?? ''} onChange={(e) => set('minimum_supported_version', e.target.value)} hint="Leave empty for “no change”. Overrides the channel minimum upward only." error={errs.minimum_supported_version} />
          {f.release_type === 'CRITICAL' && <TextField label="Critical grace (minutes)" type="number" min={0} value={f.grace_minutes ?? ''} onChange={(e) => set('grace_minutes', e.target.value ? Number(e.target.value) : null)} />}
          <div className="flex flex-col gap-2 sm:col-span-2">
            <Checkbox label="Mandatory update" hint="Before the deadline clients see UPDATE_RECOMMENDED (mandatory); after it, UPDATE_REQUIRED." checked={f.mandatory} onChange={(e) => set('mandatory', e.target.checked)} />
            {f.mandatory && <TextField label="Mandatory after (UTC)" type="datetime-local" value={f.mandatory_after ?? ''} onChange={(e) => set('mandatory_after', e.target.value)} className="max-w-xs" />}
          </div>
          <TextArea label="Internal notes" className="sm:col-span-2" rows={2} value={f.notes ?? ''} onChange={(e) => set('notes', e.target.value)} />
          <TextArea label="Known issues" className="sm:col-span-2" rows={2} value={f.known_issues ?? ''} onChange={(e) => set('known_issues', e.target.value)} />
          <div className="flex gap-2 sm:col-span-2">
            <Button type="submit" variant="primary" loading={m.isPending} disabled={!app || !isSemver(f.version) || !f.build}>
              Create draft
            </Button>
            <ButtonLink to="/admin/releases">Cancel</ButtonLink>
          </div>
        </form>
      </Card>
    </>
  );
}
