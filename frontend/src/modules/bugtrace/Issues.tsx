/** Bug Trace B1 (Issues) and B2 (Issue detail). */
import { useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronDown, Download, ExternalLink } from 'lucide-react';
import { bugtraceApi, bugKeys, type IssuePatch } from '@/services/bugtrace';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import type { Issue, IssueDetail, IssueStatus } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { compactNumber, cx, formatDateTime, formatNumber, formatPct, timeAgo, titleCase } from '@/core/format';
import { Alert, Badge, Button, Card, CardHeader, ColumnChart, EmptyState, ErrorState, FilterSelect, KeyValue, Kpi, PageHeader, Spinner, Table, Td, TextArea, TextField, Th, type Tone } from '@/components/ui';
import { Modal } from '@/components/ui/Modal';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';

export const ISSUE_STATUS_TONE: Record<IssueStatus, Tone> = {
  NEW: 'red',
  TRIAGED: 'blue',
  IN_PROGRESS: 'indigo',
  RESOLVED: 'green',
  REGRESSED: 'amber',
  IGNORED: 'grey',
  DUPLICATE: 'grey',
  WONT_FIX: 'grey',
};
const PLATFORM_TONE: Record<string, Tone> = { web: 'blue', desktop: 'teal', mobile: 'purple', server: 'grey' };

export function IssueStatusBadge({ issue }: { issue: Pick<Issue, 'status' | 'resolve_version'> }) {
  return (
    <Badge tone={ISSUE_STATUS_TONE[issue.status]}>
      {issue.status.replace('_', ' ')}
      {issue.status === 'RESOLVED' && issue.resolve_version ? ` ${issue.resolve_version}` : ''}
    </Badge>
  );
}

function Sparkline({ data }: { data: number[] }) {
  const max = Math.max(1, ...data);
  const pts = data.map((v, i) => `${(i / Math.max(1, data.length - 1)) * 60},${18 - (v / max) * 16}`).join(' ');
  return (
    <svg width="60" height="20" aria-hidden className="text-brand">
      <polyline points={pts} fill="none" stroke="currentColor" strokeWidth="1.5" />
    </svg>
  );
}

function short(a: string, b: string) {
  const ms = Date.now() - new Date(a).getTime();
  const f = (x: number) => (x < 3600_000 ? `${Math.max(1, Math.round(x / 60000))}m` : x < 86400_000 ? `${Math.round(x / 3600_000)}h` : `${Math.round(x / 86400_000)}d`);
  return `${f(ms)} · ${f(Date.now() - new Date(b).getTime())}`;
}

function toCsv(rows: Issue[]): string {
  const esc = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  return ['id,title,culprit,platform,status,priority,first_seen,last_seen,events,users,last_release', ...rows.map((i) => [i.id, i.title, i.culprit, i.project.platform, i.status, i.priority, i.first_seen, i.last_seen, i.events_count, i.users_count, i.last_release].map(esc).join(','))].join('\n');
}

export function IssuesPage() {
  useDocumentTitle('Issues · Bug Trace');
  const { user } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [sp, setSp] = useSearchParams();
  const [q, setQ] = useState(sp.get('q') ?? '');
  const filters = {
    project: sp.get('project') || undefined,
    platform: sp.get('platform') || undefined,
    release: sp.get('release') || undefined,
    status: sp.get('status') ?? 'UNRESOLVED',
    type: sp.get('type') || undefined,
    sort: sp.get('sort') ?? 'users',
    q: sp.get('q') || undefined,
    limit: 50,
  };
  const list = useQuery({ queryKey: bugKeys.issues(filters), queryFn: () => bugtraceApi.issues(filters) });
  const projects = useQuery({ queryKey: bugKeys.projects, queryFn: bugtraceApi.projects });
  const [selected, setSelected] = useState<string[]>([]);
  const bulk = useMutation({
    mutationFn: (patch: IssuePatch) => bugtraceApi.bulk(selected, patch),
    onSuccess: () => (toast.success(`${selected.length} issues updated`), setSelected([]), void qc.invalidateQueries({ queryKey: ['bt', 'issues'] })),
    onError: (e) => toast.error(errorMessage(e)),
  });
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(sp);
    if (v) n.set(k, v);
    else n.delete(k);
    setSp(n, { replace: true });
  };
  const k = list.data?.kpis;
  const releases = useMemo(() => Array.from(new Set((list.data?.data ?? []).flatMap((i) => [i.first_release, i.last_release]).filter(Boolean))) as string[], [list.data]);
  const exportCsv = () => {
    const blob = new Blob([toCsv(list.data?.data ?? [])], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'bugtrace-issues.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  };
  return (
    <>
      <PageHeader
        title="Issues"
        sub="Grouped crashes, errors and hangs from every WorkSuite app."
        actions={can(user, 'bugtrace.export') && <Button size="sm" icon={<Download className="size-3.5" aria-hidden />} onClick={exportCsv} disabled={!list.data}>Export</Button>}
      />
      {k && (
        <div className="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <Kpi label="Unresolved" value={formatNumber(k.unresolved)} sub="open issues" />
          <Kpi label="Regressed" value={formatNumber(k.regressed)} tone="warn" sub="reappeared after a fix" />
          <Kpi label="Crash-free users · 24 h" value={formatPct(k.crash_free_users, 2)} tone="ok" />
          <Kpi label="Events · 24 h" value={compactNumber(k.events_24h)} sub="after sampling" />
          <Kpi label="User reports" value={formatNumber(k.untriaged_reports)} sub={<Link className="link" to="/admin/bugtrace/reports">untriaged</Link>} />
        </div>
      )}
      <form
        className="mb-3 flex flex-wrap items-center gap-2"
        role="search"
        onSubmit={(e) => {
          e.preventDefault();
          set('q', q);
        }}
      >
        <label htmlFor="bt-q" className="sr-only">
          Search issues
        </label>
        <input id="bt-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search message, file, function…" className="h-8 w-64 rounded-lg border border-line bg-white px-3 text-[12.5px] focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none" />
        <FilterSelect label="App" value={sp.get('project') ?? ''} onChange={(v) => set('project', v)} options={[{ value: '', label: 'All' }, ...(projects.data?.data ?? []).map((p) => ({ value: p.id, label: p.name }))]} />
        <FilterSelect label="Platform" value={sp.get('platform') ?? ''} onChange={(v) => set('platform', v)} options={[{ value: '', label: 'All' }, ...['web', 'desktop', 'mobile', 'server'].map((p) => ({ value: p, label: titleCase(p) }))]} />
        <FilterSelect label="Release" value={sp.get('release') ?? ''} onChange={(v) => set('release', v)} options={[{ value: '', label: 'All' }, ...releases.map((r) => ({ value: r, label: r }))]} />
        <FilterSelect label="Status" value={filters.status} onChange={(v) => set('status', v)} options={[{ value: 'UNRESOLVED', label: 'Unresolved' }, ...['NEW', 'TRIAGED', 'IN_PROGRESS', 'REGRESSED', 'RESOLVED', 'IGNORED'].map((s) => ({ value: s, label: titleCase(s) })), { value: '', label: 'Any' }]} />
        <FilterSelect label="Type" value={sp.get('type') ?? ''} onChange={(v) => set('type', v)} options={[{ value: '', label: 'Crash, Error, Hang' }, ...['crash', 'error', 'hang', 'message'].map((s) => ({ value: s, label: titleCase(s) }))]} />
        <FilterSelect label="Sort" value={filters.sort} onChange={(v) => set('sort', v)} options={[{ value: 'users', label: 'Users affected' }, { value: 'events', label: 'Events' }, { value: 'last_seen', label: 'Last seen' }, { value: 'first_seen', label: 'First seen' }]} />
      </form>
      {selected.length > 0 && can(user, 'bugtrace.triage') && (
        <div className="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-[12.5px]" role="region" aria-label="Bulk actions">
          <strong>{selected.length} selected</strong>
          <Button size="sm" onClick={() => bulk.mutate({ status: 'RESOLVED', resolution: 'NEXT_RELEASE' })}>Resolve in next release</Button>
          <Button size="sm" onClick={() => bulk.mutate({ status: 'IGNORED' })}>Ignore</Button>
          <Button size="sm" onClick={() => bulk.mutate({ status: 'TRIAGED' })}>Mark triaged</Button>
          <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
        </div>
      )}
      <Card>
        {list.isLoading ? (
          <Spinner />
        ) : list.error || !list.data ? (
          <div className="p-4">
            <ErrorState error={list.error} retry={list.refetch} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title="No issues match">Nice. Try another status or clear the filters.</EmptyState>
        ) : (
          <Table label="Issues">
            <thead>
              <tr>
                <Th className="w-8">
                  <input type="checkbox" aria-label="Select all issues" className="size-4 accent-brand" checked={selected.length === list.data.data.length} onChange={(e) => setSelected(e.target.checked ? list.data!.data.map((i) => i.id) : [])} />
                </Th>
                <Th>Issue</Th>
                <Th>Platform</Th>
                <Th>Status</Th>
                <Th>First · Last</Th>
                <Th className="text-right">Events</Th>
                <Th className="text-right">Users</Th>
                <Th>24 h</Th>
                <Th>Releases</Th>
              </tr>
            </thead>
            <tbody>
              {list.data.data.map((i) => (
                <tr key={i.id} className="hover:bg-ground/60">
                  <Td>
                    <input type="checkbox" aria-label={`Select ${i.id}`} className="size-4 accent-brand" checked={selected.includes(i.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, i.id] : selected.filter((x) => x !== i.id))} />
                  </Td>
                  <Td className="max-w-[440px]">
                    <Link to={`/admin/bugtrace/issues/${i.id}`} className="font-semibold text-ink hover:text-brand">
                      {i.title}
                    </Link>
                    <div className="truncate font-mono text-[11px] text-muted">
                      <span className="text-subtle">{i.id}</span> · {i.culprit}
                    </div>
                  </Td>
                  <Td>
                    <Badge tone={PLATFORM_TONE[i.project.platform] ?? 'grey'}>{i.project.platform}</Badge>
                  </Td>
                  <Td>
                    <IssueStatusBadge issue={i} />
                  </Td>
                  <Td className="font-mono text-[11.5px] whitespace-nowrap">{short(i.first_seen, i.last_seen)}</Td>
                  <Td className="text-right font-mono">{formatNumber(i.events_count)}</Td>
                  <Td className="text-right font-mono">{i.users_count ? formatNumber(i.users_count) : `${i.installations_count} inst.`}</Td>
                  <Td>
                    <Sparkline data={i.sparkline} />
                  </Td>
                  <Td className="font-mono text-[11.5px]">{i.first_release === i.last_release ? i.last_release : `${i.first_release}–${i.last_release}`}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}

// ---------------------------------------------------------------- B2 detail

function LinkDialog({ kind, onClose, issue }: { kind: 'github' | 'task'; onClose: () => void; issue: IssueDetail }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [ref, setRef] = useState('');
  const [url, setUrl] = useState('');
  const m = useMutation({ mutationFn: () => bugtraceApi.link(issue.id, { kind, ref, url }), onSuccess: () => (toast.success('Linked'), void qc.invalidateQueries({ queryKey: bugKeys.issue(issue.id) }), onClose()), onError: (e) => toast.error(errorMessage(e)) });
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={kind === 'github' ? 'Link GitHub issue' : 'Create task'}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={m.isPending} disabled={!ref} onClick={() => m.mutate()}>
            Link
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <TextField label={kind === 'github' ? 'Reference (owner/repo#123)' : 'Task reference'} value={ref} onChange={(e) => setRef(e.target.value)} data-autofocus="" />
        <TextField label="URL" type="url" value={url} onChange={(e) => setUrl(e.target.value)} />
      </div>
    </Modal>
  );
}

function SymbolicationNote({ issue }: { issue: IssueDetail }) {
  const native = issue.frames.some((f) => f.module);
  const source = native ? 'debug symbols' : 'source map';
  switch (issue.symbolication) {
    case 'partial':
      return <span className="text-warn">Partly symbolicated: some {source} are missing for {issue.last_release}</span>;
    case 'missing':
      return <span className="text-warn">Not symbolicated: upload {source} for {issue.last_release}</span>;
    case 'pending':
      return <span className="text-subtle">Minidump waiting for processing</span>;
    case 'n/a':
      return null;
    default:
      return <span className="text-ok">✓ symbolicated with {source} {issue.last_release}</span>;
  }
}

function StackTrace({ issue }: { issue: IssueDetail }) {
  const [all, setAll] = useState(false);
  const frames = all ? issue.frames : issue.frames.filter((f) => f.in_app);
  return (
    <Card>
      <CardHeader
        title="Stack trace"
        sub={<SymbolicationNote issue={issue} />}
        actions={
          <button type="button" className="link text-[11.5px]" onClick={() => setAll((a) => !a)}>
            {all ? 'in-app frames only' : 'show all frames'}
          </button>
        }
      />
      <ol className="px-4 pb-4 font-mono text-[11.5px]">
        {frames.map((f, i) => (
          <li key={i} className="border-b border-line py-1.5 last:border-0">
            <div className="flex justify-between gap-3">
              <span className={cx(f.in_app ? 'text-ink' : 'text-subtle')}>
                {f.module && !f.file ? <span className="text-subtle">{f.module} · </span> : null}
                {f.function ?? '??'}
              </span>
              <span className="truncate text-right text-subtle">
                {f.file}
                {f.line != null ? `:${f.line}` : ''}
                {f.col != null ? `:${f.col}` : ''}
              </span>
            </div>
            {f.context && (
              <pre className="mt-1.5 overflow-x-auto rounded-md bg-navy p-2 text-[11px] leading-relaxed text-[#dfe4f5]" aria-label={`Source context for ${f.function}`}>
                {f.context.pre.map((l, j) => (
                  <div key={`p${j}`}>
                    <span className="mr-2 inline-block w-8 text-right text-[#8e97bd]">{(f.line ?? 0) - f.context!.pre.length + j}</span>
                    {l}
                  </div>
                ))}
                <div className="bg-bad/80 text-white">
                  <span className="mr-2 inline-block w-8 text-right">{f.line}</span>
                  {f.context.line}
                </div>
                {f.context.post.map((l, j) => (
                  <div key={`q${j}`}>
                    <span className="mr-2 inline-block w-8 text-right text-[#8e97bd]">{(f.line ?? 0) + j + 1}</span>
                    {l}
                  </div>
                ))}
              </pre>
            )}
          </li>
        ))}
      </ol>
    </Card>
  );
}

export function IssueDetailPage() {
  const { id = '' } = useParams();
  const { user } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({ queryKey: bugKeys.issue(id), queryFn: () => bugtraceApi.issue(id) });
  const users = useQuery({ queryKey: adminKeys.users, queryFn: adminApi.users, enabled: can(user, 'user.manage'), retry: false });
  const [tab, setTab] = useState('latest');
  const [link, setLink] = useState<null | 'github' | 'task'>(null);
  const [resolveOpen, setResolveOpen] = useState(false);
  const [resolveVersion, setResolveVersion] = useState('');
  const [comment, setComment] = useState('');
  useDocumentTitle(q.data ? `${q.data.id} · Bug Trace` : 'Issue · Bug Trace');
  const patch = useMutation({
    mutationFn: (p: IssuePatch) => bugtraceApi.patchIssue(id, p),
    onSuccess: () => (toast.success('Issue updated'), void qc.invalidateQueries({ queryKey: bugKeys.issue(id) }), void qc.invalidateQueries({ queryKey: ['bt', 'issues'] })),
    onError: (e) => toast.error(errorMessage(e)),
  });
  const addComment = useMutation({ mutationFn: () => bugtraceApi.comment(id, comment), onSuccess: () => (setComment(''), void qc.invalidateQueries({ queryKey: bugKeys.issue(id) })), onError: (e) => toast.error(errorMessage(e)) });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  const i = q.data;
  const triage = can(user, 'bugtrace.triage');
  const days = Array.from({ length: 7 }, (_, d) => {
    const slice = i.hourly.slice(d * 24, d * 24 + 24);
    return { label: slice[0]?.hour.slice(5, 10) ?? '', value: slice.reduce((s, h) => s + h.events, 0) };
  });
  const tags = Array.isArray(i.tags) ? i.tags : Object.entries(i.tags).map(([key, value]) => ({ key, value }));
  const assignees = users.data?.data.filter((u) => u.kind === 'STAFF' && u.status === 'ACTIVE') ?? (i.assignee ? [{ id: i.assignee.id, name: i.assignee.name }] : []);
  return (
    <>
      <nav aria-label="Breadcrumb" className="mb-1 text-[12px] text-subtle">
        <Link className="hover:text-ink" to="/admin/bugtrace/issues">
          Bug Trace › Issues
        </Link>{' '}
        › {i.id}
      </nav>
      <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2 text-[11.5px]">
            <Badge tone={PLATFORM_TONE[i.project.platform] ?? 'grey'}>{i.project.platform}</Badge>
            <IssueStatusBadge issue={i} />
            <span className="font-semibold">
              {i.priority} · {i.type}
            </span>
            <span className="font-mono text-subtle">{i.id}</span>
          </div>
          <h1 className="mt-1 text-[19px] font-bold tracking-tight">{i.title}</h1>
          <p className="font-mono text-[11.5px] text-muted">
            {i.culprit} · {i.project.name} {i.last_release}
          </p>
        </div>
        {triage && (
          <div className="flex flex-wrap items-center gap-2">
            <label className="sr-only" htmlFor="assignee">
              Assignee
            </label>
            <select id="assignee" className="h-8 rounded-lg border border-line bg-white px-2 text-[12px]" value={i.assignee?.id ?? ''} onChange={(e) => patch.mutate({ assignee_id: e.target.value || null })}>
              <option value="">Assign: nobody</option>
              {assignees.map((u) => (
                <option key={u.id} value={u.id}>
                  Assign: {u.name}
                </option>
              ))}
            </select>
            <label className="sr-only" htmlFor="priority">
              Priority
            </label>
            <select id="priority" className="h-8 rounded-lg border border-line bg-white px-2 text-[12px]" value={i.priority} onChange={(e) => patch.mutate({ priority: e.target.value })}>
              {['P0', 'P1', 'P2', 'P3', 'P4'].map((p) => (
                <option key={p}>{p}</option>
              ))}
            </select>
            <label className="sr-only" htmlFor="status">
              Status
            </label>
            <select id="status" className="h-8 rounded-lg border border-line bg-white px-2 text-[12px]" value={i.status} onChange={(e) => patch.mutate({ status: e.target.value })}>
              {['NEW', 'TRIAGED', 'IN_PROGRESS', 'RESOLVED', 'IGNORED', 'WONT_FIX'].map((s) => (
                <option key={s} value={s}>
                  {titleCase(s)}
                </option>
              ))}
            </select>
            <Button size="sm" onClick={() => setLink('github')}>
              Link GitHub issue
            </Button>
            <Button size="sm" onClick={() => setLink('task')}>
              Create task
            </Button>
            <div className="relative">
              <Button size="sm" variant="primary" aria-haspopup="menu" aria-expanded={resolveOpen} onClick={() => setResolveOpen((o) => !o)}>
                Resolve in next release <ChevronDown className="size-3.5" aria-hidden />
              </Button>
              {resolveOpen && (
                <div role="menu" className="absolute right-0 z-30 mt-1 w-64 rounded-xl border border-line bg-white p-2 shadow-xl">
                  <button role="menuitem" type="button" className="block w-full rounded px-2 py-1.5 text-left text-[12.5px] hover:bg-ground" onClick={() => (patch.mutate({ status: 'RESOLVED', resolution: 'NEXT_RELEASE' }), setResolveOpen(false))}>
                    Resolve in next release
                  </button>
                  <button role="menuitem" type="button" className="block w-full rounded px-2 py-1.5 text-left text-[12.5px] hover:bg-ground" onClick={() => (patch.mutate({ status: 'RESOLVED', resolution: 'NOW' }), setResolveOpen(false))}>
                    Resolve now
                  </button>
                  <div className="mt-1 flex gap-1 border-t border-line pt-2">
                    <label className="sr-only" htmlFor="resolve-version">
                      Resolve in version
                    </label>
                    <input id="resolve-version" placeholder="in version 3.4.1" className="h-8 min-w-0 flex-1 rounded border border-line px-2 font-mono text-[12px]" value={resolveVersion} onChange={(e) => setResolveVersion(e.target.value)} />
                    <Button size="sm" disabled={!resolveVersion} onClick={() => (patch.mutate({ status: 'RESOLVED', resolution: 'VERSION', resolve_version: resolveVersion }), setResolveOpen(false))}>
                      Set
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
      <Tabs
        label="Issue sections"
        active={tab}
        onSelect={setTab}
        className="mb-4"
        panelId="issue-panel"
        items={[
          { id: 'latest', label: 'Latest event' },
          { id: 'events', label: `All events ${formatNumber(i.events_count)}` },
          { id: 'reports', label: 'User reports', count: i.reports.length },
          { id: 'tags', label: 'Tags' },
          { id: 'activity', label: 'Activity' },
        ]}
      />
      <div id="issue-panel" role="tabpanel" className="grid items-start gap-4 xl:grid-cols-[1.6fr_1fr]">
        <div className="grid gap-4">
          {tab === 'latest' && (
            <>
              <StackTrace issue={i} />
              <Card>
                <CardHeader title="Breadcrumbs" sub="no input values, message bodies or file contents are ever recorded" />
                <Table label="Breadcrumbs">
                  <tbody>
                    {i.breadcrumbs.map((b, j) => (
                      <tr key={j}>
                        <Td className="w-20 font-mono text-[11.5px]">{b.timestamp}</Td>
                        <Td className={cx('w-24 text-[12px]', b.level === 'error' || b.category === 'error' ? 'font-semibold text-bad' : 'text-muted')}>{b.category}</Td>
                        <Td className="font-mono text-[11.5px]">{b.message}</Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </Card>
            </>
          )}
          {tab === 'events' && (
            <Card>
              <CardHeader title="Events by release" />
              <Table label="Events by release">
                <thead>
                  <tr>
                    <Th>Release</Th>
                    <Th className="text-right">Events</Th>
                    <Th>First seen</Th>
                    <Th>Last seen</Th>
                  </tr>
                </thead>
                <tbody>
                  {i.releases.map((r) => (
                    <tr key={r.release}>
                      <Td className="font-mono">{r.release}</Td>
                      <Td className="text-right font-mono">{formatNumber(r.events)}</Td>
                      <Td>{formatDateTime(r.first_seen)}</Td>
                      <Td>{formatDateTime(r.last_seen)}</Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          )}
          {tab === 'reports' && (
            <Card>
              <CardHeader title="Linked user reports" />
              <ul className="divide-y divide-line px-4 pb-3 text-[12.5px]">
                {i.reports.length === 0 && <li className="py-3 text-subtle">No user reports linked.</li>}
                {i.reports.map((r) => (
                  <li key={r.reference} className="py-2">
                    <Link className="link font-mono" to={`/admin/bugtrace/reports/${r.reference}`}>
                      {r.reference}
                    </Link>{' '}
                    “{r.title}” · {r.app} {r.release} {r.has_screenshot && '· with screenshot'}
                  </li>
                ))}
              </ul>
            </Card>
          )}
          {tab === 'tags' && (
            <Card>
              <CardHeader title="Tags" />
              <div className="flex flex-wrap gap-2 px-4 pb-4">
                {tags.map((t) => (
                  <span key={t.key} className="rounded-md border border-line px-2 py-1 font-mono text-[11.5px]">
                    <span className="text-subtle">{t.key}:</span> {t.value}
                  </span>
                ))}
              </div>
              <div className="grid gap-4 px-4 pb-4 sm:grid-cols-2">
                {(Object.entries(i.distributions) as [string, { name: string; count: number }[]][]).map(([k, list]) => (
                  <div key={k}>
                    <h3 className="label-caps mb-1">{k}</h3>
                    <ul className="space-y-0.5 text-[12px]">
                      {list.map((d) => (
                        <li key={d.name} className="flex justify-between">
                          <span>{d.name}</span>
                          <span className="font-mono text-muted">{d.count}%</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            </Card>
          )}
          {tab === 'activity' && (
            <Card>
              <CardHeader title="Activity and comments" />
              <ul className="divide-y divide-line px-4 text-[12.5px]">
                {i.comments.map((c) => (
                  <li key={c.id} className="py-2">
                    <strong>{c.user}</strong> <span className="text-subtle">{timeAgo(c.created_at)}</span>
                    <p className={cx(c.kind === 'activity' && 'text-muted italic')}>{c.body}</p>
                  </li>
                ))}
                {i.comments.length === 0 && <li className="py-3 text-subtle">No comments yet.</li>}
              </ul>
              {triage && (
                <form
                  className="space-y-2 p-4"
                  onSubmit={(e) => {
                    e.preventDefault();
                    addComment.mutate();
                  }}
                >
                  <TextArea label="Add comment" rows={2} value={comment} onChange={(e) => setComment(e.target.value)} />
                  <Button type="submit" size="sm" variant="primary" disabled={!comment.trim()} loading={addComment.isPending}>
                    Comment
                  </Button>
                </form>
              )}
            </Card>
          )}
        </div>
        <div className="grid gap-4">
          <Card>
            <CardHeader title="Occurrences · 7 days" />
            <div className="px-4 pb-4">
              <ColumnChart label="Occurrences per day" data={days} height={90} color="bg-indigo" />
              <p className="mt-2 text-[11.5px] text-subtle">
                First seen {formatDateTime(i.first_seen)} in {i.first_release} · {formatNumber(i.users_count)} users · {i.installations_count} installations
              </p>
            </div>
          </Card>
          <Card>
            <CardHeader title="Context" />
            <div className="px-4 pb-4">
              <KeyValue
                rows={[
                  ['Release', <span className="font-mono">{i.first_release} (first seen)</span>],
                  ['Browser', i.distributions.browser?.map((d) => `${d.name} ${d.count}%`).join(' · ') || '—'],
                  ['OS', i.distributions.os?.map((d) => d.name).join(' · ') || '—'],
                  ['Edition', i.distributions.edition?.map((d) => d.name).join(' · ') || '—'],
                  ['Installations', `${i.tenant_count ?? i.installations_count} (pseudonymous)`],
                  ['User', <span className="font-mono">u_hmac:{i.id.slice(-3).toLowerCase()}9f…</span>],
                  ['Scrubbed fields', `${i.scrubbed_fields}`],
                ]}
              />
            </div>
          </Card>
          <Card>
            <CardHeader title="Linked user reports" />
            <div className="px-4 pb-4 text-[12px]">
              {i.reports.map((r) => (
                <p key={r.reference}>
                  <Link className="link font-mono" to={`/admin/bugtrace/reports/${r.reference}`}>
                    {r.reference}
                  </Link>{' '}
                  “{r.title}”{r.has_screenshot ? ' — with screenshot' : ''}
                </p>
              ))}
              {!i.reports.length && <p className="text-subtle">None yet.</p>}
              <p className="mt-2 text-[11.5px] text-subtle">Reporters are notified when this issue is resolved in a release.</p>
            </div>
          </Card>
          {i.links.length > 0 && (
            <Card>
              <CardHeader title="Links" />
              <ul className="px-4 pb-4 text-[12.5px]">
                {i.links.map((l, j) => (
                  <li key={j}>
                    <a className="link inline-flex items-center gap-1" href={l.url} target="_blank" rel="noopener noreferrer">
                      {l.kind}: {l.ref} <ExternalLink className="size-3" aria-hidden />
                    </a>
                  </li>
                ))}
              </ul>
            </Card>
          )}
          {i.status === 'REGRESSED' && <Alert tone="warn">Regressed: this issue occurred again in a version ≥ the resolving version {i.resolve_version}.</Alert>}
        </div>
      </div>
      {link && <LinkDialog kind={link} issue={i} onClose={() => setLink(null)} />}
    </>
  );
}
