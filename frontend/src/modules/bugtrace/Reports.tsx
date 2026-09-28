/** Bug Trace B3 (User reports inbox), B5 (Release health) and Alerts CRUD. */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { bugtraceApi, bugKeys } from '@/services/bugtrace';
import { errorMessage } from '@/services/api';
import type { BugAlert, ReleaseHealth, ReportSummary } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { cx, formatNumber, formatPct, timeAgo, titleCase } from '@/core/format';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, EmptyState, ErrorState, FilterSelect, KeyValue, PageHeader, Select, Spinner, Table, Td, TextArea, TextField, Th, type Tone } from '@/components/ui';
import { Modal } from '@/components/ui/Modal';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';

const APP_TONE: Record<string, Tone> = { Web: 'blue', Desktop: 'teal', Mobile: 'purple', Server: 'grey' };
const INBOX = [
  { id: 'UNTRIAGED', label: 'Untriaged' },
  { id: 'LINKED', label: 'Linked' },
  { id: 'FEATURE_REQUEST', label: 'Feature requests' },
  { id: 'NEED_INFO', label: 'Need info' },
  { id: 'CLOSED', label: 'Closed' },
];

function ReportRow({ r, active }: { r: ReportSummary; active: boolean }) {
  return (
    <Link to={`/admin/bugtrace/reports/${r.reference}`} aria-current={active ? 'true' : undefined} className={cx('block border-b border-line px-3 py-2.5 hover:bg-ground', active && 'bg-brand-50')}>
      <div className="flex items-center gap-2">
        <Badge tone={APP_TONE[r.app] ?? 'grey'}>{r.app}</Badge>
        <span className="truncate text-[12.5px] font-semibold">{r.title}</span>
      </div>
      <div className="mt-0.5 truncate text-[11.5px] text-subtle">
        <span className="font-mono">{r.reference}</span> · {r.release} · {timeAgo(r.created_at)}
        {r.has_screenshot && ' · screenshot'}
        {r.suggested_issue && ` · matches ${r.suggested_issue.id}`}
      </div>
    </Link>
  );
}

function ScreenshotPlaceholder() {
  return (
    <div aria-label="Screenshot preview (annotated, personal data blurred)" role="img" className="flex h-44 overflow-hidden rounded-lg border border-line bg-[#f5f6fa]">
      <div className="w-1/4 bg-navy" />
      <div className="flex-1 p-3">
        <div className="mb-3 h-3 w-3/4 rounded bg-[#dfe3ef]" />
        <div className="relative h-24 rounded-md border-2 border-[#f59f00] bg-[#fdecea]/70 p-3">
          <div className="h-2 w-2/3 rounded bg-[#e3a9a3] blur-[2px]" />
          <div className="mt-2 h-2 w-1/2 rounded bg-[#e3a9a3] blur-[2px]" />
        </div>
      </div>
    </div>
  );
}

function ReportDetailPane({ reference }: { reference: string }) {
  const { user } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const q = useQuery({ queryKey: bugKeys.report(reference), queryFn: () => bugtraceApi.report(reference) });
  const [reply, setReply] = useState('');
  const [linkTo, setLinkTo] = useState('');
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: bugKeys.report(reference) });
    void qc.invalidateQueries({ queryKey: ['bt', 'reports'] });
  };
  const patch = useMutation({ mutationFn: (b: { status?: string; issue_id?: string; close_reason?: string }) => bugtraceApi.patchReport(reference, b), onSuccess: () => (toast.success('Report updated'), refresh()), onError: (e) => toast.error(errorMessage(e)) });
  const createIssue = useMutation({ mutationFn: () => bugtraceApi.createIssueFromReport(reference), onSuccess: (r) => (toast.success(`Issue ${r.id} created`), refresh(), navigate(`/admin/bugtrace/issues/${r.id}`)), onError: (e) => toast.error(errorMessage(e)) });
  const sendReply = useMutation({ mutationFn: () => bugtraceApi.reply(reference, reply), onSuccess: () => (toast.success('Reply sent to the reporter'), setReply(''), refresh()), onError: (e) => toast.error(errorMessage(e)) });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  const r = q.data;
  const triage = can(user, 'bugtrace.triage');
  const shot = r.attachments.find((a) => a.kind === 'screenshot');
  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="font-mono text-[12px] text-subtle">{r.reference}</span>
        <h2 className="text-[17px] font-bold">{r.title}</h2>
        <Badge>{r.status.replace('_', ' ')}</Badge>
      </div>
      <Card className="p-4 text-[12.5px]">
        <h3 className="font-bold">What happened</h3>
        <p className="mb-2">{r.description}</p>
        {r.steps.length > 0 && (
          <>
            <h3 className="font-bold">Steps</h3>
            <ol className="mb-2 list-decimal pl-5">
              {r.steps.map((s, i) => (
                <li key={i}>{s}</li>
              ))}
            </ol>
          </>
        )}
        {r.expected && (
          <>
            <h3 className="font-bold">Expected</h3>
            <p>{r.expected}</p>
          </>
        )}
      </Card>
      <div className="grid gap-3 lg:grid-cols-[1.3fr_1fr]">
        <Card className="p-4">
          <h3 className="mb-2 text-[12.5px] font-bold">Screenshot (annotated by reporter, personal data blurred)</h3>
          {shot?.url ? <img src={shot.url} alt="Reporter screenshot, annotated and blurred" className="max-h-72 rounded-lg border border-line" /> : r.has_screenshot ? <ScreenshotPlaceholder /> : <p className="text-[12px] text-subtle">No screenshot attached.</p>}
          {r.attachments.filter((a) => a.kind !== 'screenshot').map((a) => (
            <p key={a.id} className="mt-2 text-[12px]">
              {titleCase(a.kind)} attached {a.url && <a className="link" href={a.url}>download</a>}
            </p>
          ))}
        </Card>
        <Card className="p-4">
          <h3 className="mb-1 text-[12.5px] font-bold">Context (auto-attached)</h3>
          <KeyValue
            rows={[
              ...Object.entries(r.context).map(([k, v]) => [titleCase(k), k === 'last_error' ? <Link className="link" to={`/admin/bugtrace/issues/${v}`}>{String(v)}</Link> : String(v)] as [string, React.ReactNode]),
              ['Severity (user)', titleCase(r.severity)],
              ...(r.contact ? ([['Reporter email', r.contact.email]] as [string, string][]) : []),
            ]}
          />
        </Card>
      </div>
      {triage && (
        <Card className="flex flex-wrap items-center gap-2 p-3 text-[12.5px]">
          {r.suggested_issue && !r.issue ? (
            <span className="mr-auto">
              <strong>Suggested:</strong> same error fingerprint as issue{' '}
              <Link className="link font-mono" to={`/admin/bugtrace/issues/${r.suggested_issue.id}`}>
                {r.suggested_issue.id}
              </Link>
            </span>
          ) : r.issue ? (
            <span className="mr-auto">
              Linked to{' '}
              <Link className="link font-mono" to={`/admin/bugtrace/issues/${r.issue.id}`}>
                {r.issue.id}
              </Link>
            </span>
          ) : (
            <span className="mr-auto flex items-center gap-2">
              <label htmlFor="link-issue" className="sr-only">
                Issue id
              </label>
              <input id="link-issue" placeholder="WS-WEB-4Q1" className="h-8 w-32 rounded-lg border border-line px-2 font-mono text-[12px]" value={linkTo} onChange={(e) => setLinkTo(e.target.value)} />
              <Button size="sm" disabled={!linkTo} onClick={() => patch.mutate({ issue_id: linkTo })}>
                Link
              </Button>
            </span>
          )}
          <Button size="sm" onClick={() => patch.mutate({ status: 'FEATURE_REQUEST' })}>
            Mark feature request
          </Button>
          <Button size="sm" onClick={() => patch.mutate({ status: 'NEED_INFO' })}>
            Need more info
          </Button>
          {!r.issue && (
            <Button size="sm" loading={createIssue.isPending} onClick={() => createIssue.mutate()}>
              Create issue
            </Button>
          )}
          {r.status !== 'CLOSED' && (
            <Button size="sm" onClick={() => patch.mutate({ status: 'CLOSED', close_reason: 'Closed by triage' })}>
              Close
            </Button>
          )}
          {r.suggested_issue && !r.issue && (
            <Button size="sm" variant="primary" onClick={() => patch.mutate({ issue_id: r.suggested_issue!.id })}>
              Link to {r.suggested_issue.id}
            </Button>
          )}
        </Card>
      )}
      {r.updates.length > 0 && (
        <Card className="p-4">
          <h3 className="mb-1 text-[12.5px] font-bold">Updates sent to reporter</h3>
          <ul className="space-y-1 text-[12.5px]">
            {r.updates.map((u, i) => (
              <li key={i}>
                <span className="text-subtle">{timeAgo(u.at)} · {u.by ?? 'Support'}:</span> {u.message}
              </li>
            ))}
          </ul>
        </Card>
      )}
      {triage && (
        <form
          className="card space-y-2 p-4"
          onSubmit={(e) => {
            e.preventDefault();
            sendReply.mutate();
          }}
        >
          <TextArea label="Reply to reporter" rows={3} value={reply} onChange={(e) => setReply(e.target.value)} hint="Goes to the reporter by email and appears in WorkSuite › Help › My bug reports. The reporter’s email is shown only to users with bugtrace.reporter.contact." />
          <Button type="submit" size="sm" variant="primary" disabled={!reply.trim()} loading={sendReply.isPending}>
            Send reply
          </Button>
        </form>
      )}
    </div>
  );
}

export function ReportsInbox() {
  const { ref } = useParams();
  useDocumentTitle('User reports · Bug Trace');
  const [status, setStatus] = useState('UNTRIAGED');
  const f = { status };
  const q = useQuery({ queryKey: bugKeys.reports(f), queryFn: () => bugtraceApi.reports(f) });
  const first = q.data?.data[0]?.reference;
  const current = ref ?? first;
  return (
    <div className="-mx-4 -my-5 grid min-h-[calc(100vh-53px)] sm:-mx-6 lg:grid-cols-[340px_1fr]">
      <section className="border-r border-line bg-white" aria-labelledby="inbox-h">
        <div className="flex items-baseline justify-between px-3 pt-3">
          <h1 id="inbox-h" className="text-[15px] font-bold">
            User Reports
          </h1>
          <span className="text-[11.5px] text-subtle">{q.data?.counts.UNTRIAGED ?? 0} untriaged</span>
        </div>
        <Tabs label="Report status" active={status} onSelect={setStatus} items={INBOX.map((t) => ({ ...t, count: q.data?.counts[t.id] }))} className="px-2" />
        {q.isLoading ? <Spinner /> : q.error ? <ErrorState error={q.error} retry={q.refetch} /> : q.data!.data.length === 0 ? <EmptyState title="Inbox zero" /> : q.data!.data.map((r) => <ReportRow key={r.reference} r={r} active={r.reference === current} />)}
      </section>
      <section className="bg-ground p-4 sm:p-5" aria-label="Report detail">
        {current ? <ReportDetailPane key={current} reference={current} /> : <EmptyState title="Select a report" />}
      </section>
    </div>
  );
}

// ---------------------------------------------------------------- B5 Release health

function HealthChart({ data }: { data: ReleaseHealth['series'] }) {
  const releases = Array.from(new Set(data.map((d) => d.release)));
  const [a, b] = releases;
  const W = 520,
    H = 170,
    PAD = 28;
  const ys = data.map((d) => d.crash_free_users);
  const min = Math.min(98, ...ys),
    max = 100;
  const y = (v: number) => PAD / 2 + (1 - (v - min) / (max - min)) * (H - PAD);
  const series = (rel: string) => data.filter((d) => d.release === rel);
  const line = (rel: string) =>
    series(rel)
      .map((d, i, arr) => `${PAD + (i / Math.max(1, arr.length - 1)) * (W - PAD * 1.5)},${y(d.crash_free_users)}`)
      .join(' ');
  const threshold = 99.0;
  return (
    <figure>
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label={`Crash-free users: ${a} vs ${b} baseline, threshold ${threshold}%`}>
        <line x1={PAD} x2={W - PAD / 2} y1={y(100)} y2={y(100)} stroke="#e3e6ef" />
        <line x1={PAD} x2={W - PAD / 2} y1={y(min)} y2={y(min)} stroke="#e3e6ef" />
        <line x1={PAD} x2={W - PAD / 2} y1={y(threshold)} y2={y(threshold)} stroke="#b42318" strokeDasharray="4 4" />
        <text x={2} y={y(100) + 4} fontSize="10" fill="#6b7390">100%</text>
        <text x={2} y={y(min) + 4} fontSize="10" fill="#6b7390">{min}%</text>
        <text x={W - 120} y={y(threshold) - 4} fontSize="10" fill="#b42318">threshold {threshold}%</text>
        {b && <polyline points={line(b)} fill="none" stroke="#a9b3d6" strokeWidth="2" />}
        {a && <polyline points={line(a)} fill="none" stroke="#23349e" strokeWidth="2.5" />}
      </svg>
      <figcaption className="mt-1 flex gap-4 text-[11.5px] text-subtle">
        <span><span className="mr-1 inline-block h-0.5 w-4 bg-indigo align-middle" />{a}</span>
        {b && <span><span className="mr-1 inline-block h-0.5 w-4 bg-[#a9b3d6] align-middle" />{b} baseline</span>}
      </figcaption>
    </figure>
  );
}

export function ReleaseHealthPage() {
  useDocumentTitle('Release health · Bug Trace');
  const [range, setRange] = useState('7d');
  const q = useQuery({ queryKey: bugKeys.health({ range }), queryFn: () => bugtraceApi.health({}) });
  const GATE: Record<string, { tone: Tone; label: string }> = { OK: { tone: 'green', label: 'Healthy' }, WARN: { tone: 'amber', label: 'Watch' }, BLOCK: { tone: 'red', label: 'Pause suggested' } };
  const rules = q.data?.gate_rules ?? {};
  return (
    <>
      <PageHeader title="Release Health" sub="Crash-free sessions and users per release · feeds rollout auto-pause." actions={<FilterSelect label="Range" value={range} onChange={setRange} options={[{ value: '24h', label: 'Last 24 h' }, { value: '7d', label: 'Last 7 days' }, { value: '30d', label: 'Last 30 days' }]} />} />
      {!q.data ? (
        q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />
      ) : (
        <div className="grid gap-4">
          <Card>
            <Table label="Release health">
              <thead>
                <tr>
                  <Th>Release</Th>
                  <Th>Adoption</Th>
                  <Th className="text-right">Crash-free users</Th>
                  <Th className="text-right">Crash-free sessions</Th>
                  <Th className="text-right">New issues</Th>
                  <Th className="text-right">User reports</Th>
                  <Th className="text-right">Update failures</Th>
                  <Th>Rollout gate</Th>
                </tr>
              </thead>
              <tbody>
                {q.data.releases.map((r) => (
                  <tr key={r.release}>
                    <Td className="font-semibold">{r.release_id ? <Link className="hover:text-brand" to={`/admin/releases/${r.release_id}/rollout`}>{r.release}</Link> : r.release}</Td>
                    <Td>{r.adoption_pct}%</Td>
                    <Td className={cx('text-right font-mono', (r.crash_free_users ?? 100) < 99 ? 'font-bold text-bad' : 'text-ok')}>{formatPct(r.crash_free_users, 2)}</Td>
                    <Td className="text-right font-mono">{formatPct(r.crash_free_sessions, 2)}</Td>
                    <Td className="text-right">
                      {r.new_issues || '—'}
                      {r.regressions ? ` (${r.regressions} regression)` : ''}
                    </Td>
                    <Td className="text-right">{r.reports}</Td>
                    <Td className="text-right">{formatNumber(r.update_failures)}</Td>
                    <Td>
                      <Badge tone={GATE[r.gate].tone}>{GATE[r.gate].label}</Badge>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </Card>
          <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
            <Card>
              <CardHeader title="Crash-free users" sub="new release vs previous baseline" />
              <div className="px-4 pb-4">
                <HealthChart data={q.data.series} />
              </div>
            </Card>
            <Card>
              <CardHeader title="Rollout gate rules" />
              <div className="px-4 pb-4">
                <KeyValue
                  rows={[
                    ['Crash-free users below', <span className="font-mono">{String(rules.crash_free_users_below ?? '—')}%</span>],
                    ['Drop vs previous release', <span className="font-mono">&gt; {String(rules.drop_vs_previous_pt ?? '—')} pt</span>],
                    ['New P0/P1 issue with users', <span className="font-mono">≥ {String(rules.new_p0_p1_with_users ?? '—')}</span>],
                    ['Update install failures', <span className="font-mono">&gt; {String(rules.update_install_failures_pct ?? '—')}%</span>],
                    ['Action', String(rules.action ?? '—')],
                    ['Auto-pause', String(rules.auto_pause ?? '—')],
                  ]}
                />
                <p className="mt-2 text-[11.5px] text-subtle">Nothing is ever rolled back automatically; a paused rollout just stops new offers.</p>
              </div>
            </Card>
          </div>
        </div>
      )}
    </>
  );
}

// ---------------------------------------------------------------- Alerts CRUD

const CONDITIONS = [
  { value: 'new_issue_priority_high', label: 'New P0/P1 issue with users ≥' },
  { value: 'crash_free_users_below', label: 'Crash-free users below (%)' },
  { value: 'issue_regressed', label: 'Issue regressed' },
  { value: 'update_failure_rate_above', label: 'Update install failures above (%)' },
  { value: 'events_spike', label: 'Events spike (× baseline)' },
];

function AlertDialog({ alert, onClose }: { alert: BugAlert | null; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [f, setF] = useState<Partial<BugAlert>>(alert ?? { name: '', condition: CONDITIONS[0].value, threshold: 100, window: '1h', channels: ['email'], enabled: true });
  const m = useMutation({
    mutationFn: () => (alert ? bugtraceApi.updateAlert(alert.id, f) : bugtraceApi.createAlert(f)),
    onSuccess: () => (toast.success('Alert saved'), void qc.invalidateQueries({ queryKey: bugKeys.alerts }), onClose()),
    onError: (e) => toast.error(errorMessage(e)),
  });
  const ch = f.channels ?? [];
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={alert ? 'Edit alert' : 'New alert'}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={m.isPending} disabled={!f.name} onClick={() => m.mutate()}>
            Save alert
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        <TextField label="Name" value={f.name ?? ''} onChange={(e) => setF({ ...f, name: e.target.value })} />
        <Select label="Condition" value={f.condition} onChange={(e) => setF({ ...f, condition: e.target.value })} options={CONDITIONS} />
        <div className="grid grid-cols-2 gap-3">
          <TextField label="Threshold" type="number" value={f.threshold ?? 0} onChange={(e) => setF({ ...f, threshold: Number(e.target.value) })} />
          <Select label="Window" value={f.window} onChange={(e) => setF({ ...f, window: e.target.value })} options={['event', '1h', '6h', '24h'].map((w) => ({ value: w, label: w }))} />
        </div>
        <fieldset>
          <legend className="mb-1 text-[12px] font-semibold">Notify via</legend>
          <div className="flex gap-4">
            {['email', 'chat', 'webhook'].map((c) => (
              <Checkbox key={c} label={titleCase(c)} checked={ch.includes(c)} onChange={(e) => setF({ ...f, channels: e.target.checked ? [...ch, c] : ch.filter((x) => x !== c) })} />
            ))}
          </div>
        </fieldset>
        <Checkbox label="Enabled" checked={!!f.enabled} onChange={(e) => setF({ ...f, enabled: e.target.checked })} />
      </div>
    </Modal>
  );
}

export function AlertsPage() {
  useDocumentTitle('Alerts · Bug Trace');
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({ queryKey: bugKeys.alerts, queryFn: bugtraceApi.alerts });
  const [edit, setEdit] = useState<BugAlert | null | 'new'>(null);
  const toggle = useMutation({ mutationFn: (a: BugAlert) => bugtraceApi.updateAlert(a.id, { enabled: !a.enabled }), onSuccess: () => void qc.invalidateQueries({ queryKey: bugKeys.alerts }), onError: (e) => toast.error(errorMessage(e)) });
  const del = useMutation({ mutationFn: (a: BugAlert) => bugtraceApi.deleteAlert(a.id), onSuccess: () => (toast.success('Alert deleted'), void qc.invalidateQueries({ queryKey: bugKeys.alerts })), onError: (e) => toast.error(errorMessage(e)) });
  return (
    <>
      <PageHeader title="Alerts" sub="Regressions, crash-free drops, P0/P1 issues and update failures." actions={<Button variant="primary" size="sm" icon={<Plus className="size-3.5" aria-hidden />} onClick={() => setEdit('new')}>New alert</Button>} />
      <Card>
        {!q.data ? (
          q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />
        ) : q.data.data.length === 0 ? (
          <EmptyState title="No alerts yet" />
        ) : (
          <Table label="Alerts">
            <thead>
              <tr>
                <Th>Alert</Th>
                <Th>Condition</Th>
                <Th>Window</Th>
                <Th>Channels</Th>
                <Th>Enabled</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((a) => (
                <tr key={a.id}>
                  <Td className="font-semibold">{a.name}</Td>
                  <Td className="text-[12px]">
                    {CONDITIONS.find((c) => c.value === a.condition)?.label ?? a.condition} <span className="font-mono">{a.threshold}</span>
                  </Td>
                  <Td>{a.window}</Td>
                  <Td>{a.channels.join(', ')}</Td>
                  <Td>
                    <input type="checkbox" className="size-4 accent-brand" aria-label={`Enable ${a.name}`} checked={a.enabled} onChange={() => toggle.mutate(a)} />
                  </Td>
                  <Td className="text-right whitespace-nowrap">
                    <Button size="sm" variant="ghost" onClick={() => setEdit(a)}>
                      Edit
                    </Button>
                    <Button size="sm" variant="ghost" aria-label={`Delete ${a.name}`} onClick={() => window.confirm(`Delete alert “${a.name}”?`) && del.mutate(a)}>
                      <Trash2 className="size-3.5 text-bad" aria-hidden />
                    </Button>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {edit && <AlertDialog alert={edit === 'new' ? null : edit} onClose={() => setEdit(null)} />}
      <Alert className="mt-3">Alerts notify the Release Manager; rollout auto-pause is opt-in per release (Release › Settings).</Alert>
    </>
  );
}
