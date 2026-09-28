import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, X } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import type { AdminApp, AppPlatform, ChannelCode, DownloadPageDraftBody, PackageType, Platform } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { archLabel, formatDate, formatDateTime, formatNumber, PACKAGE_LABEL, PLATFORM_LABEL, titleCase } from '@/core/format';
import { compareSemver, isSemver } from '@/core/semver';
import { Alert, Badge, BarList, Button, ButtonLink, Card, CardHeader, Checkbox, ErrorState, PageHeader, Select, Spinner, Table, Td, TextArea, TextField, Th } from '@/components/ui';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { ChannelBadge, ReleaseStatusBadge } from '@/components/StatusBadges';

function useAppMutation<T>(appId: string, fn: (v: T) => Promise<unknown>, msg: string) {
  const qc = useQueryClient();
  const toast = useToast();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      toast.success(msg);
      void qc.invalidateQueries({ queryKey: adminKeys.app(appId) });
      void qc.invalidateQueries({ queryKey: adminKeys.apps });
    },
    onError: (e) => (e as { code?: string }).code !== 'MFA_CANCELLED' && toast.error(errorMessage(e)),
  });
}

// ---------------------------------------------------------------- settings

function SettingsTab({ app, editable }: { app: AdminApp; editable: boolean }) {
  const [f, setF] = useState({
    name: app.name,
    description: app.description,
    status: app.status,
    public_download_enabled: app.public_download_enabled,
    show_previous_versions: app.show_previous_versions,
    default_channel: app.default_channel,
    channels: app.channels.filter((c) => c.enabled).map((c) => c.code),
    manifest_settings: app.manifest_settings,
    security_settings: app.security_settings,
  });
  const save = useAppMutation(app.id, () => adminApi.updateApp(app.id, { ...f, channels: f.channels }), 'Application saved');
  return (
    <Card className="p-5">
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate(undefined);
        }}
      >
        <fieldset disabled={!editable} className="contents">
          <TextField label="Name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
          <Select label="Status" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value as AdminApp['status'] })} options={['ACTIVE', 'HIDDEN', 'RETIRED'].map((s) => ({ value: s, label: titleCase(s) }))} />
          <TextArea label="Description" rows={2} className="sm:col-span-2" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} />
          <fieldset className="sm:col-span-2">
            <legend className="mb-1 text-[12px] font-semibold">Channels</legend>
            <div className="flex flex-wrap gap-4">
              {(['STABLE', 'BETA', 'DEV', 'INTERNAL', 'CUSTOM'] as ChannelCode[]).map((c) => (
                <Checkbox key={c} label={titleCase(c)} checked={f.channels.includes(c)} onChange={(e) => setF({ ...f, channels: e.target.checked ? [...f.channels, c] : f.channels.filter((x) => x !== c) })} />
              ))}
            </div>
          </fieldset>
          <Select label="Default channel" value={f.default_channel} onChange={(e) => setF({ ...f, default_channel: e.target.value as ChannelCode })} options={f.channels.map((c) => ({ value: c, label: titleCase(c) }))} />
          <div className="flex flex-col gap-2 pt-5">
            <Checkbox label="Public download enabled" checked={f.public_download_enabled} onChange={(e) => setF({ ...f, public_download_enabled: e.target.checked })} />
            <Checkbox label="Show previous versions" checked={f.show_previous_versions} onChange={(e) => setF({ ...f, show_previous_versions: e.target.checked })} />
          </div>
          <TextField label="Signing key id" mono value={f.manifest_settings.key_id ?? ''} onChange={(e) => setF({ ...f, manifest_settings: { ...f.manifest_settings, key_id: e.target.value } })} />
          <TextField label="Update-response key id" mono value={f.manifest_settings.response_key_id ?? ''} onChange={(e) => setF({ ...f, manifest_settings: { ...f.manifest_settings, response_key_id: e.target.value } })} />
          <Checkbox label="Desktop Update API enabled" checked={f.manifest_settings.update_api_enabled} onChange={(e) => setF({ ...f, manifest_settings: { ...f.manifest_settings, update_api_enabled: e.target.checked } })} />
          <Checkbox label="Private (license / TPT required)" checked={f.security_settings.private} onChange={(e) => setF({ ...f, security_settings: { ...f.security_settings, private: e.target.checked } })} />
          <Checkbox label="Malware scan required" checked={f.security_settings.malware_scan_required} onChange={(e) => setF({ ...f, security_settings: { ...f.security_settings, malware_scan_required: e.target.checked } })} />
          <div className="sm:col-span-2">
            {editable ? (
              <Button type="submit" variant="primary" loading={save.isPending}>
                Save settings
              </Button>
            ) : (
              <Alert>Read-only: application.manage is required to edit.</Alert>
            )}
          </div>
        </fieldset>
      </form>
    </Card>
  );
}

// ---------------------------------------------------------------- platforms

function PlatformsTab({ app, editable }: { app: AdminApp; editable: boolean }) {
  const [rows, setRows] = useState<AppPlatform[]>(app.platforms);
  const save = useAppMutation(app.id, () => adminApi.updateApp(app.id, { platforms: rows }), 'Platforms saved');
  const patch = (i: number, p: Partial<AppPlatform>) => setRows((r) => r.map((x, j) => (j === i ? { ...x, ...p } : x)));
  return (
    <Card>
      <CardHeader title="Platforms and architectures" sub="Default package per platform is what the public CTA offers (e.g. EXE for consumers, MSI labelled “IT / Intune”)." />
      <Table label="Platforms">
        <thead>
          <tr>
            <Th>Platform</Th>
            <Th>Arch</Th>
            <Th>Package types</Th>
            <Th>Default</Th>
            <Th>Min OS</Th>
            <Th>Required</Th>
          </tr>
        </thead>
        <tbody>
          {rows.map((p, i) => (
            <tr key={`${p.platform}-${p.architecture}`}>
              <Td className="font-semibold">{PLATFORM_LABEL[p.platform]}</Td>
              <Td>{archLabel(p.platform, p.architecture)}</Td>
              <Td>{p.package_types.map((t) => PACKAGE_LABEL[t]).join(', ')}</Td>
              <Td>
                <label className="sr-only" htmlFor={`def-${i}`}>
                  Default package
                </label>
                <select id={`def-${i}`} disabled={!editable} className="h-8 rounded-lg border border-line bg-white px-2 text-[12px]" value={p.default_package_type ?? ''} onChange={(e) => patch(i, { default_package_type: e.target.value as PackageType })}>
                  {p.package_types.map((t) => (
                    <option key={t} value={t}>
                      {PACKAGE_LABEL[t]}
                    </option>
                  ))}
                </select>
              </Td>
              <Td>
                <label className="sr-only" htmlFor={`minos-${i}`}>
                  Minimum OS
                </label>
                <input id={`minos-${i}`} disabled={!editable} className="h-8 w-40 rounded-lg border border-line px-2 text-[12px]" value={p.min_os_version ?? ''} onChange={(e) => patch(i, { min_os_version: e.target.value || null })} />
              </Td>
              <Td>
                <input type="checkbox" aria-label="Required for publish" disabled={!editable} className="size-4 accent-brand" checked={p.required_for_publish} onChange={(e) => patch(i, { required_for_publish: e.target.checked })} />
              </Td>
            </tr>
          ))}
        </tbody>
      </Table>
      {editable && (
        <div className="p-4">
          <Button variant="primary" size="sm" loading={save.isPending} onClick={() => save.mutate(undefined)}>
            Save platforms
          </Button>
        </div>
      )}
    </Card>
  );
}

// ---------------------------------------------------------------- minimum versions

function MinimumsTab({ app }: { app: AdminApp }) {
  const { user } = useAuth();
  const editable = can(user, 'minimum_version.manage');
  const q = useQuery({ queryKey: adminKeys.minimums(app.id), queryFn: () => adminApi.minimums(app.id) });
  const [channel, setChannel] = useState<ChannelCode>('STABLE');
  const [platform, setPlatform] = useState<string>('');
  const [version, setVersion] = useState('');
  const [enforce, setEnforce] = useState('');
  const [message, setMessage] = useState('');
  const [reason, setReason] = useState('');
  const [debounced, setDebounced] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setDebounced(version), 350);
    return () => clearTimeout(t);
  }, [version]);
  const impact = useQuery({
    queryKey: ['admin', 'app', app.id, 'impact', channel, platform, debounced, enforce],
    queryFn: () => adminApi.minimumImpact(app.id, { channel, platform: platform || null, version: debounced, enforce_from: enforce || null }),
    enabled: isSemver(debounced),
  });
  const qc = useQueryClient();
  const save = useAppMutation(app.id, () => adminApi.setMinimum(app.id, { channel, platform: (platform || null) as Platform | null, minimum_version: version, enforce_from: enforce ? new Date(enforce).toISOString() : null, message, reason }), 'Minimum version updated');
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <div className="grid items-start gap-4 xl:grid-cols-2">
      <div className="grid gap-4">
        <Card>
          <CardHeader title="Current minimum versions" sub="Per channel (optionally per platform). Release minimums override upward only." />
          <Table label="Minimum versions">
            <thead>
              <tr>
                <Th>Channel</Th>
                <Th>Platform</Th>
                <Th>Minimum</Th>
                <Th>Enforce from</Th>
                <Th>Message</Th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((m, i) => (
                <tr key={i}>
                  <Td>
                    <ChannelBadge channel={m.channel} />
                  </Td>
                  <Td>{m.platform ? PLATFORM_LABEL[m.platform] : 'All'}</Td>
                  <Td className="font-mono">{m.minimum_version}</Td>
                  <Td>{formatDate(m.enforce_from)}</Td>
                  <Td className="max-w-60 text-muted">{m.message ?? '—'}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
        <Card>
          <CardHeader title="History" />
          <ul className="divide-y divide-line px-4 pb-3 text-[12.5px]">
            {(q.data.history as { channel: string; minimum_version: string; before_version: string | null; reason: string | null; updated_by: string | null; created_at: string }[]).map((h, i) => (
              <li key={i} className="py-2">
                <span className="font-mono">{h.before_version ?? '—'} → {h.minimum_version}</span> · {titleCase(h.channel)} · {h.updated_by} · {formatDateTime(h.created_at)}
                {h.reason && <span className="block text-muted">“{h.reason}”</span>}
              </li>
            ))}
            {q.data.history.length === 0 && <li className="py-2 text-subtle">No changes yet.</li>}
          </ul>
        </Card>
      </div>
      <Card>
        <CardHeader title="Change minimum version" sub="Critical action: impact preview, reason and MFA required; audited before/after." />
        <form
          className="grid gap-3 px-4 pb-4 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate(undefined, { onSuccess: () => (setReason(''), void qc.invalidateQueries({ queryKey: adminKeys.minimums(app.id) })) });
          }}
        >
          <fieldset disabled={!editable} className="contents">
            <Select label="Channel" value={channel} onChange={(e) => setChannel(e.target.value as ChannelCode)} options={app.channels.filter((c) => c.enabled).map((c) => ({ value: c.code, label: titleCase(c.code) }))} />
            <Select label="Platform" value={platform} onChange={(e) => setPlatform(e.target.value)} options={[{ value: '', label: 'All platforms' }, ...Array.from(new Set(app.platforms.map((p) => p.platform))).map((p) => ({ value: p, label: PLATFORM_LABEL[p] }))]} />
            <TextField label="Minimum version" mono required value={version} onChange={(e) => setVersion(e.target.value)} error={version && !isSemver(version) ? 'Enter a valid SemVer version' : null} />
            <TextField label="Enforce from" type="datetime-local" value={enforce} onChange={(e) => setEnforce(e.target.value)} />
            <TextArea label="Message shown to users" rows={2} className="sm:col-span-2" value={message} onChange={(e) => setMessage(e.target.value)} />
            <div className="rounded-lg bg-ground p-3 sm:col-span-2" aria-live="polite">
              <div className="label-caps mb-2">Impact preview</div>
              {!isSemver(debounced) ? (
                <p className="text-[12px] text-subtle">Enter a version to see how many active devices are affected.</p>
              ) : impact.data ? (
                <>
                  <p className="mb-2 text-[13px]">
                    <strong className="text-warn">{formatNumber(impact.data.update_required)}</strong> of {formatNumber(impact.data.active_devices)} active devices will be required to update · <strong className="text-bad">{formatNumber(impact.data.unsupported)}</strong> become unsupported.
                  </p>
                  <BarList label="Active devices by version" items={impact.data.by_version.map((b) => ({ label: b.version, value: b.count, color: isSemver(b.version) && isSemver(debounced) && compareSemver(b.version, debounced) < 0 ? 'bg-[#d98a1c]' : 'bg-indigo', display: formatNumber(b.count) }))} />
                </>
              ) : (
                <Spinner label="Calculating impact…" className="p-0" />
              )}
            </div>
            <TextArea label="Reason (required, audited)" rows={2} className="sm:col-span-2" value={reason} onChange={(e) => setReason(e.target.value)} />
            <div className="sm:col-span-2">
              <Button type="submit" variant="primary" loading={save.isPending} disabled={!isSemver(version) || !reason.trim()}>
                Apply minimum version
              </Button>
            </div>
          </fieldset>
        </form>
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------- download page CMS

export function DownloadPageEditor({ app }: { app: AdminApp }) {
  const { user } = useAuth();
  const editable = can(user, 'content.manage');
  const q = useQuery({ queryKey: adminKeys.downloadPage(app.id), queryFn: () => adminApi.downloadPage(app.id) });
  const [f, setF] = useState<DownloadPageDraftBody | null>(null);
  const [previewHtml, setPreviewHtml] = useState('');
  useEffect(() => {
    if (!q.data || f) return;
    const src = q.data.draft ?? q.data.published;
    setF({
      hero: { ...src.hero },
      description_md: src.description_md,
      requirements: src.requirements,
      install_help: Object.fromEntries(Object.entries(src.install_help).map(([k, v]) => [k, v.markdown])),
      faq: src.faq.map(({ q: qq, a }) => ({ q: qq, a })),
      support_url: src.support_url,
    });
  }, [q.data, f]);
  const md = f?.description_md ?? '';
  useEffect(() => {
    const t = setTimeout(() => void adminApi.previewMarkdown(md).then((r) => setPreviewHtml(r.html)).catch(() => undefined), 400);
    return () => clearTimeout(t);
  }, [md]);
  const qc = useQueryClient();
  const toast = useToast();
  const saveDraft = useMutation({ mutationFn: () => adminApi.saveDownloadPage(app.id, f!), onSuccess: () => (toast.success('Draft saved'), void qc.invalidateQueries({ queryKey: adminKeys.downloadPage(app.id) })), onError: (e) => toast.error(errorMessage(e)) });
  const publish = useMutation({ mutationFn: () => adminApi.publishDownloadPage(app.id), onSuccess: () => (toast.success('Download page published'), void qc.invalidateQueries({ queryKey: adminKeys.downloadPage(app.id) })), onError: (e) => toast.error(errorMessage(e)) });
  if (q.isLoading || !f) return q.error ? <ErrorState error={q.error} retry={q.refetch} /> : <Spinner />;
  const platforms = Array.from(new Set(app.platforms.map((p) => p.platform)));
  return (
    <div className="grid items-start gap-4 xl:grid-cols-[1.3fr_1fr]">
      <Card className="p-5">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Badge tone={q.data?.draft ? 'amber' : 'green'}>{q.data?.draft ? `Draft v${q.data.draft.version}` : `Published v${q.data?.published.version}`}</Badge>
          <span className="text-[12px] text-subtle">Markdown with a restricted allow-list (no raw HTML, no scripts). Layout is fixed by the shared page blocks.</span>
        </div>
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            saveDraft.mutate();
          }}
        >
          <fieldset disabled={!editable} className="contents">
            <TextField label="Hero title" value={f.hero.title} onChange={(e) => setF({ ...f, hero: { ...f.hero, title: e.target.value } })} />
            <TextField label="Hero subtitle" value={f.hero.subtitle} onChange={(e) => setF({ ...f, hero: { ...f.hero, subtitle: e.target.value } })} />
            <TextField label="Screenshot URL (optional)" value={f.hero.screenshot ?? ''} onChange={(e) => setF({ ...f, hero: { ...f.hero, screenshot: e.target.value || null } })} />
            <TextArea label="Description (Markdown)" mono rows={5} value={f.description_md} onChange={(e) => setF({ ...f, description_md: e.target.value })} />
            {platforms.map((p) => (
              <div key={p} className="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-2">
                <TextArea label={`System requirements · ${PLATFORM_LABEL[p]}`} hint="One line per requirement" rows={4} value={(f.requirements[p] ?? []).join('\n')} onChange={(e) => setF({ ...f, requirements: { ...f.requirements, [p]: e.target.value.split('\n').filter((l) => l.trim()) } })} />
                <TextArea label={`Installation help · ${PLATFORM_LABEL[p]}`} mono hint="Markdown" rows={4} value={f.install_help[p] ?? ''} onChange={(e) => setF({ ...f, install_help: { ...f.install_help, [p]: e.target.value } })} />
              </div>
            ))}
            <fieldset className="rounded-lg border border-line p-3">
              <legend className="px-1 text-[12px] font-semibold">FAQ</legend>
              <div className="space-y-3">
                {f.faq.map((item, i) => (
                  <div key={i} className="grid gap-2 sm:grid-cols-[1fr_auto]">
                    <div className="grid gap-2">
                      <TextField label={`Question ${i + 1}`} value={item.q} onChange={(e) => setF({ ...f, faq: f.faq.map((x, j) => (j === i ? { ...x, q: e.target.value } : x)) })} />
                      <TextArea label={`Answer ${i + 1} (Markdown)`} rows={2} value={item.a} onChange={(e) => setF({ ...f, faq: f.faq.map((x, j) => (j === i ? { ...x, a: e.target.value } : x)) })} />
                    </div>
                    <Button size="sm" variant="ghost" aria-label={`Remove question ${i + 1}`} onClick={() => setF({ ...f, faq: f.faq.filter((_, j) => j !== i) })}>
                      <X className="size-3.5" aria-hidden />
                    </Button>
                  </div>
                ))}
                <Button size="sm" icon={<Plus className="size-3.5" aria-hidden />} onClick={() => setF({ ...f, faq: [...f.faq, { q: '', a: '' }] })}>
                  Add question
                </Button>
              </div>
            </fieldset>
            <TextField label="Support link" type="url" value={f.support_url ?? ''} onChange={(e) => setF({ ...f, support_url: e.target.value || null })} />
            {editable && (
              <div className="flex flex-wrap gap-2">
                <Button type="submit" loading={saveDraft.isPending}>
                  Save draft
                </Button>
                <Button variant="primary" loading={publish.isPending} disabled={!q.data?.draft} onClick={() => publish.mutate()}>
                  Publish page
                </Button>
                <a className="link self-center text-[12px]" href={`/apps/${app.slug}`} target="_blank" rel="noopener">
                  View live page
                </a>
              </div>
            )}
          </fieldset>
        </form>
      </Card>
      <div className="grid gap-4">
        <Card>
          <CardHeader title="Preview" sub="Description as rendered" />
          <div className="px-4 pb-4">
            <p className="text-[17px] font-bold">{f.hero.title}</p>
            <p className="mb-2 text-[12.5px] text-subtle">{f.hero.subtitle}</p>
            <div className="prose-uc" dangerouslySetInnerHTML={{ __html: previewHtml }} />
          </div>
        </Card>
        <Card>
          <CardHeader title="Version history" />
          <ul className="divide-y divide-line px-4 pb-3 text-[12.5px]">
            {q.data?.history.map((h) => (
              <li key={h.version} className="py-2">
                v{h.version} · {h.updated_by ?? '—'} · {formatDateTime(h.created_at)}
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- releases of the app

function AppReleases({ app }: { app: AdminApp }) {
  const q = useQuery({ queryKey: adminKeys.releases({ app: app.slug, limit: 100 }), queryFn: () => adminApi.releases({ app: app.slug, limit: 100 }) });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <Card>
      <CardHeader title="Releases" actions={<ButtonLink size="sm" variant="primary" to={`/admin/releases/new?app=${app.id}`}>New release</ButtonLink>} />
      <Table label="Releases">
        <thead>
          <tr>
            <Th>Version</Th>
            <Th>Channel</Th>
            <Th>Status</Th>
            <Th>Released</Th>
          </tr>
        </thead>
        <tbody>
          {q.data.data.map((r) => (
            <tr key={r.id}>
              <Td>
                <Link className="font-mono font-semibold text-indigo hover:underline" to={`/admin/releases/${r.id}`}>
                  {r.version}
                </Link>
              </Td>
              <Td>
                <ChannelBadge channel={r.channel} />
              </Td>
              <Td>
                <ReleaseStatusBadge status={r.status} />
              </Td>
              <Td>{formatDate(r.release_date)}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </Card>
  );
}

const TABS = [
  { id: 'settings', label: 'Settings' },
  { id: 'platforms', label: 'Platforms' },
  { id: 'minimums', label: 'Minimum versions' },
  { id: 'download-page', label: 'Download page' },
  { id: 'releases', label: 'Releases' },
];

export default function AppDetail() {
  const { id = '', tab = 'settings' } = useParams();
  const { user } = useAuth();
  const q = useQuery({ queryKey: adminKeys.app(id), queryFn: () => adminApi.app(id) });
  useDocumentTitle(q.data ? `${q.data.name} · Update Center` : 'Application');
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  const app = q.data;
  const editable = can(user, 'application.manage');
  return (
    <>
      <PageHeader
        title={app.name}
        breadcrumb={<Link className="link" to="/admin/applications">Applications</Link>}
        badges={<Badge tone={app.status === 'ACTIVE' ? 'green' : 'grey'}>{app.status}</Badge>}
        sub={
          <>
            <span className="font-mono">{app.app_identifier}</span> · /apps/{app.slug} · {titleCase(app.kind)} · latest stable {app.latest_stable?.version ?? '—'}
          </>
        }
        actions={<a className="link text-[12px]" href={`/apps/${app.slug}`} target="_blank" rel="noopener">Public page</a>}
      />
      <Tabs label="Application sections" active={tab} items={TABS.map((t) => ({ ...t, to: `/admin/applications/${app.id}/${t.id}` }))} className="mb-4" panelId="app-panel" />
      <div id="app-panel" role="tabpanel">
        {tab === 'settings' && <SettingsTab key={app.id + app.name} app={app} editable={editable} />}
        {tab === 'platforms' && <PlatformsTab app={app} editable={editable} />}
        {tab === 'minimums' && <MinimumsTab app={app} />}
        {tab === 'download-page' && <DownloadPageEditor app={app} />}
        {tab === 'releases' && <AppReleases app={app} />}
      </div>
    </>
  );
}
