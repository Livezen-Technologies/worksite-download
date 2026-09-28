import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import type { AdminReleaseDetail } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { Modal } from '@/components/ui/Modal';
import { Alert, Button, TextArea } from '@/components/ui';
import { OtpInput } from '@/components/OtpInput';
import { isConfirmed, TypeToConfirm } from '@/components/TypeToConfirm';
import { useToast } from '@/components/ui/Toast';

/** A4 — withdraw: reason (required, audited), type-the-version-to-confirm, MFA; effects stated up front. */
export function WithdrawModal({ release, open, onClose }: { release: AdminReleaseDetail; open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { stepUpValidUntil } = useAuth();
  const [reason, setReason] = useState('');
  const [confirm, setConfirm] = useState('');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const stepUpActive = !!stepUpValidUntil && new Date(stepUpValidUntil).getTime() > Date.now();
  const verifiedAgo = stepUpActive ? Math.max(0, Math.round((Date.now() - (new Date(stepUpValidUntil!).getTime() - 600_000)) / 1000)) : null;
  const ok = reason.trim().length > 0 && isConfirmed(release.version, confirm) && (stepUpActive || code.length === 6);

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await adminApi.withdraw(release.id, { reason: reason.trim(), confirm_version: confirm.trim() }, code || undefined);
      toast.success(`${release.version} withdrawn`);
      void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
      void qc.invalidateQueries({ queryKey: ['admin', 'releases'] });
      onClose();
    } catch (e) {
      if (e instanceof ApiError && (e.code === 'MFA_INVALID' || e.code === 'MFA_REQUIRED')) {
        setCode('');
        setError(e.code === 'MFA_INVALID' ? 'That code was not accepted.' : 'Enter your authenticator code.');
      } else setError(errorMessage(e));
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
      tone="danger"
      title={`Withdraw release ${release.version}?`}
      footer={
        <>
          <span />
          <div className="flex gap-2">
            <Button onClick={onClose} disabled={busy}>
              Cancel
            </Button>
            <Button variant="danger" loading={busy} disabled={!ok} onClick={() => void submit()}>
              Withdraw Release
            </Button>
          </div>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-[12.5px] text-muted">
          {release.app.name.replace(/^WorkSuite\s+/, '')} clients stop installing it immediately. Downloaded-but-not-installed copies are rejected when revalidated before install. Public download links return “unavailable”, the release is removed from
          the channel manifest and tenants are notified by webhook. This cannot be undone.
        </p>
        <TextArea label="Reason (required, audited)" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required data-autofocus="" />
        <TypeToConfirm expected={release.version} value={confirm} onChange={setConfirm} />
        {stepUpActive ? (
          <p className="text-[11.5px] text-ok">MFA verified {verifiedAgo} s ago</p>
        ) : (
          <div>
            <p className="mb-2 text-[12px] font-semibold">Authenticator code</p>
            <OtpInput value={code} onChange={setCode} idPrefix="withdraw-otp" invalid={!!error} />
          </div>
        )}
        {error && <Alert tone="bad">{error}</Alert>}
      </div>
    </Modal>
  );
}
