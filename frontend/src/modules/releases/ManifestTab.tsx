import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import type { AdminReleaseDetail, ManifestInfo, ManifestRecord } from '@/types/api';
import { formatDateTime, shortHash } from '@/core/format';
import { Alert, Badge, Button, Card, CardHeader, ErrorState, KeyValue, Spinner, Table, Td, TextArea, Th } from '@/components/ui';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';

export function JsonView({ json }: { json: unknown }) {
  return <pre className="max-h-[60vh] overflow-auto rounded-lg bg-navy p-3 font-mono text-[11.5px] leading-relaxed text-[#dfe4f5]">{JSON.stringify(json, null, 2)}</pre>;
}

export function ManifestJsonDialog({ open, onClose, json, title }: { open: boolean; onClose: () => void; json: unknown; title: string }) {
  return (
    <Modal open={open} onClose={onClose} size="lg" title={title}>
      <JsonView json={json ?? {}} />
    </Modal>
  );
}

/** Regenerate a (published) manifest: reason required, MFA via <MfaPrompt>, shows the diff (§7.6). */
export function RegenerateManifestDialog({ release, open, onClose }: { release: AdminReleaseDetail; open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [diff, setDiff] = useState<ManifestInfo['diff'] | null>(null);
  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await adminApi.regenerateManifest(release.id, reason.trim());
      setDiff(r.diff ?? []);
      toast.success('Manifest regenerated and re-signed');
      void qc.invalidateQueries({ queryKey: adminKeys.release(release.id) });
      void qc.invalidateQueries({ queryKey: adminKeys.releaseSub(release.id, 'manifest') });
    } catch (e) {
      if ((e as { code?: string }).code !== 'MFA_CANCELLED') setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const close = () => {
    setDiff(null);
    setReason('');
    setError(null);
    onClose();
  };
  return (
    <Modal
      open={open}
      onClose={close}
      size="md"
      title={`Regenerate manifest · ${release.version}`}
      subtitle={release.published_at ? 'This release is published: regenerating re-signs the manifest clients verify. MFA required.' : 'Rebuilds the draft manifest from the active revisions.'}
      footer={
        diff ? (
          <>
            <span />
            <Button variant="primary" onClick={close}>
              Done
            </Button>
          </>
        ) : (
          <>
            <Button onClick={close}>Cancel</Button>
            <Button variant="primary" loading={busy} disabled={!reason.trim()} onClick={() => void run()}>
              Regenerate &amp; sign
            </Button>
          </>
        )
      }
    >
      {diff ? (
        <div>
          <h3 className="mb-2 text-[13px] font-bold">Changes</h3>
          {diff.length === 0 ? (
            <p className="text-[12.5px] text-subtle">No content changes; signature refreshed.</p>
          ) : (
            <Table label="Manifest diff">
              <thead>
                <tr>
                  <Th>Path</Th>
                  <Th>Before</Th>
                  <Th>After</Th>
                </tr>
              </thead>
              <tbody>
                {diff.map((d) => (
                  <tr key={d.path}>
                    <Td className="font-mono">{d.path}</Td>
                    <Td className="font-mono text-bad">{JSON.stringify(d.before)}</Td>
                    <Td className="font-mono text-ok">{JSON.stringify(d.after)}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </div>
      ) : (
        <div className="space-y-3">
          <TextArea label="Reason (required, audited)" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} data-autofocus="" />
          {error && <Alert tone="bad">{error}</Alert>}
        </div>
      )}
    </Modal>
  );
}

function ManifestCard({ title, m }: { title: string; m: ManifestRecord | null }) {
  const [show, setShow] = useState(false);
  return (
    <Card>
      <CardHeader title={title} actions={m && <Button size="sm" onClick={() => setShow((s) => !s)}>{show ? 'Hide JSON' : 'View JSON'}</Button>} />
      <div className="px-4 pb-4">
        {m ? (
          <>
            <KeyValue
              rows={[
                ['Manifest version', <span className="font-mono">{m.manifest_version}</span>],
                ['Signing key', <span className="font-mono">{m.key_id ?? '—'}</span>],
                ['Signature', m.signature_valid ? <Badge tone="green">Valid</Badge> : m.signature_valid === false ? <Badge tone="red">Invalid</Badge> : <Badge>Unsigned</Badge>],
                ['SHA-256', <span className="font-mono" title={m.sha256 ?? ''}>{shortHash(m.sha256, 8)}</span>],
                ['Generated', formatDateTime(m.generated_at)],
                ['Published', formatDateTime(m.published_at)],
              ]}
            />
            {show && (
              <div className="mt-3">
                <JsonView json={m.json} />
              </div>
            )}
          </>
        ) : (
          <p className="text-[12.5px] text-subtle">Not generated yet — all packages must be READY.</p>
        )}
      </div>
    </Card>
  );
}

export function ManifestTab({ release }: { release: AdminReleaseDetail }) {
  const [regen, setRegen] = useState(false);
  const q = useQuery({ queryKey: adminKeys.releaseSub(release.id, 'manifest'), queryFn: () => adminApi.manifest(release.id) });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <div className="grid gap-4">
      <div className="flex justify-end">
        {release.allowed_actions.includes('regenerate_manifest') && (
          <Button variant="primary" size="sm" onClick={() => setRegen(true)}>
            Regenerate manifest
          </Button>
        )}
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <ManifestCard title="Release manifest" m={q.data.release} />
        <ManifestCard title="Channel manifest" m={q.data.channel} />
      </div>
      <Card>
        <CardHeader title="Manifest history" />
        <Table label="Manifest history">
          <thead>
            <tr>
              <Th>Kind</Th>
              <Th>Version</Th>
              <Th>Key</Th>
              <Th>Generated</Th>
              <Th>Published</Th>
              <Th>Superseded</Th>
              <Th>Reason</Th>
            </tr>
          </thead>
          <tbody>
            {q.data.history.map((h) => (
              <tr key={h.id}>
                <Td>{h.kind}</Td>
                <Td className="font-mono">{h.manifest_version}</Td>
                <Td className="font-mono">{h.key_id}</Td>
                <Td>{formatDateTime(h.generated_at)}</Td>
                <Td>{formatDateTime(h.published_at)}</Td>
                <Td>{formatDateTime(h.superseded_at)}</Td>
                <Td>{h.reason ?? '—'}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      </Card>
      <RegenerateManifestDialog release={release} open={regen} onClose={() => setRegen(false)} />
    </div>
  );
}
