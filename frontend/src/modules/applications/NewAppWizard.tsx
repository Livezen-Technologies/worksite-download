/** 8-step New Application wizard (§7.2). */
import { useMemo, useState, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import type { AppKind, AppPlatform, AppStatus, Arch, ChannelCode, CreateAppBody, PackageType, Platform } from '@/types/api';
import { archLabel, cx, PACKAGE_LABEL, PLATFORM_LABEL, titleCase } from '@/core/format';
import { Alert, Button, Card, Checkbox, KeyValue, PageHeader, Select, TextArea, TextField } from '@/components/ui';
import { useToast } from '@/components/ui/Toast';
import { useDocumentTitle } from '@/hooks/useDocumentHead';

export const PLATFORM_OPTIONS: Record<Platform, { archs: Arch[]; types: PackageType[]; signature: string; minOs: string }> = {
  windows: { archs: ['x64', 'arm64'], types: ['exe', 'msi'], signature: 'authenticode', minOs: 'Windows 10 (22H2)' },
  macos: { archs: ['arm64', 'x64', 'universal'], types: ['dmg', 'pkg', 'zip'], signature: 'developer-id+notarization', minOs: 'macOS 13' },
  linux: { archs: ['x64', 'arm64'], types: ['appimage', 'deb', 'rpm'], signature: 'gpg', minOs: 'glibc 2.31' },
  android: { archs: ['any'], types: ['apk', 'aab'], signature: 'apk-signature-v3', minOs: 'Android 10' },
  ios: { archs: ['any'], types: ['ipa'], signature: 'apple-distribution', minOs: 'iOS 16' },
  server: { archs: ['any'], types: ['zip'], signature: 'release-key', minOs: 'PHP 8.2' },
};
const ALL_PLATFORMS = Object.keys(PLATFORM_OPTIONS) as Platform[];
const CHANNELS: ChannelCode[] = ['STABLE', 'BETA', 'DEV', 'INTERNAL', 'CUSTOM'];
const STEPS = ['Application details', 'Platforms', 'Architectures', 'Update channels', 'Download settings', 'Manifest settings', 'Security', 'Publish'];

export function slugify(s: string): string {
  return s
    .toLowerCase()
    .replace(/^worksuite\s+/, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);
}

type Draft = Omit<CreateAppBody, 'platforms'> & { selected: Platform[]; entries: AppPlatform[] };

function initialDraft(): Draft {
  return {
    name: '',
    slug: '',
    app_identifier: '',
    description: '',
    icon: null,
    status: 'ACTIVE',
    kind: 'DESKTOP',
    selected: ['windows', 'macos', 'linux'],
    entries: [],
    channels: ['STABLE', 'BETA'],
    default_channel: 'STABLE',
    public_download_enabled: true,
    show_previous_versions: true,
    manifest_settings: { schema: 2, key_id: 'ws-release-2026a', response_key_id: 'ws-response-2026a', update_api_enabled: true },
    security_settings: { private: false, requires_license_token: false, malware_scan_required: true, signatures: {} },
  };
}

function defaultEntries(selected: Platform[], existing: AppPlatform[]): AppPlatform[] {
  const out: AppPlatform[] = [];
  for (const p of selected) {
    const mine = existing.filter((e) => e.platform === p);
    if (mine.length) out.push(...mine);
    else {
      const o = PLATFORM_OPTIONS[p];
      const archs: Arch[] = p === 'macos' ? ['arm64', 'x64'] : p === 'windows' ? ['x64', 'arm64'] : [o.archs[0]];
      for (const a of archs) out.push({ platform: p, architecture: a, package_types: p === 'linux' ? ['appimage', 'deb', 'rpm'] : p === 'macos' && a === 'x64' ? ['dmg'] : p === 'windows' && a === 'arm64' ? ['exe'] : o.types.slice(0, 2), default_package_type: o.types[0], required_for_publish: true, min_os_version: o.minOs });
    }
  }
  return out;
}

function Stepper({ step, onGo }: { step: number; onGo: (i: number) => void }) {
  return (
    <ol className="mb-4 flex flex-wrap gap-1.5" aria-label="Wizard steps">
      {STEPS.map((s, i) => (
        <li key={s}>
          <button
            type="button"
            disabled={i > step}
            onClick={() => onGo(i)}
            aria-current={i === step ? 'step' : undefined}
            className={cx('flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px]', i === step ? 'border-indigo bg-indigo text-white' : i < step ? 'border-ok/40 bg-ok-bg text-ok' : 'border-line bg-white text-subtle')}
          >
            <span className="font-bold">{i < step ? <Check className="size-3" aria-hidden /> : i + 1}</span>
            {s}
          </button>
        </li>
      ))}
    </ol>
  );
}

export default function NewAppWizard() {
  useDocumentTitle('New application · Update Center');
  const [step, setStep] = useState(0);
  const [d, setD] = useState<Draft>(initialDraft);
  const [slugTouched, setSlugTouched] = useState(false);
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const set = <K extends keyof Draft>(k: K, v: Draft[K]) => setD((x) => ({ ...x, [k]: v }));

  const body: CreateAppBody = useMemo(() => {
    const { selected: _s, entries, ...rest } = d;
    return {
      ...rest,
      platforms: entries,
      security_settings: { ...d.security_settings, requires_license_token: d.security_settings.private || d.security_settings.requires_license_token },
    };
  }, [d]);

  const create = useMutation({
    mutationFn: () => adminApi.createApp(body),
    onSuccess: (app) => {
      toast.success(`${app.name} created`);
      void qc.invalidateQueries({ queryKey: adminKeys.apps });
      navigate(`/admin/applications/${app.id}`);
    },
  });
  const fieldErrors = create.error instanceof ApiError ? create.error.fieldErrors : {};

  const stepValid = (): string | null => {
    if (step === 0) {
      if (!d.name.trim()) return 'Enter a name';
      if (!/^[a-z0-9-]{2,40}$/.test(d.slug)) return 'Slug: 2–40 lowercase letters, digits or dashes';
      if (!/^[a-z0-9]+(\.[a-z0-9-]+)+$/.test(d.app_identifier)) return 'App identifier must be reverse-DNS, e.g. lk.worksuite.desktop';
    }
    if (step === 1 && d.selected.length === 0) return 'Select at least one platform';
    if (step === 2 && d.selected.some((p) => !d.entries.some((e) => e.platform === p && e.package_types.length))) return 'Each platform needs at least one architecture with a package type';
    if (step === 3 && (!d.channels.length || !d.channels.includes(d.default_channel))) return 'Enable at least one channel and pick an enabled default';
    return null;
  };
  const err = stepValid();

  const next = () => {
    if (err) return;
    if (step === 1) setD((x) => ({ ...x, entries: defaultEntries(x.selected, x.entries.filter((e) => x.selected.includes(e.platform))) }));
    if (step === 1)
      setD((x) => ({ ...x, security_settings: { ...x.security_settings, signatures: Object.fromEntries(x.selected.map((p) => [p, x.security_settings.signatures[p] ?? PLATFORM_OPTIONS[p].signature])) } }));
    setStep((s) => Math.min(STEPS.length - 1, s + 1));
  };

  const toggleArch = (p: Platform, a: Arch, on: boolean) =>
    setD((x) => ({
      ...x,
      entries: on
        ? [...x.entries, { platform: p, architecture: a, package_types: [PLATFORM_OPTIONS[p].types[0]], default_package_type: PLATFORM_OPTIONS[p].types[0], required_for_publish: true, min_os_version: PLATFORM_OPTIONS[p].minOs }]
        : x.entries.filter((e) => !(e.platform === p && e.architecture === a)),
    }));
  const patchEntry = (p: Platform, a: Arch, patch: Partial<AppPlatform>) => setD((x) => ({ ...x, entries: x.entries.map((e) => (e.platform === p && e.architecture === a ? { ...e, ...patch } : e)) }));

  let content: ReactNode = null;
  if (step === 0)
    content = (
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Name"
          required
          value={d.name}
          onChange={(e) => {
            const name = e.target.value;
            setD((x) => ({ ...x, name, slug: slugTouched ? x.slug : slugify(name), app_identifier: x.app_identifier || '' }));
          }}
          error={fieldErrors.name}
          placeholder="WorkSuite Sync Agent"
        />
        <TextField label="Slug" required mono value={d.slug} onChange={(e) => (setSlugTouched(true), set('slug', e.target.value))} hint={`Public URL: /apps/${d.slug || '…'}`} error={fieldErrors.slug} />
        <TextField label="App identifier" required mono value={d.app_identifier} onChange={(e) => set('app_identifier', e.target.value)} placeholder="lk.worksuite.syncagent" error={fieldErrors.app_identifier} />
        <TextField label="Icon URL (optional)" value={d.icon ?? ''} onChange={(e) => set('icon', e.target.value || null)} />
        <TextArea label="Description" className="sm:col-span-2" rows={3} value={d.description} onChange={(e) => set('description', e.target.value)} />
        <Select label="Kind" value={d.kind} onChange={(e) => set('kind', e.target.value as AppKind)} options={['DESKTOP', 'SERVER', 'MOBILE', 'OTHER'].map((k) => ({ value: k, label: titleCase(k) }))} />
        <Select label="Status" value={d.status} onChange={(e) => set('status', e.target.value as AppStatus)} options={['ACTIVE', 'HIDDEN', 'RETIRED'].map((k) => ({ value: k, label: titleCase(k) }))} />
      </div>
    );
  if (step === 1)
    content = (
      <fieldset>
        <legend className="mb-2 text-[13px] font-semibold">Supported platforms</legend>
        <div className="grid gap-2 sm:grid-cols-3">
          {ALL_PLATFORMS.map((p) => (
            <Checkbox key={p} label={PLATFORM_LABEL[p]} checked={d.selected.includes(p)} onChange={(e) => set('selected', e.target.checked ? [...d.selected, p] : d.selected.filter((x) => x !== p))} />
          ))}
        </div>
      </fieldset>
    );
  if (step === 2)
    content = (
      <div className="space-y-4">
        {d.selected.map((p) => (
          <fieldset key={p} className="rounded-lg border border-line p-3">
            <legend className="px-1 text-[13px] font-bold">{PLATFORM_LABEL[p]}</legend>
            {PLATFORM_OPTIONS[p].archs.map((a) => {
              const entry = d.entries.find((e) => e.platform === p && e.architecture === a);
              return (
                <div key={a} className="border-b border-line py-2 last:border-0">
                  <Checkbox label={<strong>{archLabel(p, a) === a ? a : `${a} · ${archLabel(p, a)}`}</strong>} checked={!!entry} onChange={(e) => toggleArch(p, a, e.target.checked)} />
                  {entry && (
                    <div className="mt-2 ml-6 grid gap-2 sm:grid-cols-[1.5fr_1fr_1fr]">
                      <fieldset>
                        <legend className="text-[11.5px] font-semibold text-muted">Package types allowed</legend>
                        <div className="mt-1 flex flex-wrap gap-3">
                          {PLATFORM_OPTIONS[p].types.map((t) => (
                            <Checkbox
                              key={t}
                              label={PACKAGE_LABEL[t]}
                              checked={entry.package_types.includes(t)}
                              onChange={(ev) => {
                                const types = ev.target.checked ? [...entry.package_types, t] : entry.package_types.filter((x) => x !== t);
                                patchEntry(p, a, { package_types: types, default_package_type: types.includes(entry.default_package_type as PackageType) ? entry.default_package_type : (types[0] ?? null) });
                              }}
                            />
                          ))}
                        </div>
                      </fieldset>
                      <TextField label="Minimum OS" value={entry.min_os_version ?? ''} onChange={(ev) => patchEntry(p, a, { min_os_version: ev.target.value || null })} />
                      <Checkbox className="self-end pb-2" label="Required for publish" checked={entry.required_for_publish} onChange={(ev) => patchEntry(p, a, { required_for_publish: ev.target.checked })} />
                    </div>
                  )}
                </div>
              );
            })}
          </fieldset>
        ))}
      </div>
    );
  if (step === 3)
    content = (
      <div className="space-y-3">
        <fieldset>
          <legend className="mb-2 text-[13px] font-semibold">Enabled channels</legend>
          <div className="flex flex-wrap gap-4">
            {CHANNELS.map((c) => (
              <Checkbox key={c} label={titleCase(c)} checked={d.channels.includes(c)} onChange={(e) => set('channels', e.target.checked ? [...d.channels, c] : d.channels.filter((x) => x !== c))} />
            ))}
          </div>
        </fieldset>
        <Select label="Default channel" value={d.default_channel} onChange={(e) => set('default_channel', e.target.value as ChannelCode)} options={d.channels.map((c) => ({ value: c, label: titleCase(c) }))} className="max-w-xs" />
      </div>
    );
  if (step === 4)
    content = (
      <div className="space-y-4">
        <Checkbox label="Public download enabled" hint="Show public download pages and /download links for the Stable channel." checked={d.public_download_enabled} onChange={(e) => set('public_download_enabled', e.target.checked)} />
        <Checkbox label="Show previous versions" hint="List older versions with their support state on the public site." checked={d.show_previous_versions} onChange={(e) => set('show_previous_versions', e.target.checked)} />
        <div className="grid gap-3 sm:grid-cols-2">
          {d.entries.map((e) => (
            <Select
              key={`${e.platform}-${e.architecture}`}
              label={`Default package · ${PLATFORM_LABEL[e.platform]} ${archLabel(e.platform, e.architecture)}`}
              value={e.default_package_type ?? ''}
              onChange={(ev) => patchEntry(e.platform, e.architecture, { default_package_type: ev.target.value as PackageType })}
              options={e.package_types.map((t) => ({ value: t, label: `${PACKAGE_LABEL[t]}${t === 'msi' ? ' (IT / Intune)' : ''}` }))}
            />
          ))}
        </div>
      </div>
    );
  if (step === 5)
    content = (
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label="Manifest schema" type="number" min={1} value={d.manifest_settings.schema} onChange={(e) => set('manifest_settings', { ...d.manifest_settings, schema: Number(e.target.value) })} />
        <TextField label="Signing key id (release key)" mono value={d.manifest_settings.key_id ?? ''} onChange={(e) => set('manifest_settings', { ...d.manifest_settings, key_id: e.target.value || null })} />
        <TextField label="Update-response key id" mono value={d.manifest_settings.response_key_id ?? ''} onChange={(e) => set('manifest_settings', { ...d.manifest_settings, response_key_id: e.target.value || null })} />
        <Checkbox className="self-end pb-2" label="Desktop Update API enabled" checked={d.manifest_settings.update_api_enabled} onChange={(e) => set('manifest_settings', { ...d.manifest_settings, update_api_enabled: e.target.checked })} />
      </div>
    );
  if (step === 6)
    content = (
      <div className="space-y-4">
        <Checkbox label="Private application" hint="Downloads require sign-in; the Update API requires a valid license token / tenant policy token (TPT)." checked={d.security_settings.private} onChange={(e) => set('security_settings', { ...d.security_settings, private: e.target.checked })} />
        <Checkbox label="Require license token on update checks" checked={d.security_settings.requires_license_token || d.security_settings.private} disabled={d.security_settings.private} onChange={(e) => set('security_settings', { ...d.security_settings, requires_license_token: e.target.checked })} />
        <Checkbox label="Malware scan required" hint="Packages stay in quarantine until the scan is clean." checked={d.security_settings.malware_scan_required} onChange={(e) => set('security_settings', { ...d.security_settings, malware_scan_required: e.target.checked })} />
        <div className="grid gap-3 sm:grid-cols-2">
          {d.selected.map((p) => (
            <TextField key={p} label={`Required code signature · ${PLATFORM_LABEL[p]}`} mono value={d.security_settings.signatures[p] ?? ''} onChange={(e) => set('security_settings', { ...d.security_settings, signatures: { ...d.security_settings.signatures, [p]: e.target.value } })} />
          ))}
        </div>
      </div>
    );
  if (step === 7)
    content = (
      <div className="space-y-3">
        <KeyValue
          rows={[
            ['Name', d.name],
            ['Slug / identifier', <span className="font-mono">{d.slug} · {d.app_identifier}</span>],
            ['Kind / status', `${titleCase(d.kind)} · ${titleCase(d.status)}`],
            ['Platforms', d.entries.map((e) => `${PLATFORM_LABEL[e.platform]} ${archLabel(e.platform, e.architecture)} (${e.package_types.map((t) => PACKAGE_LABEL[t]).join('/')})`).join(', ')],
            ['Channels', `${d.channels.map(titleCase).join(', ')} · default ${titleCase(d.default_channel)}`],
            ['Public download', d.public_download_enabled ? 'On' : 'Off'],
            ['Previous versions', d.show_previous_versions ? 'Shown' : 'Hidden'],
            ['Manifest', `schema ${d.manifest_settings.schema} · key ${d.manifest_settings.key_id ?? '—'} · response ${d.manifest_settings.response_key_id ?? '—'} · API ${d.manifest_settings.update_api_enabled ? 'on' : 'off'}`],
            ['Security', `${d.security_settings.private ? 'Private (license/TPT required)' : 'Public'} · malware scan ${d.security_settings.malware_scan_required ? 'required' : 'optional'}`],
          ]}
        />
        <p className="text-[12px] text-subtle">Creating the application is audited. You can change everything except the slug and identifier later.</p>
        {create.error && <Alert tone="bad">{errorMessage(create.error)}</Alert>}
      </div>
    );

  return (
    <>
      <PageHeader title="New application" breadcrumb={<Link className="link" to="/admin/applications">Applications</Link>} sub={`Step ${step + 1} of ${STEPS.length} · ${STEPS[step]}`} />
      <Stepper step={step} onGo={setStep} />
      <Card className="max-w-4xl p-5">
        <h2 className="mb-4 text-[15px] font-bold">
          {step + 1}. {STEPS[step]}
        </h2>
        {content}
        {err && step < 7 && <p className="mt-3 text-[12px] text-warn" role="status">{err}</p>}
        <div className="mt-5 flex justify-between gap-2 border-t border-line pt-4">
          <Button onClick={() => setStep((s) => Math.max(0, s - 1))} disabled={step === 0}>
            Back
          </Button>
          {step < STEPS.length - 1 ? (
            <Button variant="primary" onClick={next} disabled={!!err}>
              Next
            </Button>
          ) : (
            <Button variant="primary" loading={create.isPending} onClick={() => create.mutate()}>
              Create application
            </Button>
          )}
        </div>
      </Card>
    </>
  );
}
