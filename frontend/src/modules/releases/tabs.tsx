import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { adminApi, adminKeys } from '@/services/admin';
import type { AdminApp, AdminReleaseDetail, ReleaseType, Rollout, SupportState } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import { formatDate, formatDateTime, formatNumber, formatPct, PLATFORM_LABEL, titleCase } from '@/core/format';
import { isSemver } from '@/core/semver';
import { Alert, Badge, BarList, Button, Card, CardHeader, Checkbox, ColumnChart, ErrorState, KeyValue, Kpi, ProgressBar, Select, Spinner, Table, Td, TextArea, TextField, Th } from '@/components/ui';
import { ReleaseChecklist } from '@/components/ReleaseChecklist';
import { AuditTimeline } from '@/components/admin/AuditTimeline';
import { ReasonDialog } from './dialogs';
import { useReleaseMutation } from './hooks';
import { approvalsLabel } from './PublishModal';

// ---------------------------------------------------------------- Overview

export function OverviewTab({ release }: { release: AdminReleaseDetail }) {
  const r = release;
  return (
    <div className="grid items-start gap-4 lg:grid-cols-[1.3fr_1fr]">
      <Card>
        <CardHeader title="Release" />
        <div className="px-4 pb-4">
          <KeyValue
            rows={[
              ['Status', titleCase(r.status)],
              ['Channel', titleCase(r.channel)],
              ['Type', titleCase(r.release_type)],
              ['Version / build', <span className="font-mono">{r.version} · {r.build}{r.build_revision ? ` (rev ${r.build_revision})` : ''}</span>],
              ['Minimum supported', <span className="font-mono">{r.minimum_supported_version ?? (r.minimum_no_change ? 'no change' : '—')}</span>],
              ['Mandatory', r.mandatory ? `Yes · after ${formatDateTime(r.mandatory_after)}` : 'No'],
              ...(r.release_type === 'CRITICAL' ? ([['Critical grace', `${r.grace_minutes ?? 0} min`]] as [string, string][]) : []),
              ['Support state', titleCase(r.support_state)],
              ['Visibility', titleCase(r.visibility)],
              ['Release date', formatDate(r.release_date)],
              ['Scheduled publish', formatDateTime(r.scheduled_publish_at)],
              ['Published', r.published_at ? `${formatDateTime(r.published_at)} by ${r.published_by ?? '—'}` : '—'],
              ['Created by', r.created_by ?? '—'],
              ['Packages', `${r.packages_ready}/${r.packages_total} ready`],
              ...(r.superseded_by ? ([['Superseded by', <span className="font-mono">{r.superseded_by.version}</span>]] as [string, ReactNode][]) : []),
              ...(r.withdraw_reason ? ([['Withdrawn', `${formatDateTime(r.withdrawn_at)} — ${r.withdraw_reason}`]] as [string, string][]) : []),
            ]}
          />
          {r.last_error && <Alert tone="warn" className="mt-3">{r.last_error}</Alert>}
          {r.notes && <p className="mt-3 text-[12.5px] text-muted"><strong className="text-ink">Notes: </strong>{r.notes}</p>}
          {r.known_issues && <p className="mt-1 text-[12.5px] text-muted"><strong className="text-ink">Known issues: </strong>{r.known_issues}</p>}
        </div>
      </Card>
      <div className="grid gap-4">
        <Card>
          <CardHeader title="Approvals" sub={approvalsLabel(r.approvals)} />
          <ul className="divide-y divide-line px-4 pb-3 text-[12.5px]">
            {r.approvals.length === 0 && <li className="py-2 text-subtle">No approvals yet. QA approval + Release Manager approval are required on Stable.</li>}
            {r.approvals.map((a, i) => (
              <li key={i} className="flex flex-wrap items-center gap-2 py-2">
                <Badge tone={a.decision === 'FAIL' ? 'red' : 'green'}>{a.kind === 'QA' ? `QA ${a.decision}` : 'RM approved'}</Badge>
                <span className="font-semibold">{a.user}</span>
                {a.platform && <span className="text-subtle">{PLATFORM_LABEL[a.platform]}</span>}
                <span className="ml-auto text-[11.5px] text-subtle">{formatDateTime(a.created_at)}</span>
                {a.comment && <span className="w-full text-muted">“{a.comment}”</span>}
              </li>
            ))}
          </ul>
        </Card>
        <Card>
          <CardHeader title="Release checklist" />
          <div className="px-4 pb-4">
            <ReleaseChecklist checklist={r.checklist} />
          </div>
        </Card>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- Rollout

function RolloutConfig({ release, rollout }: { release: AdminReleaseDetail; rollout: Rollout | null }) {
  const [mode, setMode] = useState<'FULL' | 'STAGED'>(rollout?.mode ?? 'STAGED');
  const [stages, setStages] = useState((rollout?.stages ?? [5, 25, 50, 100]).join(', '));
  const [autoAdvance, setAutoAdvance] = useState(rollout?.auto_advance ?? true);
  const [autoPause, setAutoPause] = useState(rollout?.auto_pause ?? true);
  const [t, setT] = useState(rollout?.thresholds ?? { dwell_hours: 24, min_success_rate: 97, max_failure_rate: 3, max_rollback_rate: 1 });
  const [rules, setRules] = useState((rollout?.rules ?? []).map((r) => `${r.kind}=${r.value}`).join('\n'));
  const parsed = stages.split(/[,\s→>]+/).map(Number).filter((n) => n > 0 && n <= 100);
  const valid = mode === 'FULL' || (parsed.length > 0 && parsed[parsed.length - 1] === 100 && parsed.every((n, i) => i === 0 || n > parsed[i - 1]));
  const save = useReleaseMutation(release.id, () =>
    adminApi.saveRollout(release.id, {
      mode,
      stages: mode === 'FULL' ? [100] : parsed,
      auto_advance: autoAdvance,
      auto_pause: autoPause,
      thresholds: t,
      rules: rules
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean)
        .map((l) => {
          const [kind, ...v] = l.split('=');
          return { kind: kind.trim(), value: v.join('=').trim() };
        }),
    }),
    'Rollout plan saved',
  );
  return (
    <form
      className="grid gap-3 px-4 pb-4 sm:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate(undefined);
      }}
    >
      <Select label="Mode" value={mode} onChange={(e) => setMode(e.target.value as 'FULL' | 'STAGED')} options={[{ value: 'STAGED', label: 'Staged' }, { value: 'FULL', label: 'Full (100 %)' }]} />
      {mode === 'STAGED' && <TextField label="Stages (%)" mono value={stages} onChange={(e) => setStages(e.target.value)} hint="Increasing, ending at 100 — e.g. 5, 25, 50, 100" error={valid ? null : 'Stages must increase and end at 100'} />}
      <TextField label="Dwell time (h)" type="number" min={0} value={t.dwell_hours} onChange={(e) => setT({ ...t, dwell_hours: Number(e.target.value) })} />
      <TextField label="Min success rate (%)" type="number" min={0} max={100} step={0.1} value={t.min_success_rate} onChange={(e) => setT({ ...t, min_success_rate: Number(e.target.value) })} />
      <TextField label="Max failure rate (%)" type="number" min={0} max={100} step={0.1} value={t.max_failure_rate} onChange={(e) => setT({ ...t, max_failure_rate: Number(e.target.value) })} />
      <TextField label="Max rollback rate (%)" type="number" min={0} max={100} step={0.1} value={t.max_rollback_rate} onChange={(e) => setT({ ...t, max_rollback_rate: Number(e.target.value) })} />
      <Checkbox label="Auto-advance after dwell time when success ≥ threshold" checked={autoAdvance} onChange={(e) => setAutoAdvance(e.target.checked)} />
      <Checkbox label="Auto-pause on failure or rollback spikes" checked={autoPause} onChange={(e) => setAutoPause(e.target.checked)} />
      <TextArea label="Cohort rules (optional)" mono rows={3} className="sm:col-span-2" value={rules} onChange={(e) => setRules(e.target.value)} hint="One per line: platform=windows · architecture=arm64 · version_range=>=2.6.0 · tenant=acme.lk" />
      <div className="sm:col-span-2">
        <Button type="submit" variant="primary" size="sm" loading={save.isPending} disabled={!valid}>
          Save rollout plan
        </Button>
      </div>
    </form>
  );
}

export function RolloutTab({ release }: { release: AdminReleaseDetail }) {
  const { user } = useAuth();
  const manage = can(user, 'rollout.manage');
  const ro = release.rollout;
  const [dialog, setDialog] = useState<null | 'pause' | 'complete'>(null);
  const [nextPct, setNextPct] = useState<number | null>(null);
  const act = useReleaseMutation(release.id, (v: { action: 'pause' | 'resume' | 'increase' | 'complete'; percentage?: number; reason?: string }) => adminApi.rolloutAction(release.id, v.action, { percentage: v.percentage, reason: v.reason }), (r) => `Rollout ${r.status.toLowerCase()} · ${r.percentage}%`);
  const next = ro ? (ro.stages.find((s) => s > ro.percentage) ?? 100) : 100;
  const live = ['PUBLISHED', 'PAUSED'].includes(release.status);
  return (
    <div className="grid gap-4">
      {ro && (
        <Card>
          <CardHeader
            title="Rollout"
            sub={`${ro.mode === 'FULL' ? 'Full' : `Staged ${ro.stages.join(' → ')}`} · cohort = SHA-256(release_id ∥ device_ref) mod 10000`}
            actions={
              manage &&
              live && (
                <>
                  {ro.status === 'ACTIVE' && (
                    <Button size="sm" onClick={() => setDialog('pause')}>
                      Pause
                    </Button>
                  )}
                  {ro.status === 'PAUSED' && (
                    <Button size="sm" onClick={() => act.mutate({ action: 'resume' })} loading={act.isPending}>
                      Resume
                    </Button>
                  )}
                  {ro.status !== 'DONE' && ro.percentage < 100 && (
                    <>
                      <label htmlFor="ro-next" className="sr-only">
                        Increase to
                      </label>
                      <select id="ro-next" className="h-8 rounded-lg border border-line bg-white px-2 text-[12px]" value={nextPct ?? next} onChange={(e) => setNextPct(Number(e.target.value))}>
                        {Array.from(new Set([...ro.stages.filter((s) => s > ro.percentage), 100])).map((s) => (
                          <option key={s} value={s}>
                            {s}%
                          </option>
                        ))}
                      </select>
                      <Button size="sm" variant="primary" loading={act.isPending} onClick={() => act.mutate({ action: 'increase', percentage: nextPct ?? next })}>
                        Increase
                      </Button>
                    </>
                  )}
                  {ro.status !== 'DONE' && (
                    <Button size="sm" onClick={() => setDialog('complete')}>
                      Complete (100 %)
                    </Button>
                  )}
                </>
              )
            }
          />
          <div className="px-4 pb-4">
            <div className="mb-3 flex flex-wrap items-center gap-3 text-[13px]">
              <Badge tone={ro.status === 'ACTIVE' ? 'green' : ro.status === 'PAUSED' ? 'amber' : ro.status === 'DONE' ? 'grey' : 'blue'}>{ro.status}</Badge>
              <span>
                Stage <strong>{ro.stage_no}</strong> of {ro.stages.length} · <strong>{ro.percentage}%</strong> of eligible devices
              </span>
              {ro.paused_at && <span className="text-warn">paused {formatDateTime(ro.paused_at)}</span>}
              {release.channel === 'STABLE' && <span className="text-[11.5px] text-subtle">Changes on Stable require MFA.</span>}
            </div>
            <ProgressBar value={ro.percentage} tone={ro.status === 'PAUSED' ? 'bg-[#d98a1c]' : 'bg-ok'} label="Rollout percentage" className="mb-4 h-2" />
            <Table label="Rollout progress per stage">
              <thead>
                <tr>
                  <Th>Stage</Th>
                  <Th className="text-right">Target</Th>
                  <Th className="text-right">Eligible</Th>
                  <Th className="text-right">Updated</Th>
                  <Th className="text-right">Failed</Th>
                  <Th className="text-right">Rolled back</Th>
                  <Th className="text-right">Success</Th>
                  <Th>Progress</Th>
                </tr>
              </thead>
              <tbody>
                {ro.progress.map((p) => (
                  <tr key={p.stage} className={p.stage === ro.stage_no ? 'bg-brand-50/50' : ''}>
                    <Td>{p.stage}</Td>
                    <Td className="text-right font-mono">{p.percentage}%</Td>
                    <Td className="text-right font-mono">{formatNumber(p.eligible)}</Td>
                    <Td className="text-right font-mono">{formatNumber(p.updated)}</Td>
                    <Td className="text-right font-mono">{formatNumber(p.failed)}</Td>
                    <Td className="text-right font-mono">{formatNumber(p.rolled_back)}</Td>
                    <Td className="text-right font-mono">{formatPct(p.success_rate, 1)}</Td>
                    <Td className="w-40">
                      <ProgressBar value={p.eligible ? (p.updated / p.eligible) * 100 : 0} label={`Stage ${p.stage} progress`} tone="bg-indigo" />
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            <p className="mt-3 text-[11.5px] text-subtle">
              Thresholds: dwell {ro.thresholds.dwell_hours} h · success ≥ {ro.thresholds.min_success_rate}% · failure ≤ {ro.thresholds.max_failure_rate}% · rollback ≤ {ro.thresholds.max_rollback_rate}% · auto-advance {ro.auto_advance ? 'on' : 'off'} · auto-pause {ro.auto_pause ? 'on' : 'off'}
              {ro.rules.length > 0 && <> · rules: {ro.rules.map((r) => `${r.kind}=${r.value}`).join(', ')}</>}
            </p>
          </div>
        </Card>
      )}
      {manage && ro?.status !== 'DONE' && (
        <Card>
          <CardHeader title={ro ? 'Rollout plan' : 'Plan the rollout'} sub="Default staged 5 → 25 → 50 → 100. Tenants can hold a rollout for their organisation." />
          <RolloutConfig release={release} rollout={ro} />
        </Card>
      )}
      {!ro && !manage && <Alert>No rollout configured for this release yet.</Alert>}
      <ReasonDialog open={dialog === 'pause'} onClose={() => setDialog(null)} title="Pause rollout" description="New devices stop being offered this release. Devices that already updated are not affected." confirmLabel="Pause rollout" busy={act.isPending} onConfirm={(reason) => act.mutate({ action: 'pause', reason }, { onSuccess: () => setDialog(null) })} />
      <ReasonDialog open={dialog === 'complete'} onClose={() => setDialog(null)} title="Complete rollout" description="Offers the release to 100% of eligible devices now." confirmLabel="Complete rollout" required={false} busy={act.isPending} onConfirm={(reason) => act.mutate({ action: 'complete', reason: reason || undefined }, { onSuccess: () => setDialog(null) })} />
    </div>
  );
}

// ---------------------------------------------------------------- Downloads / Devices / Activity

export function DownloadsTab({ release }: { release: AdminReleaseDetail }) {
  const q = useQuery({ queryKey: adminKeys.releaseSub(release.id, 'downloads'), queryFn: () => adminApi.releaseDownloads(release.id) });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_1.2fr]">
      <div className="grid gap-4">
        <Kpi label="Downloads (all time)" value={formatNumber(q.data.total)} sub="completed resolver redirects, bots excluded" />
        <Card>
          <CardHeader title="Per day" />
          <div className="px-4 pb-4">
            <ColumnChart label="Downloads per day" data={q.data.series.map((s) => ({ label: s.date, value: s.count }))} />
          </div>
        </Card>
      </div>
      <Card>
        <CardHeader title="By platform / architecture / type" />
        <div className="px-4 pb-4">
          <BarList label="Downloads by package" items={q.data.by_package.map((p) => ({ label: <span className="font-sans">{p.label}</span>, value: p.count, display: formatNumber(p.count) }))} />
        </div>
      </Card>
    </div>
  );
}

export function DevicesTab({ release }: { release: AdminReleaseDetail }) {
  const q = useQuery({ queryKey: adminKeys.releaseSub(release.id, 'devices'), queryFn: () => adminApi.releaseDevices(release.id) });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  const d = q.data;
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="grid grid-cols-2 gap-3">
        <Kpi label="Update success" value={formatPct(d.update_success_rate, 1)} tone={(d.update_success_rate ?? 100) < 97 ? 'warn' : 'ok'} />
        <Kpi label="Rolled back" value={formatNumber(d.rolled_back)} />
        {Object.entries(d.events_by_type).map(([k, v]) => (
          <Kpi key={k} label={titleCase(k)} value={formatNumber(v)} />
        ))}
      </div>
      <div className="grid gap-4">
        <Card>
          <CardHeader title="Version adoption" />
          <div className="px-4 pb-4">
            <BarList label="Version adoption" max={100} items={d.adoption.map((a) => ({ label: a.version, value: a.pct, display: `${a.pct}%` }))} />
          </div>
        </Card>
        <Card>
          <CardHeader title="Failures by code" />
          <Table label="Failures by code">
            <thead>
              <tr>
                <Th>Error code</Th>
                <Th className="text-right">Count</Th>
              </tr>
            </thead>
            <tbody>
              {d.failures_by_code.map((f) => (
                <tr key={f.error_code}>
                  <Td className="font-mono">{f.error_code}</Td>
                  <Td className="text-right font-mono">{formatNumber(f.count)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      </div>
    </div>
  );
}

export function ActivityTab({ release }: { release: AdminReleaseDetail }) {
  const q = useQuery({ queryKey: adminKeys.releaseSub(release.id, 'activity'), queryFn: () => adminApi.releaseActivity(release.id) });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <Card className="p-5">
      <AuditTimeline entries={q.data.data} />
    </Card>
  );
}

// ---------------------------------------------------------------- Settings

export function SettingsTab({ release, app }: { release: AdminReleaseDetail; app: AdminApp | undefined }) {
  const { user } = useAuth();
  const r = release;
  const published = ['PUBLISHED', 'PAUSED'].includes(r.status);
  const canPolicy = can(user, 'minimum_version.manage') && !['WITHDRAWN', 'SUPERSEDED'].includes(r.status);
  const [min, setMin] = useState(r.minimum_supported_version ?? '');
  const [noChange, setNoChange] = useState(r.minimum_no_change);
  const [mandatory, setMandatory] = useState(r.mandatory);
  const [after, setAfter] = useState(r.mandatory_after?.slice(0, 16) ?? '');
  const [type, setType] = useState<ReleaseType>(r.release_type);
  const [grace, setGrace] = useState(r.grace_minutes ?? 0);
  const [reason, setReason] = useState('');
  const [immediate, setImmediate] = useState(false);
  const policy = useReleaseMutation(r.id, () =>
    adminApi.setPolicy(r.id, {
      minimum_supported_version: noChange ? null : min || null,
      minimum_no_change: noChange,
      mandatory,
      mandatory_after: mandatory && after ? new Date(after).toISOString() : null,
      release_type: type,
      grace_minutes: type === 'CRITICAL' ? grace : null,
      reason: reason || undefined,
      immediate,
    }),
    'Release policy updated',
  );
  const [support, setSupport] = useState<SupportState>(r.support_state);
  const [supportReason, setSupportReason] = useState('');
  const supportM = useReleaseMutation(r.id, () => adminApi.setSupportState(r.id, { support_state: support, reason: supportReason }), 'Support state updated');
  const [visibility, setVisibility] = useState(r.visibility);
  const [notes, setNotes] = useState(r.notes ?? '');
  const [known, setKnown] = useState(r.known_issues ?? '');
  const meta = useReleaseMutation(r.id, () => adminApi.updateRelease(r.id, { visibility, notes: notes || null, known_issues: known || null }), 'Release updated');
  const sm = r.server_meta;
  const [srv, setSrv] = useState({ min_from_version: sm?.min_from_version ?? '', desktop_compat: sm?.desktop_compat ?? '', downtime_estimate_s: sm?.downtime_estimate_s ?? 0, admin_notice: sm?.admin_notice ?? '', advisory: sm?.advisory ?? '' });
  const srvM = useReleaseMutation(r.id, () => adminApi.serverMeta(r.id, { ...srv, advisory: srv.advisory || null }), 'Server metadata saved');
  const minInvalid = !noChange && min !== '' && !isSemver(min);
  return (
    <div className="grid items-start gap-4 xl:grid-cols-2">
      <Card>
        <CardHeader title="Minimum, mandatory and critical" sub={published ? 'Published release: change requires reason + MFA (impact is audited before/after).' : undefined} />
        <form
          className="grid gap-3 px-4 pb-4 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            policy.mutate(undefined);
          }}
        >
          <fieldset disabled={!canPolicy} className="contents">
            <Select label="Release type" value={type} onChange={(e) => setType(e.target.value as ReleaseType)} options={['OPTIONAL', 'RECOMMENDED', 'MANDATORY', 'CRITICAL'].map((t) => ({ value: t, label: titleCase(t) }))} />
            {type === 'CRITICAL' && <TextField label="Critical grace (minutes)" type="number" min={0} value={grace} onChange={(e) => setGrace(Number(e.target.value))} />}
            <TextField label="Minimum supported version" mono value={min} disabled={noChange} onChange={(e) => setMin(e.target.value)} error={minInvalid ? 'Enter a valid SemVer version' : null} hint="Overrides the channel minimum upward only." />
            <Checkbox className="self-end pb-2" label="No change to minimum" checked={noChange} onChange={(e) => setNoChange(e.target.checked)} />
            <Checkbox label="Mandatory update" checked={mandatory} onChange={(e) => setMandatory(e.target.checked)} />
            {mandatory && <TextField label="Mandatory after (local time)" type="datetime-local" value={after} onChange={(e) => setAfter(e.target.value)} />}
            {published && (
              <>
                <TextArea label="Reason (required)" rows={2} className="sm:col-span-2" value={reason} onChange={(e) => setReason(e.target.value)} />
                <Checkbox className="sm:col-span-2" label="Apply immediately (otherwise from the next update check window)" checked={immediate} onChange={(e) => setImmediate(e.target.checked)} />
              </>
            )}
            <div className="sm:col-span-2">
              <Button type="submit" variant="primary" size="sm" loading={policy.isPending} disabled={!canPolicy || minInvalid || (published && !reason.trim())}>
                Save policy
              </Button>
            </div>
          </fieldset>
        </form>
      </Card>
      <div className="grid gap-4">
        <Card>
          <CardHeader title="Support state" sub="SUPPORTED · DEPRECATED (listed with warning) · UNSUPPORTED (no download link) · BLOCKED (hidden)" />
          <form
            className="grid gap-3 px-4 pb-4 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault();
              supportM.mutate(undefined);
            }}
          >
            <Select label="Support state" value={support} disabled={!can(user, 'release.publish')} onChange={(e) => setSupport(e.target.value as SupportState)} options={['SUPPORTED', 'DEPRECATED', 'UNSUPPORTED', 'BLOCKED'].map((s) => ({ value: s, label: titleCase(s) }))} />
            <TextField label="Reason" value={supportReason} onChange={(e) => setSupportReason(e.target.value)} />
            <div className="sm:col-span-2">
              <Button type="submit" size="sm" variant="primary" loading={supportM.isPending} disabled={!can(user, 'release.publish') || support === r.support_state || !supportReason.trim()}>
                Change support state
              </Button>
            </div>
          </form>
        </Card>
        <Card>
          <CardHeader title="Visibility and notes" />
          <form
            className="grid gap-3 px-4 pb-4"
            onSubmit={(e) => {
              e.preventDefault();
              meta.mutate(undefined);
            }}
          >
            <Select label="Visibility" value={visibility} onChange={(e) => setVisibility(e.target.value as 'PUBLIC' | 'HIDDEN')} options={[{ value: 'PUBLIC', label: 'Public (listed on the site)' }, { value: 'HIDDEN', label: 'Hidden (not listed; direct links per channel rule)' }]} />
            <TextArea label="Internal notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
            <TextArea label="Known issues" rows={2} value={known} onChange={(e) => setKnown(e.target.value)} />
            <div>
              <Button type="submit" size="sm" variant="primary" loading={meta.isPending} disabled={!can(user, 'release.edit')}>
                Save
              </Button>
            </div>
          </form>
        </Card>
        {sm && (
          <Card>
            <CardHeader title="Server compatibility and admin notice" sub={app?.name} />
            <form
              className="grid gap-3 px-4 pb-4 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                srvM.mutate(undefined);
              }}
            >
              <TextField label="Upgrade directly from ≥" mono value={srv.min_from_version} onChange={(e) => setSrv({ ...srv, min_from_version: e.target.value })} />
              <TextField label="Desktop compatibility" mono value={srv.desktop_compat} onChange={(e) => setSrv({ ...srv, desktop_compat: e.target.value })} placeholder="2.6.0 - 2.8.x" />
              <TextField label="Estimated downtime (s)" type="number" value={srv.downtime_estimate_s} onChange={(e) => setSrv({ ...srv, downtime_estimate_s: Number(e.target.value) })} />
              <TextField label="Security advisory (optional)" value={srv.advisory} onChange={(e) => setSrv({ ...srv, advisory: e.target.value })} />
              <TextArea label="Admin notice" rows={3} className="sm:col-span-2" value={srv.admin_notice} onChange={(e) => setSrv({ ...srv, admin_notice: e.target.value })} hint="Shown in tenant Admin Center › System › Updates and in admin emails." />
              <div className="sm:col-span-2 text-[11.5px] text-subtle">
                Migrations: {sm.migrations?.count ?? 0} ({sm.migrations?.one_way.length ?? 0} one-way) · staging test: {sm.staging_test?.status ?? '—'}
              </div>
              <div>
                <Button type="submit" size="sm" variant="primary" loading={srvM.isPending} disabled={!can(user, 'release.edit')}>
                  Save server metadata
                </Button>
              </div>
            </form>
          </Card>
        )}
      </div>
    </div>
  );
}
