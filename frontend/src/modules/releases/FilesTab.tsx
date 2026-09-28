import { Fragment, useState } from 'react';
import { ChevronDown, ChevronRight, Plus, Trash2, Upload } from 'lucide-react';
import { adminApi } from '@/services/admin';
import type { AdminApp, AdminPackage, AdminReleaseDetail, Edition } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import { archLabel, cx, formatBytes, formatDateTime, PACKAGE_LABEL, PLATFORM_LABEL, shortHash, titleCase } from '@/core/format';
import { Badge, Button, Card, CardHeader, KeyValue, Table, Td, Th } from '@/components/ui';
import { Modal } from '@/components/ui/Modal';
import { ReleaseChecklist } from '@/components/ReleaseChecklist';
import { isConfirmed, TypeToConfirm } from '@/components/TypeToConfirm';
import { TextArea } from '@/components/ui';
import { CampaignPanel } from '@/modules/server/CampaignPanel';
import { UploadDialog, type UploadSlot } from './UploadDialog';
import { useReleaseMutation } from './hooks';
import { ManifestJsonDialog, RegenerateManifestDialog } from './ManifestTab';

function SigCell({ p }: { p: AdminPackage }) {
  const r = p.active_revision;
  if (!r || p.status === 'EMPTY') return <span className="text-subtle">—</span>;
  const kind = p.platform === 'windows' ? 'Authenticode' : p.platform === 'macos' ? 'Notarized' : p.platform === 'linux' ? 'GPG' : 'Release key';
  if (r.signature_status === 'VALID') return <span className="font-semibold text-ok">✓ {kind}</span>;
  if (!r.signature_status) return <span className="text-subtle">pending</span>;
  return <span className="font-semibold text-bad" title={r.signature_detail ?? ''}>✗ {r.signature_status}</span>;
}

function StatusCell({ p }: { p: AdminPackage }) {
  if (p.status === 'READY') return <span className="font-semibold text-ok">✓ Ready</span>;
  if (p.status === 'EMPTY') return <span className="text-subtle">Missing</span>;
  if (p.status === 'REJECTED') return <span className="font-semibold text-bad">✗ Rejected</span>;
  const running = p.revisions.at(-1)?.pipeline.find((s) => s.status === 'running');
  return <span className="font-semibold text-warn">Processing{running ? ` · ${running.step}` : ''}</span>;
}

function revisionNotes(pkgs: AdminPackage[], published: boolean): string[] {
  const notes: string[] = [];
  for (const p of pkgs) {
    const replaced = p.revisions.filter((r) => r.status === 'REPLACED');
    const active = p.active_revision;
    if (replaced.length && active) {
      const label = p.edition ? titleCase(p.edition) : PACKAGE_LABEL[p.package_type];
      notes.push(`${label} r${active.revision_number} replaced ${replaced.map((r) => `r${r.revision_number}`).join(', ')}${published ? '' : ' before publication'}${active.replace_reason ? ` (reason: “${active.replace_reason}”)` : ''} — ${replaced.map((r) => `r${r.revision_number}`).join(', ')} kept for audit.`);
    }
  }
  return notes;
}

function DeletePackageDialog({ release, pkg, onClose }: { release: AdminReleaseDetail; pkg: AdminPackage; onClose: () => void }) {
  const [reason, setReason] = useState('');
  const [confirm, setConfirm] = useState('');
  const file = pkg.active_revision?.file_name ?? '';
  const del = useReleaseMutation(release.id, () => adminApi.deletePackage(release.id, pkg.id, { reason, confirm_file_name: confirm }), `${file} deleted`);
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      tone="danger"
      title="Delete release file?"
      footer={
        <>
          <span />
          <div className="flex gap-2">
            <Button onClick={onClose}>Cancel</Button>
            <Button variant="danger" loading={del.isPending} disabled={!reason.trim() || !isConfirmed(file, confirm)} onClick={() => del.mutate(undefined, { onSuccess: onClose })}>
              Delete file
            </Button>
          </div>
        </>
      }
    >
      <div className="space-y-3">
        <p className="text-[12.5px] text-muted">The slot becomes empty and the release checklist will block publish until a new file is uploaded. Requires MFA; audited.</p>
        <TextArea label="Reason (required)" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        <TypeToConfirm expected={file} value={confirm} onChange={setConfirm} what="file name" />
      </div>
    </Modal>
  );
}

function DesktopPackages({ release, onUpload }: { release: AdminReleaseDetail; onUpload: (slot: UploadSlot | null) => void }) {
  const [expanded, setExpanded] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<AdminPackage | null>(null);
  const canUpload = release.allowed_actions.includes('upload') || release.allowed_actions.includes('replace_file');
  const canDelete = release.allowed_actions.includes('delete_file');
  const total = release.packages.reduce((s, p) => s + (p.active_revision?.size ?? 0), 0);
  const published = !!release.published_at;
  const notes = revisionNotes(release.packages, published);
  return (
    <Card>
      <CardHeader
        title="Packages"
        sub={`${release.packages.length} packages · ${formatBytes(total, 1)}`}
        actions={
          canUpload && (
            <Button size="sm" icon={<Plus className="size-3.5" aria-hidden />} onClick={() => onUpload(null)}>
              Upload file
            </Button>
          )
        }
      />
      <Table label="Packages">
        <thead>
          <tr>
            <Th className="w-6">
              <span className="sr-only">Revisions</span>
            </Th>
            <Th>Platform</Th>
            <Th>Arch</Th>
            <Th>Package</Th>
            <Th>File</Th>
            <Th className="text-right">Size</Th>
            <Th>Rev</Th>
            <Th>Signature</Th>
            <Th>Status</Th>
            <Th>
              <span className="sr-only">Actions</span>
            </Th>
          </tr>
        </thead>
        <tbody>
          {release.packages.map((p) => {
            const r = p.active_revision ?? p.revisions.at(-1) ?? null;
            const open = expanded === p.id;
            return (
              <Fragment key={p.id}>
                <tr className={cx('hover:bg-ground/60', p.status === 'EMPTY' && 'bg-warn-bg/30')}>
                  <Td className="pr-0">
                    {p.revisions.length > 0 && (
                      <button type="button" aria-expanded={open} aria-label={`${open ? 'Hide' : 'Show'} revisions for ${PLATFORM_LABEL[p.platform]} ${p.architecture} ${PACKAGE_LABEL[p.package_type]}`} onClick={() => setExpanded(open ? null : p.id)} className="grid size-5 place-items-center rounded hover:bg-ground">
                        {open ? <ChevronDown className="size-3.5" aria-hidden /> : <ChevronRight className="size-3.5" aria-hidden />}
                      </button>
                    )}
                  </Td>
                  <Td className="font-semibold">{PLATFORM_LABEL[p.platform]}</Td>
                  <Td>{p.platform === 'macos' ? archLabel('macos', p.architecture) : p.architecture === 'arm64' ? 'ARM64' : p.architecture}</Td>
                  <Td>{PACKAGE_LABEL[p.package_type]}</Td>
                  <Td className="max-w-52 font-mono text-[11.5px] break-all">{r?.file_name ?? <span className="font-sans text-subtle">not uploaded</span>}</Td>
                  <Td className="text-right font-mono text-[11.5px] whitespace-nowrap">{r ? formatBytes(r.size) : '—'}</Td>
                  <Td className="font-mono text-[11.5px]">{r ? `r${r.revision_number}` : '—'}</Td>
                  <Td className="whitespace-nowrap">
                    <SigCell p={p} />
                  </Td>
                  <Td className="whitespace-nowrap">
                    <StatusCell p={p} />
                  </Td>
                  <Td className="whitespace-nowrap text-right">
                    {canUpload && (
                      <button type="button" className="grid size-7 place-items-center rounded hover:bg-ground" title={p.status === 'EMPTY' ? 'Upload' : 'Replace'} aria-label={`${p.status === 'EMPTY' ? 'Upload' : 'Replace'} ${PLATFORM_LABEL[p.platform]} ${p.architecture} ${PACKAGE_LABEL[p.package_type]}`} onClick={() => onUpload({ platform: p.platform, architecture: p.architecture, package_type: p.package_type })}>
                        <Upload className="size-3.5" aria-hidden />
                      </button>
                    )}
                    {canDelete && p.active_revision && (
                      <button type="button" className="grid size-7 place-items-center rounded text-bad hover:bg-bad-bg" title="Delete file" aria-label={`Delete ${p.active_revision.file_name}`} onClick={() => setDeleting(p)}>
                        <Trash2 className="size-3.5" aria-hidden />
                      </button>
                    )}
                  </Td>
                </tr>
                {open && (
                  <tr>
                    <td colSpan={10} className="bg-ground/60 px-8 py-2">
                      <ul className="space-y-1 text-[11.5px]">
                        {[...p.revisions].reverse().map((rv) => (
                          <li key={rv.id} className="flex flex-wrap gap-x-3">
                            <span className="font-mono font-semibold">r{rv.revision_number}</span>
                            <Badge tone={rv.status === 'READY' ? 'green' : rv.status === 'REPLACED' ? 'grey' : rv.status === 'REJECTED' ? 'red' : 'blue'}>{rv.status}</Badge>
                            <span className="font-mono">{rv.file_name}</span>
                            <span className="font-mono text-muted">sha256 {shortHash(rv.sha256, 6)}</span>
                            <span className="text-muted">
                              {rv.uploaded_by} · {formatDateTime(rv.uploaded_at)}
                            </span>
                            {rv.replace_reason && <span className="text-muted">“{rv.replace_reason}”</span>}
                          </li>
                        ))}
                      </ul>
                    </td>
                  </tr>
                )}
              </Fragment>
            );
          })}
        </tbody>
      </Table>
      <p className="px-4 py-3 text-[11.5px] text-subtle">
        {notes.map((n, i) => (
          <span key={i} className="block">
            {n}
          </span>
        ))}
        Replacing a file after publish requires <code className="font-mono">release.file.replace</code> + MFA and regenerates the manifest.
      </p>
      {deleting && <DeletePackageDialog release={release} pkg={deleting} onClose={() => setDeleting(null)} />}
    </Card>
  );
}

const MODULE_LABEL: Record<string, string> = { core: 'Core', mail: 'Mail', chat: 'Chat', meet: 'Meet', calendar: 'Calendar', tasks: 'Tasks', files: 'Files', notes: 'Notes', contacts: 'Contacts', crm: 'CRM', hr: 'HR', finance: 'Finance', projects: 'Projects', inventory: 'Inventory', sales: 'Sales', expense: 'Expenses', manufacturing: 'Manufacturing', fleet: 'Fleet', payroll: 'Payroll', 'mail-infrastructure': 'Mail Infrastructure', 'workforce-monitoring': 'Workforce Monitoring', 'sso-scim': 'SSO/SCIM', 'multi-node': 'Multi-node' };
const LOWER: Partial<Record<Edition, Edition>> = { BUSINESS: 'FREE', ENTERPRISE: 'BUSINESS' };

export function modulesSummary(p: AdminPackage, all: AdminPackage[]): string {
  const mods = (p.active_revision ?? p.revisions.at(-1))?.metadata?.package_json?.modules ?? [];
  const lowerEd = p.edition ? LOWER[p.edition] : undefined;
  const lower = lowerEd ? all.find((x) => x.edition === lowerEd) : undefined;
  const lowerMods = (lower?.active_revision ?? lower?.revisions.at(-1))?.metadata?.package_json?.modules;
  if (lowerEd && lowerMods?.length) {
    const extra = mods.filter((m) => !lowerMods.includes(m));
    return `${titleCase(lowerEd)} + ${extra.map((m) => MODULE_LABEL[m] ?? m).join(', ')}`;
  }
  return mods.filter((m) => m !== 'core').map((m) => MODULE_LABEL[m] ?? m).join(', ') || '—';
}

function EditionCards({ release, onUpload }: { release: AdminReleaseDetail; onUpload: (slot: UploadSlot | null) => void }) {
  const order: Edition[] = ['FREE', 'BUSINESS', 'ENTERPRISE', 'ALL'];
  const pkgs = [...release.packages].sort((a, b) => order.indexOf(a.edition ?? 'ALL') - order.indexOf(b.edition ?? 'ALL'));
  const canUpload = release.allowed_actions.includes('upload') || release.allowed_actions.includes('replace_file');
  return (
    <div className="grid gap-3 md:grid-cols-3">
      {pkgs.map((p) => {
        const r = p.active_revision ?? p.revisions.at(-1) ?? null;
        const running = r?.pipeline.find((s) => s.status === 'running');
        const status = p.status === 'READY' ? 'READY' : p.status === 'PROCESSING' ? 'VERIFYING' : p.status === 'REJECTED' ? 'REJECTED' : 'MISSING';
        return (
          <Card key={p.id} className="p-4">
            <div className="flex items-start justify-between gap-2">
              <h3 className="text-[14px] font-bold">{p.edition === 'ALL' ? 'Core patch (all editions)' : `${titleCase(p.edition ?? '')} Edition`}</h3>
              <Badge tone={status === 'READY' ? 'green' : status === 'VERIFYING' ? 'amber' : status === 'REJECTED' ? 'red' : 'grey'}>{status}</Badge>
            </div>
            <div className="mt-0.5 font-mono text-[11.5px] break-all text-muted">{r?.file_name ?? 'not uploaded'}</div>
            <KeyValue
              className="mt-2"
              rows={[
                ['Size', <span className="font-mono">{r ? formatBytes(r.size) : '—'}</span>],
                ['SHA-256', <span className="font-mono">{shortHash(r?.sha256)}</span>],
                ['Modules', <span className="font-normal">{modulesSummary(p, release.packages)}</span>],
                ['Distribution', p.edition === 'FREE' ? 'Public download' : p.edition === 'ALL' ? 'Any valid installation' : 'License required'],
                ['Revision', <span className="font-mono">{r ? `r${r.revision_number}` : '—'}{running ? ` · ${running.detail ?? running.step}` : ''}</span>],
              ]}
            />
            {canUpload && (
              <Button size="sm" className="mt-2 w-full" icon={<Upload className="size-3.5" aria-hidden />} onClick={() => onUpload({ platform: 'server', architecture: 'any', package_type: 'zip', edition: p.edition })}>
                {r ? 'Replace ZIP' : 'Upload ZIP'}
              </Button>
            )}
          </Card>
        );
      })}
    </div>
  );
}

export function ManifestPreviewCard({ release }: { release: AdminReleaseDetail }) {
  const [json, setJson] = useState(false);
  const [regen, setRegen] = useState(false);
  const m = release.manifest?.release;
  const ready = release.packages.filter((p) => p.status === 'READY').length;
  return (
    <Card>
      <CardHeader title="Manifest preview" />
      <div className="space-y-1 px-4 pb-4 text-[12px]">
        <p>
          Version <span className="font-mono">{release.version}</span> · {ready} packages
        </p>
        <p>
          Key <span className="font-mono">{m?.key_id ?? '—'}</span> · Signature{' '}
          {m?.signature_valid ? <strong className="text-ok">Valid</strong> : m ? <strong className="text-bad">Invalid</strong> : <span className="text-subtle">not generated</span>}
        </p>
        <div className="flex gap-2 pt-2">
          <Button size="sm" disabled={!m} onClick={() => setJson(true)}>
            View JSON
          </Button>
          {release.allowed_actions.includes('regenerate_manifest') && (
            <Button size="sm" onClick={() => setRegen(true)}>
              Regenerate
            </Button>
          )}
        </div>
      </div>
      <ManifestJsonDialog open={json} onClose={() => setJson(false)} json={m?.json} title={`Release manifest · ${release.version}`} />
      <RegenerateManifestDialog release={release} open={regen} onClose={() => setRegen(false)} />
    </Card>
  );
}

export function FilesTab({ release, app }: { release: AdminReleaseDetail; app: AdminApp | undefined }) {
  const { user } = useAuth();
  const [upload, setUpload] = useState<{ slot: UploadSlot | null } | null>(null);
  const isServer = release.app.kind === 'SERVER';
  return (
    <div className="grid items-start gap-4 xl:grid-cols-[1fr_300px]">
      <div className="grid gap-4">
        {isServer ? <EditionCards release={release} onUpload={(slot) => setUpload({ slot })} /> : <DesktopPackages release={release} onUpload={(slot) => setUpload({ slot })} />}
        {isServer && (
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title="Server release checklist" />
              <div className="px-4 pb-4">
                <ReleaseChecklist checklist={release.checklist} columns={2} compact />
              </div>
            </Card>
            <CampaignPanel release={release} canEdit={can(user, 'campaign.manage')} />
          </div>
        )}
      </div>
      <div className="grid gap-4">
        {!isServer && (
          <Card>
            <CardHeader title="Release checklist" />
            <div className="px-4 pb-4">
              <ReleaseChecklist checklist={release.checklist} compact />
            </div>
          </Card>
        )}
        <ManifestPreviewCard release={release} />
      </div>
      {upload && <UploadDialog open onClose={() => setUpload(null)} release={release} app={app} initial={upload.slot} />}
    </div>
  );
}
