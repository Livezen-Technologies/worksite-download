/**
 * A3 "Upload release file" and W2 "Upload server package ZIP".
 * Select the slot first (platform/arch/type or edition), then a chunked, resumable tus upload with
 * pause/resume/retry and browser-refresh recovery, then the live processing pipeline polled from
 * GET …/packages/{pkg}. Uploads are never executed.
 */
import { useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, CircleAlert, FileArchive, Loader2, Pause, Play, RotateCcw, Upload as UploadIcon, X } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import {
  createTusUpload,
  findPendingUpload,
  listPendingUploads,
  savePendingUpload,
  uploadFingerprint,
  type PendingUpload,
  type SlotKey,
  type TusController,
  type UploadProgress,
} from '@/services/upload';
import type { AdminApp, AdminPackage, AdminReleaseDetail, Arch, Edition, PackageType, PipelineStep, Platform } from '@/types/api';
import { Modal } from '@/components/ui/Modal';
import { Alert, Button, ProgressBar, Select, TextArea } from '@/components/ui';
import { isConfirmed, TypeToConfirm } from '@/components/TypeToConfirm';
import { archLabel, cx, formatBytes, PACKAGE_LABEL, PLATFORM_LABEL, shortHash } from '@/core/format';

type Phase = 'select' | 'uploading' | 'paused' | 'error' | 'processing' | 'ready' | 'rejected';

const STEP_LABEL: Record<PipelineStep['step'], string> = {
  upload: 'Upload complete',
  quarantine: 'Stored in quarantine prefix',
  scan: 'Malware scan (ClamAV)',
  sha256: 'SHA-256 generated',
  metadata: 'Metadata',
  signature: 'Digital signature',
  attestation: 'CI attestation',
  move: 'Moving to release storage',
  ready: 'Package ready',
};

export interface UploadSlot {
  platform: Platform;
  architecture: Arch;
  package_type: PackageType;
  edition?: Edition | null;
}

function StepIcon({ status, n }: { status: PipelineStep['status']; n: number }) {
  if (status === 'done') return <span className="grid size-5 place-items-center rounded-full bg-ok text-white"><Check className="size-3" strokeWidth={3} aria-hidden /></span>;
  if (status === 'failed') return <span className="grid size-5 place-items-center rounded-full bg-bad text-white"><X className="size-3" strokeWidth={3} aria-hidden /></span>;
  if (status === 'running') return <span className="grid size-5 place-items-center rounded-full bg-brand text-[10px] font-bold text-white">{n}</span>;
  if (status === 'skipped') return <span className="grid size-5 place-items-center rounded-full border border-line text-[10px] text-subtle">–</span>;
  return <span className="grid size-5 place-items-center rounded-full border border-line text-[10px] text-subtle">{n}</span>;
}

export function PipelineList({ steps }: { steps: PipelineStep[] }) {
  return (
    <ol className="divide-y divide-line" aria-label="Processing pipeline">
      {steps.map((s, i) => (
        <li key={s.step} className="flex items-center gap-3 py-2 text-[12.5px]" aria-current={s.status === 'running' ? 'step' : undefined}>
          <StepIcon status={s.status} n={i + 1} />
          <span className={cx('flex-1', s.status === 'running' && 'font-semibold', s.status === 'pending' && 'text-subtle', s.status === 'failed' && 'font-semibold text-bad')}>
            {STEP_LABEL[s.step]}
            {s.status === 'running' && <span className="sr-only"> (in progress)</span>}
            {s.status === 'failed' && <span className="sr-only"> (failed)</span>}
          </span>
          {s.detail && <span className={cx('max-w-[55%] truncate text-right text-[11.5px]', s.step === 'move' || s.step === 'quarantine' || s.step === 'sha256' ? 'font-mono text-muted' : 'text-muted')}>{s.detail}</span>}
        </li>
      ))}
    </ol>
  );
}

export function ValidationList({ items }: { items: { check: string; status: string; detail: string | null }[] }) {
  return (
    <ul className="divide-y divide-line" aria-label="Package validation">
      {items.map((v) => (
        <li key={v.check} className="flex items-start gap-2.5 py-2 text-[12.5px]">
          {v.status === 'pass' ? (
            <span className="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full bg-ok text-white"><Check className="size-2.5" strokeWidth={3} aria-hidden /></span>
          ) : v.status === 'fail' ? (
            <CircleAlert className="mt-0.5 size-4 shrink-0 text-bad" aria-hidden />
          ) : (
            <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-subtle" aria-hidden />
          )}
          <span className={cx(v.status === 'fail' && 'font-semibold text-bad', v.status === 'pending' && 'text-subtle')}>
            <span className="sr-only">{v.status}: </span>
            {v.detail ?? v.check}
          </span>
        </li>
      ))}
    </ul>
  );
}

export function PackageJsonPanel({ json }: { json: unknown }) {
  return (
    <aside className="w-full rounded-2xl bg-navy p-4 text-[#dfe4f5] shadow-2xl lg:max-w-sm" aria-label="worksuite-package.json preview">
      <div className="mb-2 text-[10.5px] font-bold tracking-[0.1em] text-[#aab4d8] uppercase">worksuite-package.json</div>
      <pre className="max-h-[60vh] overflow-auto font-mono text-[11px] leading-relaxed whitespace-pre-wrap">{JSON.stringify(json, null, 2)}</pre>
    </aside>
  );
}

export function UploadDialog({ open, onClose, release, app, initial }: { open: boolean; onClose: () => void; release: AdminReleaseDetail; app: AdminApp | undefined; initial?: UploadSlot | null }) {
  const qc = useQueryClient();
  const isServer = release.app.kind === 'SERVER';
  const platforms = useMemo(() => app?.platforms ?? [], [app]);
  const [platform, setPlatform] = useState<Platform>(initial?.platform ?? platforms[0]?.platform ?? (isServer ? 'server' : 'windows'));
  const archOptions = platforms.filter((p) => p.platform === platform);
  const [arch, setArch] = useState<Arch>(initial?.architecture ?? archOptions[0]?.architecture ?? 'x64');
  const typeOptions = archOptions.find((p) => p.architecture === arch)?.package_types ?? [];
  const [ptype, setPtype] = useState<PackageType>(initial?.package_type ?? typeOptions[0] ?? 'exe');
  const [edition, setEdition] = useState<Edition>(initial?.edition ?? 'FREE');
  const [file, setFile] = useState<File | null>(null);
  const [reason, setReason] = useState('');
  const [confirmVersion, setConfirmVersion] = useState('');
  const [phase, setPhase] = useState<Phase>('select');
  const [progress, setProgress] = useState<UploadProgress | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [netNote, setNetNote] = useState<string | null>(null);
  const [pkgId, setPkgId] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const ctl = useRef<TusController | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // keep selectors consistent
  useEffect(() => {
    if (!archOptions.some((a) => a.architecture === arch) && archOptions[0]) setArch(archOptions[0].architecture);
  }, [platform]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!typeOptions.includes(ptype) && typeOptions[0]) setPtype(typeOptions[0]);
  }, [arch, platform]); // eslint-disable-line react-hooks/exhaustive-deps

  const slot: SlotKey = isServer
    ? { releaseId: release.id, platform: 'server', architecture: 'any', packageType: 'zip', edition }
    : { releaseId: release.id, platform, architecture: arch, packageType: ptype, edition: null };
  const existing: AdminPackage | undefined = release.packages.find(
    (p) => p.platform === slot.platform && p.architecture === slot.architecture && p.package_type === slot.packageType && (p.edition ?? null) === (slot.edition ?? null),
  );
  const replacing = !!existing?.active_revision && existing.status === 'READY';
  const afterPublish = ['PUBLISHED', 'PAUSED'].includes(release.status);
  const pending = listPendingUploads(release.id);

  const pkg = useQuery({
    queryKey: adminKeys.pkg(release.id, pkgId ?? ''),
    queryFn: () => adminApi.pkg(release.id, pkgId!),
    enabled: !!pkgId && (phase === 'processing' || phase === 'ready' || phase === 'rejected'),
    refetchInterval: (q) => (q.state.data?.status === 'PROCESSING' || q.state.data?.revisions.at(-1)?.status === 'UPLOADING' || !q.state.data ? 2000 : false),
  });
  const rev = pkg.data ? (pkg.data.revisions[pkg.data.revisions.length - 1] ?? null) : null;

  useEffect(() => {
    if (phase !== 'processing' || !rev) return;
    if (rev.status === 'READY') {
      setPhase('ready');
      void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
    } else if (rev.status === 'REJECTED') {
      setPhase('rejected');
      void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
    }
  }, [phase, rev, qc, release.id]);

  useEffect(() => () => ctl.current?.pause(), []);

  const reset = () => {
    setFile(null);
    setReason('');
    setConfirmVersion('');
    setPhase('select');
    setProgress(null);
    setError(null);
    setNetNote(null);
    setPkgId(null);
    ctl.current = null;
  };

  const start = async () => {
    if (!file) return;
    setError(null);
    const fp = uploadFingerprint(slot, file);
    let record: PendingUpload | undefined = findPendingUpload(fp);
    try {
      if (!record) {
        const res = await adminApi.createPackage(release.id, {
          platform: slot.platform,
          architecture: slot.architecture,
          package_type: slot.packageType,
          edition: isServer ? slot.edition : undefined,
          file_name: file.name,
          size: file.size,
          protocol: 'tus',
          reason: replacing || afterPublish ? reason.trim() : undefined,
        });
        record = {
          ...slot,
          fingerprint: fp,
          packageId: res.package.id,
          uploadId: res.upload.id,
          tusEndpoint: res.upload.tus_endpoint,
          chunkSize: res.upload.chunk_size,
          fileName: file.name,
          size: file.size,
          lastModified: file.lastModified,
          startedAt: new Date().toISOString(),
        };
        savePendingUpload(record);
      }
    } catch (e) {
      setError(e instanceof ApiError && e.fieldErrors.reason ? e.fieldErrors.reason : errorMessage(e));
      return;
    }
    setPkgId(record.packageId);
    setPhase('uploading');
    const c = createTusUpload({
      file,
      pending: record,
      onProgress: (p) => {
        setProgress(p);
        setNetNote(null);
      },
      onSuccess: () => {
        setPhase('processing');
        void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
      },
      onError: (err, willRetry) => {
        if (willRetry) setNetNote('Network interrupted — resuming automatically…');
        else {
          setError(err.message);
          setPhase('error');
        }
      },
    });
    ctl.current = c;
    c.start();
  };

  const canStart =
    !!file && (!replacing && !afterPublish ? true : reason.trim().length > 0) && (!afterPublish || isConfirmed(release.version, confirmVersion)) && phase === 'select';
  const busy = phase === 'uploading';
  const pct = progress ? (progress.bytesUploaded / Math.max(1, progress.bytesTotal)) * 100 : 0;
  const pipeline = rev?.pipeline ?? [];
  const validation = rev?.metadata?.validation ?? [];
  const pkgJson = rev?.metadata?.package_json;

  const onDrop = (e: DragEvent) => {
    e.preventDefault();
    setDragOver(false);
    const f = e.dataTransfer.files?.[0];
    if (f && phase === 'select') setFile(f);
  };

  const title = isServer ? 'Upload Server Package' : 'Upload Release File';
  const subtitle = (
    <>
      {release.app.name} · <span className="font-mono">{release.version}</span> · {release.channel.charAt(0) + release.channel.slice(1).toLowerCase()}
    </>
  );

  const progressText = progress
    ? `${formatBytes(progress.bytesUploaded)} of ${formatBytes(progress.bytesTotal)} · ${progress.chunksDone} of ${progress.chunksTotal} chunks of ${Math.round((file ? (findPendingUpload(uploadFingerprint(slot, file))?.chunkSize ?? 8388608) : 8388608) / 1048576)} MB${progress.retries ? ` · resumed ${progress.retries === 1 ? 'once' : `${progress.retries} times`} after network drop` : ''}${progress.resumedFromOffset !== null ? ' · resumed from previous session' : ''}`
    : phase === 'processing' || phase === 'ready'
      ? 'upload complete'
      : '';

  return (
    <Modal
      open={open}
      onClose={() => {
        if (busy) ctl.current?.pause();
        onClose();
      }}
      dismissible={!busy}
      size={isServer ? 'lg' : 'md'}
      title={title}
      subtitle={subtitle}
      aside={isServer && pkgJson ? <PackageJsonPanel json={pkgJson} /> : undefined}
      footer={
        <>
          <div className="flex gap-2">
            {(phase === 'ready' || phase === 'rejected' || phase === 'error') && (
              <Button onClick={reset}>{isServer ? 'Upload another edition' : 'Upload another'}</Button>
            )}
            {phase === 'uploading' && (
              <Button icon={<Pause className="size-3.5" aria-hidden />} onClick={() => (ctl.current?.pause(), setPhase('paused'))}>
                Pause
              </Button>
            )}
            {phase === 'paused' && (
              <Button icon={<Play className="size-3.5" aria-hidden />} onClick={() => (ctl.current?.start(), setPhase('uploading'))}>
                Resume
              </Button>
            )}
            {phase === 'error' && ctl.current && (
              <Button icon={<RotateCcw className="size-3.5" aria-hidden />} onClick={() => (setError(null), ctl.current?.start(), setPhase('uploading'))}>
                Retry
              </Button>
            )}
            {(phase === 'paused' || phase === 'error') && (
              <Button variant="ghost" onClick={() => void ctl.current?.abort().then(reset)}>
                Cancel upload
              </Button>
            )}
          </div>
          {phase === 'select' ? (
            <Button variant="primary" icon={<UploadIcon className="size-3.5" aria-hidden />} disabled={!canStart} onClick={() => void start()}>
              Start upload
            </Button>
          ) : (
            <Button variant="primary" disabled={busy} onClick={onClose}>
              Done
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        {isServer ? (
          <fieldset disabled={phase !== 'select'}>
            <legend className="mb-1 text-[12px] font-semibold">Edition</legend>
            <div className="grid grid-cols-2 overflow-hidden rounded-lg border border-line sm:grid-cols-4" role="radiogroup" aria-label="Edition">
              {(['FREE', 'BUSINESS', 'ENTERPRISE', 'ALL'] as Edition[]).map((ed) => (
                <label key={ed} className={cx('cursor-pointer border-line px-3 py-2 text-center text-[12.5px] not-first:border-l', edition === ed ? 'bg-indigo font-semibold text-white' : 'bg-white hover:bg-ground')}>
                  <input type="radio" name="edition" value={ed} checked={edition === ed} onChange={() => setEdition(ed)} className="sr-only" />
                  {ed === 'ALL' ? 'All editions' : ed.charAt(0) + ed.slice(1).toLowerCase()}
                </label>
              ))}
            </div>
          </fieldset>
        ) : (
          <div className="grid gap-3 sm:grid-cols-3">
            <Select label="Platform" value={platform} disabled={phase !== 'select'} onChange={(e) => setPlatform(e.target.value as Platform)} options={Array.from(new Set(platforms.map((p) => p.platform))).map((p) => ({ value: p, label: PLATFORM_LABEL[p] }))} />
            <Select label="Architecture" value={arch} disabled={phase !== 'select'} onChange={(e) => setArch(e.target.value as Arch)} options={archOptions.map((a) => ({ value: a.architecture, label: archLabel(platform, a.architecture) === a.architecture ? a.architecture : `${a.architecture} (${archLabel(platform, a.architecture)})` }))} />
            <Select label="Package" value={ptype} disabled={phase !== 'select'} onChange={(e) => setPtype(e.target.value as PackageType)} options={typeOptions.map((t) => ({ value: t, label: PACKAGE_LABEL[t] }))} />
          </div>
        )}

        {phase === 'select' && pending.length > 0 && (
          <Alert tone="info" title="Interrupted uploads:">
            <ul className="mt-1 space-y-0.5">
              {pending.map((p) => (
                <li key={p.fingerprint}>
                  <span className="font-mono">{p.fileName}</span> ({formatBytes(p.size)}, {p.edition ?? `${p.platform} ${p.architecture} ${p.packageType}`}) — select the same file again to resume where it stopped.
                </li>
              ))}
            </ul>
          </Alert>
        )}

        {phase === 'select' && replacing && (
          <Alert tone="warn">
            This slot already has <span className="font-mono">{existing?.active_revision?.file_name}</span> (r{existing?.active_revision?.revision_number}). Uploading creates revision r{(existing?.revisions.length ?? 0) + 1}; the old revision is kept for audit.
          </Alert>
        )}

        {/* file drop zone / progress */}
        <div
          onDragOver={(e) => (e.preventDefault(), phase === 'select' && setDragOver(true))}
          onDragLeave={() => setDragOver(false)}
          onDrop={onDrop}
          className={cx('rounded-xl border border-dashed p-3', dragOver ? 'border-brand bg-brand-50' : 'border-[#b9c2e6] bg-[#f7f8fc]')}
        >
          <div className="flex items-center gap-3">
            <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-indigo" aria-hidden>
              {isServer ? <FileArchive className="size-4" /> : <UploadIcon className="size-4" />}
            </span>
            <div className="min-w-0 flex-1">
              {file ? (
                <div className="truncate font-mono text-[12.5px] font-semibold">{file.name}</div>
              ) : (
                <div className="text-[12.5px]">
                  Drop the {isServer ? 'edition ZIP' : 'installer'} here or{' '}
                  <button type="button" className="link" onClick={() => inputRef.current?.click()}>
                    choose a file
                  </button>
                </div>
              )}
              {(phase !== 'select' || progress) && (
                <ProgressBar className="my-1.5 h-2" value={phase === 'processing' || phase === 'ready' ? 100 : pct} tone={phase === 'error' ? 'bg-bad' : phase === 'paused' ? 'bg-[#d98a1c]' : 'bg-ok'} label="Upload progress" />
              )}
              <div className="text-[11.5px] text-subtle" aria-live="polite">
                {phase === 'select' && file ? `${formatBytes(file.size)} · will upload in ${Math.max(1, Math.ceil(file.size / 8388608))} chunks of 8 MB (resumable)` : progressText}
                {phase === 'paused' && ' · paused'}
              </div>
              {netNote && <div className="text-[11.5px] text-warn">{netNote}</div>}
            </div>
            {phase === 'select' && file && (
              <Button size="sm" variant="ghost" onClick={() => setFile(null)} aria-label="Remove selected file">
                <X className="size-3.5" aria-hidden />
              </Button>
            )}
          </div>
          <input
            ref={inputRef}
            type="file"
            className="sr-only"
            tabIndex={-1}
            aria-hidden
            accept={isServer ? '.zip' : typeOptions.map((t) => (t === 'appimage' ? '.AppImage' : `.${t}`)).join(',')}
            onChange={(e) => e.target.files?.[0] && setFile(e.target.files[0])}
          />
        </div>

        {phase === 'select' && (replacing || afterPublish) && (
          <TextArea label="Reason for replacement (required, audited)" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} hint={afterPublish ? 'Replacing a published file needs release.file.replace + MFA and regenerates and re-signs the manifests.' : undefined} />
        )}
        {phase === 'select' && afterPublish && <TypeToConfirm expected={release.version} value={confirmVersion} onChange={setConfirmVersion} />}

        {error && <Alert tone="bad">{error}</Alert>}

        {(phase === 'processing' || phase === 'ready' || phase === 'rejected') && (
          <div>
            <h3 className="mb-1 text-[12.5px] font-bold">{isServer ? 'Package validation' : 'Processing'}</h3>
            {isServer && validation.length ? <ValidationList items={validation} /> : pipeline.length ? <PipelineList steps={pipeline} /> : <p className="text-[12px] text-subtle">Waiting for the processing queue…</p>}
            {isServer && pipeline.length > 0 && (
              <details className="mt-2 text-[12px]">
                <summary className="cursor-pointer text-subtle">Pipeline steps</summary>
                <PipelineList steps={pipeline} />
              </details>
            )}
            {phase === 'ready' && rev && (
              <Alert tone="ok" className="mt-2">
                Ready · SHA-256 <span className="font-mono">{shortHash(rev.sha256, 6)}</span> · r{rev.revision_number}
              </Alert>
            )}
            {phase === 'rejected' && rev && (
              <Alert tone="bad" className="mt-2" title="Rejected:">
                {rev.reject_code ?? 'Validation failed'} {rev.scan_detail ?? rev.signature_detail ?? ''}
              </Alert>
            )}
          </div>
        )}

        <p className="rounded-lg bg-ground px-3 py-2 text-[11.5px] text-muted">Files are only read, hashed, scanned and verified here — the web server never executes an uploaded installer.</p>
      </div>
    </Modal>
  );
}
