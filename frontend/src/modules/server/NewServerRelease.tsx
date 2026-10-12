import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Upload } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { cx, formatBytes } from '@/core/format';
import { isSemver } from '@/core/semver';
import type { AdminPackage, AdminReleaseDetail, CreateServerReleaseBody, Edition, ServerChannel, ServerSeverity } from '@/types/api';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, PageHeader, ProgressBar, Select, TextArea, TextField, type Tone } from '@/components/ui';
import { ReleaseStatusBadge } from '@/components/StatusBadges';
import { ReleaseChecklist } from '@/components/ReleaseChecklist';
import { TypeToConfirm, isConfirmed } from '@/components/TypeToConfirm';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { UploadDialog, type UploadSlot } from '@/modules/releases/UploadDialog';
import { useRelease } from '@/modules/releases/hooks';
import { QueryState, SEVERITIES, editionLabel } from './serverUi';

const EDITIONS: Edition[] = ['FREE', 'BUSINESS', 'ENTERPRISE'];

interface FormState {
  version: string;
  build: string;
  commit: string;
  channel: ServerChannel;
  severity: ServerSeverity;
  min_from_version: string;
  migrations_reversible: boolean;
  editions: Edition[];
  core_patch: boolean;
  desktop_compat: string;
  admin_notice: string;
  notes: string;
  known_issues: string;
  upgrade_notes: string;
}

const EMPTY: FormState = {
  version: '',
  build: '',
  commit: '',
  channel: 'STABLE',
  severity: 'MINOR',
  min_from_version: '',
  migrations_reversible: false,
  editions: ['FREE', 'BUSINESS', 'ENTERPRISE'],
  core_patch: false,
  desktop_compat: '',
  admin_notice: '',
  notes: '',
  known_issues: '',
  upgrade_notes: '',
};

function fromRelease(r: AdminReleaseDetail): FormState {
  const m = r.server_meta;
  const editions = (m?.editions ?? []) as Edition[];
  return {
    version: r.version,
    build: String(r.build),
    commit: m?.commit ?? '',
    channel: (['STABLE', 'BETA', 'LTS'].includes(r.channel) ? r.channel : 'STABLE') as ServerChannel,
    severity: m?.severity ?? 'MINOR',
    min_from_version: m?.min_from_version ?? '',
    migrations_reversible: m?.migrations_reversible === true,
    editions: editions.filter((e) => e !== 'ALL'),
    core_patch: editions.includes('ALL'),
    desktop_compat: m?.desktop_compat ?? '',
    admin_notice: m?.admin_notice ?? '',
    notes: r.changelog?.markdown ?? r.notes ?? '',
    known_issues: r.known_issues ?? '',
    upgrade_notes: m?.upgrade_notes ?? '',
  };
}

export type PackageState = 'Valid' | 'Validating' | 'Uploading' | 'Rejected' | 'Missing';

/** Per-edition package state shown on DL-2 (from the release's packages and their processing pipeline). */
export function packageState(pkg: AdminPackage | undefined): { state: PackageState; progress: number; file: string; detail: string } {
  if (!pkg || pkg.status === 'EMPTY' || pkg.revisions.length === 0) return { state: 'Missing', progress: 0, file: 'Waiting for the CI upload', detail: 'Not uploaded yet' };
  const latest = pkg.processing_revision ?? pkg.revisions[0];
  if (latest && (latest.status === 'PROCESSING' || latest.status === 'UPLOADING')) {
    const steps = latest.pipeline ?? [];
    const done = steps.filter((s) => s.status === 'done' || s.status === 'skipped').length;
    const running = steps.find((s) => s.status === 'running');
    const progress = steps.length ? Math.round((done / steps.length) * 100) : 5;
    return { state: latest.status === 'UPLOADING' ? 'Uploading' : 'Validating', progress, file: latest.file_name, detail: running?.detail ?? (running ? `Running ${running.step}` : 'Queued for validation') };
  }
  if (pkg.status === 'READY' && pkg.active_revision) {
    const r = pkg.active_revision;
    const att = String(r.attestation_match) === 'MATCH' || r.attestation_match === true;
    const scan = r.scan_status === 'CLEAN' ? 'scan clean' : r.scan_status === 'SKIPPED' ? 'scan skipped' : `scan ${String(r.scan_status ?? 'pending').toLowerCase()}`;
    return { state: 'Valid', progress: 100, file: r.file_name, detail: `${formatBytes(r.size)} · attestation ${att ? 'ok' : 'pending'} · ${scan}` };
  }
  const rejected = pkg.revisions.find((rv) => rv.status === 'REJECTED');
  return { state: 'Rejected', progress: 100, file: rejected?.file_name ?? '—', detail: rejected?.reject_code ? `Rejected: ${rejected.reject_code}` : 'Rejected by validation' };
}

const STATE_TONE: Record<PackageState, Tone> = { Valid: 'green', Validating: 'blue', Uploading: 'blue', Rejected: 'red', Missing: 'amber' };
const BAR: Record<PackageState, string> = { Valid: 'bg-ok', Validating: 'bg-brand', Uploading: 'bg-brand', Rejected: 'bg-bad', Missing: 'bg-line' };

/** DL-2 New server release: version form, edition packages, notes and the checklist; QA → Release Manager publish. */
export default function NewServerRelease() {
  const { id } = useParams();
  return id ? <ExistingRelease id={id} /> : <ReleaseForm release={null} />;
}

function ExistingRelease({ id }: { id: string }) {
  const q = useRelease(id);
  if (!q.data) return <QueryState q={q} />;
  return <ReleaseForm key={q.data.id} release={q.data} />;
}

function validate(f: FormState): Record<string, string> {
  const e: Record<string, string> = {};
  if (!isSemver(f.version)) e.version = 'Use SemVer, e.g. 3.1.0';
  if (!/^\d+$/.test(f.build) || Number(f.build) <= 0) e.build = 'Build is a positive number (commit count)';
  if (f.commit && !/^[0-9a-f]{7,40}$/i.test(f.commit)) e.commit = '7–40 hex characters';
  if (f.min_from_version && !isSemver(f.min_from_version)) e.min_from_version = 'Use SemVer, e.g. 3.0.0';
  if (!f.core_patch && f.editions.length === 0) e.editions = 'Choose at least one edition';
  return e;
}

function ReleaseForm({ release }: { release: AdminReleaseDetail | null }) {
  const { user } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [f, setF] = useState<FormState>(() => (release ? fromRelease(release) : EMPTY));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [uploadSlot, setUploadSlot] = useState<UploadSlot | null>(null);
  const [publishing, setPublishing] = useState(false);
  const app = useQuery({ queryKey: adminKeys.app('server'), queryFn: () => adminApi.app('server'), enabled: !!release });
  useDocumentTitle(release ? `Server ${release.version} · Update Center` : 'New server release · Update Center');

  const editable = !release || ['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING', 'APPROVED'].includes(release.status);
  const locked = !!release; // version, build and channel are fixed once created
  const set = <K extends keyof FormState>(k: K, v: FormState[K]) => setF((x) => ({ ...x, [k]: v }));
  const editions: Edition[] = f.core_patch ? ['ALL'] : f.editions;
  const allowed = release?.allowed_actions ?? [];

  const refresh = () => {
    if (release) void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
    void qc.invalidateQueries({ queryKey: ['admin', 'server-releases'] });
  };

  const save = async (e?: FormEvent) => {
    e?.preventDefault();
    const errs = validate(f);
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy('save');
    try {
      if (!release) {
        const body: CreateServerReleaseBody = {
          version: f.version.trim(),
          build: Number(f.build),
          commit: f.commit.trim() || undefined,
          channel: f.channel,
          severity: f.severity,
          min_from_version: f.min_from_version.trim() || undefined,
          notes: f.notes,
          known_issues: f.known_issues,
          upgrade_notes: f.upgrade_notes,
          migrations_reversible: f.migrations_reversible,
          editions,
          desktop_compat: f.desktop_compat.trim() || undefined,
          admin_notice: f.admin_notice.trim() || undefined,
        };
        const created = await adminApi.createServerRelease(body);
        toast.success(`Draft WorkSuite Server ${created.version} created`);
        refresh();
        navigate(`/admin/server/releases/${created.id}`, { replace: true });
        return;
      }
      await adminApi.updateServerRelease(release.id, { severity: f.severity, commit: f.commit.trim(), upgrade_notes: f.upgrade_notes, migrations_reversible: f.migrations_reversible, editions });
      await adminApi.updateRelease(release.id, { known_issues: f.known_issues || null, notes: f.notes || null });
      await adminApi.serverMeta(release.id, { min_from_version: f.min_from_version.trim() || null, desktop_compat: f.desktop_compat.trim() || null, admin_notice: f.admin_notice.trim() || null });
      if (f.notes.trim()) await adminApi.saveChangelog(release.id, { summary: release.changelog?.summary || `WorkSuite Server ${release.version}`, highlights: release.changelog?.highlights ?? [], markdown: f.notes });
      toast.success('Draft saved');
      refresh();
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fieldErrors).length) setErrors(err.fieldErrors);
      toast.error(errorMessage(err));
    } finally {
      setBusy(null);
    }
  };

  const act = async (key: string, fn: () => Promise<unknown>, ok: string) => {
    setBusy(key);
    try {
      await fn();
      toast.success(ok);
      refresh();
    } catch (err) {
      if ((err as { code?: string }).code !== 'MFA_CANCELLED') toast.error(errorMessage(err));
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <PageHeader
        breadcrumb={
          <>
            <Link to="/admin/server/releases" className="link">
              Server Releases
            </Link>{' '}
            › {release ? release.version : 'New'}
          </>
        }
        title={
          <>
            WorkSuite Server <span className="font-mono">{f.version || '—'}</span>
          </>
        }
        badges={<ReleaseStatusBadge status={release?.status ?? 'DRAFT'} />}
        actions={
          <>
            {editable && can(user, release ? 'release.edit' : 'release.create') && (
              <Button size="sm" onClick={() => void save()} loading={busy === 'save'}>
                Save draft
              </Button>
            )}
            {release && allowed.includes('submit_test') && (
              <Button size="sm" variant="primary" loading={busy === 'submit'} onClick={() => void act('submit', () => adminApi.submitTest(release.id), 'Sent to QA')}>
                Send to QA
              </Button>
            )}
            {release && allowed.includes('qa') && (
              <Button size="sm" variant="primary" loading={busy === 'qa'} onClick={() => void act('qa', () => adminApi.qa(release.id, { decision: 'PASS', comment: 'QA approved in the server console' }), 'QA approved')}>
                QA approve
              </Button>
            )}
            {release && (release.status === 'APPROVED' || release.status === 'TESTING') && can(user, 'release.publish') && (
              <Button size="sm" variant="brand" onClick={() => setPublishing(true)}>
                Publish…
              </Button>
            )}
            {release && ['PUBLISHED', 'PAUSED', 'WITHDRAWN', 'SUPERSEDED'].includes(release.status) && (
              <Link className="link text-[12.5px]" to={`/admin/server/releases/${release.id}/rollout`}>
                Open rollout
              </Link>
            )}
          </>
        }
      />
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="Version" />
            <form className="grid gap-3 px-4 pb-4 sm:grid-cols-2 lg:grid-cols-3" onSubmit={(e) => void save(e)} noValidate>
              <TextField label="Version" required mono value={f.version} disabled={locked} error={errors.version} onChange={(e) => set('version', e.target.value)} placeholder="3.1.0" />
              <TextField label="Build" required mono inputMode="numeric" value={f.build} disabled={locked} error={errors.build} onChange={(e) => set('build', e.target.value)} placeholder="1574" hint="Commit count; ordering uses SemVer" />
              <TextField label="Commit" mono value={f.commit} disabled={!editable} error={errors.commit} onChange={(e) => set('commit', e.target.value)} placeholder="07ebd36c" />
              <Select
                label="Channel"
                value={f.channel}
                disabled={locked}
                onChange={(e) => set('channel', e.target.value as ServerChannel)}
                options={[
                  { value: 'STABLE', label: 'Stable' },
                  { value: 'BETA', label: 'Beta' },
                  { value: 'LTS', label: 'LTS (Enterprise)' },
                ]}
              />
              <fieldset className="sm:col-span-2" disabled={!editable}>
                <legend className="mb-1 text-[12px] font-semibold text-ink">Severity</legend>
                <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Severity">
                  {SEVERITIES.map((s) => (
                    <label
                      key={s}
                      className={cx(
                        'cursor-pointer rounded-lg border px-3 py-1.5 text-[12.5px] font-semibold focus-within:ring-2 focus-within:ring-brand/25',
                        f.severity === s ? (s === 'SECURITY' || s === 'CRITICAL' ? 'border-bad bg-bad-bg text-bad' : 'border-brand bg-brand-50 text-indigo') : 'border-line bg-white text-ink',
                      )}
                    >
                      <input type="radio" name="severity" value={s} className="sr-only" checked={f.severity === s} onChange={() => set('severity', s)} />
                      {s.charAt(0) + s.slice(1).toLowerCase()}
                    </label>
                  ))}
                </div>
                {(f.severity === 'SECURITY' || f.severity === 'CRITICAL') && <p className="mt-1 text-[11.5px] text-subtle">Rings compress to 0 → 2, admins must acknowledge the advisory, and a reminder goes out after 7 days.</p>}
              </fieldset>
              <TextField label="Update from at least" mono value={f.min_from_version} disabled={!editable} error={errors.min_from_version} onChange={(e) => set('min_from_version', e.target.value)} placeholder="3.0.0" hint="Older installations step through the path" />
              <TextField label="Desktop compatibility" mono value={f.desktop_compat} disabled={!editable} onChange={(e) => set('desktop_compat', e.target.value)} placeholder="2.6.0 - 2.8.x" />
              <TextField className="sm:col-span-2" label="Admin notice" value={f.admin_notice} disabled={!editable} onChange={(e) => set('admin_notice', e.target.value)} placeholder="Brief downtime (about 2 minutes)." />
              <Checkbox className="sm:col-span-2 lg:col-span-3" label="Every migration is reversible (leave off if any is one-way)" checked={f.migrations_reversible} disabled={!editable} onChange={(e) => set('migrations_reversible', e.target.checked)} />
              <fieldset className="sm:col-span-2 lg:col-span-3" disabled={!editable}>
                <legend className="mb-1 text-[12px] font-semibold text-ink">Editions</legend>
                <div className="flex flex-wrap gap-4">
                  {EDITIONS.map((ed) => (
                    <Checkbox key={ed} label={editionLabel(ed)} checked={!f.core_patch && f.editions.includes(ed)} disabled={f.core_patch} onChange={() => set('editions', f.editions.includes(ed) ? f.editions.filter((x) => x !== ed) : [...f.editions, ed])} />
                  ))}
                  <Checkbox label="All editions (core patch)" checked={f.core_patch} onChange={(e) => set('core_patch', e.target.checked)} />
                </div>
                {errors.editions && (
                  <p role="alert" className="mt-1 text-[11.5px] font-medium text-bad">
                    {errors.editions}
                  </p>
                )}
              </fieldset>
              <button type="submit" className="sr-only">
                Save draft
              </button>
            </form>
          </Card>

          <Card>
            <CardHeader title="Edition packages" sub="One ZIP per edition from CI. Packages are inspected, never run." />
            {!release ? (
              <p className="px-4 pb-4 text-[12.5px] text-subtle">Save the draft first; then upload each edition ZIP here or let CI upload it.</p>
            ) : (
              <ul className="divide-y divide-line px-4 pb-2" aria-label="Edition packages">
                {editions.map((ed) => {
                  const pkg = release.packages.find((p) => p.edition === ed);
                  const s = packageState(pkg);
                  return (
                    <li key={ed} className="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)_auto] sm:items-center" data-state={s.state}>
                      <div className="text-[13px] font-semibold">{editionLabel(ed)}</div>
                      <div className="min-w-0">
                        <div className="truncate font-mono text-[12px]">{s.file}</div>
                        <ProgressBar value={s.progress} tone={BAR[s.state]} label={`${editionLabel(ed)} package ${s.state.toLowerCase()}`} className="my-1" />
                        <div className="text-[11.5px] text-subtle">{s.detail}</div>
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge tone={STATE_TONE[s.state]}>{s.state}</Badge>
                        {allowed.includes('upload') && (
                          <Button size="sm" icon={<Upload className="size-3.5" aria-hidden />} onClick={() => setUploadSlot({ platform: 'server', architecture: 'any', package_type: 'zip', edition: ed })} aria-label={`Upload ${editionLabel(ed)} package`}>
                            Upload
                          </Button>
                        )}
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </Card>

          <Card>
            <CardHeader title="Release notes" sub="Markdown, by app (## headings become sections)" />
            <div className="grid gap-3 px-4 pb-4">
              <TextArea label="Release notes" mono rows={10} value={f.notes} disabled={!editable} onChange={(e) => set('notes', e.target.value)} placeholder={'## Rental Management (new app)\n- The rental engine, catalog, quotes, reservations, checkout and return'} />
              <div className="grid gap-3 lg:grid-cols-2">
                <TextArea label="Known issues" rows={4} value={f.known_issues} disabled={!editable} onChange={(e) => set('known_issues', e.target.value)} />
                <TextArea label="Upgrade notes" rows={4} value={f.upgrade_notes} disabled={!editable} onChange={(e) => set('upgrade_notes', e.target.value)} />
              </div>
            </div>
          </Card>
        </div>

        <aside className="space-y-4" aria-label="Release checklist and approvals">
          <Card>
            <CardHeader title="Release checklist" />
            <div className="px-4 pb-4">
              {release ? <ReleaseChecklist checklist={release.checklist} /> : <p className="text-[12.5px] text-subtle">The checklist appears once the draft is saved.</p>}
              <p className="mt-3 text-[11.5px] text-subtle">QA approves, then a Release Manager publishes with MFA. The same person cannot do both.</p>
            </div>
          </Card>
          {release && release.approvals.length > 0 && (
            <Card>
              <CardHeader title="Approvals" />
              <ul className="space-y-1 px-4 pb-4 text-[12.5px]">
                {release.approvals.map((a, i) => (
                  <li key={i}>
                    <strong>{a.kind === 'QA' ? 'QA' : 'Release Manager'}</strong> {a.decision.toLowerCase()} · {a.user}
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </aside>
      </div>
      {release && uploadSlot && <UploadDialog open onClose={() => setUploadSlot(null)} release={release} app={app.data} initial={uploadSlot} />}
      {release && <PublishServerDialog release={release} open={publishing} onClose={() => setPublishing(false)} onDone={() => navigate(`/admin/server/releases/${release.id}/rollout`)} />}
    </>
  );
}

/** Publish: type-to-confirm + step-up MFA (prompted by the API client) — FR-DL-REL-04. */
export function PublishServerDialog({ release, open, onClose, onDone }: { release: AdminReleaseDetail; open: boolean; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [confirm, setConfirm] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!open) {
      setConfirm('');
      setError(null);
    }
  }, [open]);
  const blockers = release.checklist.items.filter((i) => i.blocking && i.status !== 'pass' && i.status !== 'skipped' && i.key !== 'approvals');
  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await adminApi.publishServerRelease(release.id, { confirm_version: confirm.trim(), reason: reason.trim() || undefined });
      toast.success(`WorkSuite Server ${release.version} published — ring 0 is open`);
      void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
      void qc.invalidateQueries({ queryKey: ['admin', 'server-releases'] });
      onClose();
      onDone();
    } catch (e) {
      if ((e as { code?: string }).code === 'MFA_CANCELLED') return;
      setError(e instanceof ApiError && e.code === 'SOD_VIOLATION' ? 'You gave the QA approval for this release, so another Release Manager has to publish it.' : errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      dismissible={!busy}
      size="sm"
      title={`Publish WorkSuite Server ${release.version}?`}
      footer={
        <>
          <span className="text-[11.5px] text-subtle">Step-up MFA required</span>
          <div className="flex gap-2">
            <Button onClick={onClose} disabled={busy}>
              Cancel
            </Button>
            <Button variant="primary" loading={busy} disabled={!isConfirmed(release.version, confirm) || blockers.length > 0} onClick={() => void submit()}>
              Publish release
            </Button>
          </div>
        </>
      }
    >
      <div className="space-y-3 text-[12.5px]">
        <p>The manifest is signed, edition packages are copied with SHA256SUMS and its signature, ring 0 (Livezen-hosted and internal) opens and the notification campaign is sent.</p>
        {blockers.length > 0 && (
          <Alert tone="bad" title="Checklist blocks publish:">
            <ul className="mt-1 list-disc pl-5">
              {blockers.map((b) => (
                <li key={b.key}>{b.label}</li>
              ))}
            </ul>
          </Alert>
        )}
        <TextField label="Reason (optional, audited)" value={reason} onChange={(e) => setReason(e.target.value)} />
        <TypeToConfirm expected={release.version} value={confirm} onChange={setConfirm} />
        {error && <Alert tone="bad">{error}</Alert>}
      </div>
    </Modal>
  );
}
