import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import { passkeyAssertion, passkeysSupported } from '@/services/webauthn';
import type { AdminReleaseDetail, Approval } from '@/types/api';
import { Modal } from '@/components/ui/Modal';
import { Alert, Button, Checkbox, KeyValue, Spinner, TextField } from '@/components/ui';
import { OtpInput } from '@/components/OtpInput';
import { checklistBlockers } from '@/components/ReleaseChecklist';
import { useToast } from '@/components/ui/Toast';
import { formatDateTime, formatNumber, titleCase } from '@/core/format';

export function approvalsLabel(approvals: Approval[]): string {
  const qa = approvals.filter((a) => a.kind === 'QA' && a.decision === 'PASS').map((a) => a.user);
  const rm = approvals.filter((a) => a.kind === 'RELEASE_MANAGER').map((a) => a.user);
  const parts = [qa.length ? `QA ${Array.from(new Set(qa)).join(', ')}` : null, rm.length ? `RM ${Array.from(new Set(rm)).join(', ')}` : null].filter(Boolean);
  return parts.length ? parts.join(' · ') : 'None';
}

/** A4 — final publish confirmation: everything that changes for clients, plus MFA (TOTP or passkey). */
export function PublishModal({ release, open, onClose }: { release: AdminReleaseDetail; open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const summary = useQuery({ queryKey: adminKeys.releaseSub(release.id, 'publish-summary'), queryFn: () => adminApi.publishSummary(release.id), enabled: open, staleTime: 0 });
  const [code, setCode] = useState('');
  const [reason, setReason] = useState('');
  const [schedule, setSchedule] = useState(false);
  const [when, setWhen] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [codeInvalid, setCodeInvalid] = useState(false);

  const s = summary.data;
  const blockers = checklistBlockers(s?.checklist);
  const needsMfa = s?.requires_mfa ?? true;

  const publish = async (viaPasskey = false) => {
    setBusy(true);
    setError(null);
    setCodeInvalid(false);
    try {
      if (viaPasskey) await passkeyAssertion();
      await adminApi.publish(release.id, { reason: reason || undefined, scheduled_publish_at: schedule && when ? new Date(when).toISOString() : null }, viaPasskey ? undefined : code || undefined);
      toast.success(`${release.app.name} ${release.version} ${schedule ? 'scheduled' : 'published'}`);
      void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
      void qc.invalidateQueries({ queryKey: ['admin', 'releases'] });
      void qc.invalidateQueries({ queryKey: adminKeys.dashboard });
      onClose();
    } catch (e) {
      if (e instanceof ApiError && (e.code === 'MFA_INVALID' || e.code === 'MFA_REQUIRED')) {
        setCodeInvalid(true);
        setCode('');
        setError(e.code === 'MFA_INVALID' ? 'That code was not accepted. Enter the current code from your authenticator.' : 'Enter the 6-digit code from your authenticator to publish.');
      } else if (e instanceof ApiError && e.code === 'CHECKLIST_FAILED') {
        setError(`Checklist failed: ${((e.details?.items as string[]) ?? []).join(', ') || e.message}`);
        void summary.refetch();
      } else if (e instanceof ApiError && e.code === 'SIGNING_REFUSED') setError(`The signing service refused to sign the manifest: ${e.message}`);
      else if (e instanceof ApiError && e.code === 'DISTRIBUTION_DEGRADED') setError(`Distribution is degraded — publishing is blocked until storage/CDN are healthy. ${e.message}`);
      else setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const rollout = s ? (s.rollout.mode === 'FULL' ? '100% (full)' : `${s.rollout.stages[0]}% (staged ${s.rollout.stages.join(' → ')})`) : '';
  const canSubmit = !!s && blockers.length === 0 && (!needsMfa || code.length === 6) && !busy;
  return (
    <Modal
      open={open}
      onClose={onClose}
      dismissible={!busy}
      size="sm"
      title={`Publish ${release.app.name} ${release.version}?`}
      footer={
        <>
          <span className="text-[11.5px] text-subtle">{needsMfa ? 'MFA required for production publish' : ''}</span>
          <div className="flex gap-2">
            <Button onClick={onClose} disabled={busy}>
              Cancel
            </Button>
            <Button variant="primary" loading={busy} disabled={!canSubmit} onClick={() => void publish(false)}>
              {schedule ? 'Schedule publish' : 'Publish Release'}
            </Button>
          </div>
        </>
      }
    >
      {summary.isLoading ? (
        <Spinner label="Loading publish summary…" />
      ) : summary.error ? (
        <Alert tone="bad">{errorMessage(summary.error)}</Alert>
      ) : s ? (
        <div className="space-y-4">
          <KeyValue
            rows={[
              ['Channel', titleCase(s.channel)],
              ['Update', titleCase(s.release_type)],
              ['Rollout', rollout],
              ['Minimum version', <span className="font-mono">{s.effective_minimum ?? s.minimum_supported_version ?? 'no change'}</span>],
              ['Mandatory after', s.mandatory ? formatDateTime(s.mandatory_after) : '— (not mandatory)'],
              ['Supersedes', s.supersedes.length ? <span className="font-mono">{s.supersedes.map((x) => x.version).join(', ')}</span> : '—'],
              ['Approvals', approvalsLabel(s.approvals)],
              ['Devices offered', `${formatNumber(s.affected.will_be_offered)} of ${formatNumber(s.affected.active_devices)} active`],
            ]}
          />
          <p className="text-[12.5px] text-muted">
            This will make the release available to eligible {release.app.name.replace(/^WorkSuite\s+/, '')} clients, publish its public download and release-notes pages, and sign the channel manifest.
          </p>
          {!s.health_ok && <Alert tone="warn">Distribution health is degraded. Publishing may be refused until storage and CDN checks pass.</Alert>}
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
          <Checkbox label="Schedule for later" checked={schedule} onChange={(e) => setSchedule(e.target.checked)} />
          {schedule && <TextField label="Publish at (your local time)" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} />}
          {needsMfa && (
            <div>
              <p className="mb-2 text-[12px] font-semibold">Enter the 6-digit code from your authenticator</p>
              <OtpInput value={code} onChange={(v) => (setCode(v), setCodeInvalid(false))} invalid={codeInvalid} idPrefix="publish-otp" />
              {passkeysSupported() && (
                <p className="mt-2 text-[11.5px] text-subtle">
                  or{' '}
                  <button type="button" className="link" disabled={busy || blockers.length > 0} onClick={() => void publish(true)}>
                    use a passkey
                  </button>{' '}
                  · required for production publish
                </p>
              )}
            </div>
          )}
          {error && <Alert tone="bad">{error}</Alert>}
        </div>
      ) : null}
    </Modal>
  );
}
