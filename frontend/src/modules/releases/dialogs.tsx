import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { adminApi } from '@/services/admin';
import type { AdminReleaseDetail, Platform } from '@/types/api';
import { Modal } from '@/components/ui/Modal';
import { Alert, Button, Select, Spinner, TextArea } from '@/components/ui';
import { ChangelogSectionsView, DownloadList } from '@/components/public/blocks';
import { PLATFORM_LABEL } from '@/core/format';

/** Generic reason-capturing confirmation (pause, supersede, regenerate…). MFA is handled by <MfaPrompt>. */
export function ReasonDialog({
  open,
  onClose,
  title,
  description,
  confirmLabel,
  onConfirm,
  busy,
  required = true,
  tone = 'default',
  children,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: ReactNode;
  confirmLabel: string;
  onConfirm: (reason: string) => void;
  busy?: boolean;
  required?: boolean;
  tone?: 'default' | 'danger';
  children?: ReactNode;
}) {
  const [reason, setReason] = useState('');
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={busy} disabled={required && !reason.trim()} onClick={() => onConfirm(reason.trim())}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-[13px]">
        {description && <div className="text-muted">{description}</div>}
        {children}
        <TextArea label={`Reason${required ? ' (required, audited)' : ' (optional)'}`} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required={required} data-autofocus="" />
      </div>
    </Modal>
  );
}

export function QaDialog({ open, onClose, release, onSubmit, busy }: { open: boolean; onClose: () => void; release: AdminReleaseDetail; onSubmit: (v: { decision: 'PASS' | 'FAIL'; platform?: Platform; comment: string }) => void; busy?: boolean }) {
  const [decision, setDecision] = useState<'PASS' | 'FAIL'>('PASS');
  const [platform, setPlatform] = useState('');
  const [comment, setComment] = useState('');
  const platforms = Array.from(new Set(release.packages.map((p) => p.platform)));
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      title={`QA decision · ${release.version}`}
      subtitle="A failed QA returns the release to DRAFT. The uploader cannot give the QA approval on Stable."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant={decision === 'PASS' ? 'primary' : 'danger'} loading={busy} disabled={decision === 'FAIL' && !comment.trim()} onClick={() => onSubmit({ decision, platform: (platform || undefined) as Platform | undefined, comment })}>
            Record QA {decision === 'PASS' ? 'pass' : 'fail'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <fieldset>
          <legend className="mb-1 text-[12px] font-semibold">Decision</legend>
          <div className="flex gap-4 text-[13px]">
            {(['PASS', 'FAIL'] as const).map((d) => (
              <label key={d} className="flex items-center gap-1.5">
                <input type="radio" name="qa-decision" value={d} checked={decision === d} onChange={() => setDecision(d)} className="accent-brand" /> {d === 'PASS' ? 'Pass' : 'Fail'}
              </label>
            ))}
          </div>
        </fieldset>
        <Select label="Platform (optional)" value={platform} onChange={(e) => setPlatform(e.target.value)} options={[{ value: '', label: 'All platforms' }, ...platforms.map((p) => ({ value: p, label: PLATFORM_LABEL[p] }))]} />
        <TextArea label={`Comment${decision === 'FAIL' ? ' (required)' : ''}`} rows={3} value={comment} onChange={(e) => setComment(e.target.value)} />
      </div>
    </Modal>
  );
}

/** "Preview public page": the public release page + sample Desktop Update API responses. */
export function PreviewDialog({ open, onClose, releaseId }: { open: boolean; onClose: () => void; releaseId: string }) {
  const q = useQuery({ queryKey: ['admin', 'release', releaseId, 'preview'], queryFn: () => adminApi.preview(releaseId), enabled: open });
  return (
    <Modal open={open} onClose={onClose} size="xl" title="Preview public page" subtitle="Exactly what the public site and the Desktop Update API will show after publish.">
      {q.isLoading ? (
        <Spinner />
      ) : q.error ? (
        <Alert tone="bad">Preview unavailable.</Alert>
      ) : q.data ? (
        <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
          <div>
            <h3 className="text-[18px] font-bold">
              {q.data.public.app.name} <span className="font-mono">{q.data.public.version}</span>
            </h3>
            {q.data.public.changelog.summary && <p className="my-2 text-[13px] text-muted">{q.data.public.changelog.summary}</p>}
            <ChangelogSectionsView sections={q.data.public.changelog.sections} headingLevel={3} />
          </div>
          <div className="space-y-3">
            <DownloadList packages={q.data.public.packages} checksumsUrl={q.data.public.checksums_url} manifestUrl={q.data.public.manifest_url} />
            <div className="card p-4">
              <h3 className="mb-2 text-[13px] font-bold">Update API responses</h3>
              <ul className="space-y-2">
                {q.data.update_responses.map((u, i) => (
                  <li key={i} className="text-[12px]">
                    <div className="text-subtle">
                      {PLATFORM_LABEL[u.sample.platform]} {u.sample.architecture} · {u.sample.install_type} · from <span className="font-mono">{u.sample.current_version}</span>
                    </div>
                    <pre className="mt-1 overflow-x-auto rounded-md bg-navy p-2 font-mono text-[11px] text-[#dfe4f5]">{JSON.stringify(u.response, null, 2)}</pre>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
