/**
 * Realistic fixture data for the in-browser mock API (VITE_API_MOCK=true).
 * WorkSuite Desktop 2.7.0 stable (2.7.1 approved, 2.8.0-beta.2 testing), 2.6.5 supported, 2.6.4 deprecated,
 * 2.4.x unsupported; WorkSuite Server 3.4.0 with three editions; Bug Trace issues and user reports.
 */
import type {
  AdminApp,
  AdminChannel,
  AdminPackage,
  AdminReleaseDetail,
  AdminUser,
  ApiClient,
  AppPlatform,
  Approval,
  Arch,
  AuditEntry,
  BugAlert,
  Campaign,
  Changelog,
  ChangelogSections,
  ChannelCode,
  DownloadPage,
  Edition,
  Installation,
  IssueDetail,
  PackageType,
  PipelineStep,
  Platform,
  ReleaseStatus,
  ReleaseType,
  ReportDetail,
  Revision,
  Rollout,
  SigningKey,
  StorageOverview,
  SupportState,
} from '@/types/api';
import { renderMarkdown } from './markdown';

// ---------------------------------------------------------------- helpers

const NOW = Date.now();
export function ago(minutes: number): string {
  return new Date(NOW - minutes * 60_000).toISOString().replace(/\.\d{3}Z$/, 'Z');
}
const day = (d: number, h = 0) => ago(d * 1440 + h * 60);

export function fakeSha(seed: string): string {
  let h1 = 0x811c9dc5;
  let out = '';
  for (let round = 0; out.length < 64; round++) {
    for (let i = 0; i < seed.length; i++) {
      h1 ^= seed.charCodeAt(i) + round;
      h1 = Math.imul(h1, 16777619) >>> 0;
    }
    out += h1.toString(16).padStart(8, '0');
  }
  return out.slice(0, 64);
}

let idCounter = 1000;
export function newId(prefix: string): string {
  idCounter++;
  return `${prefix}_${idCounter.toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}

// ---------------------------------------------------------------- RBAC (mirrors backend App\Libraries\Rbac)

const SA = 'super_admin',
  RM = 'release_manager',
  DEV = 'developer',
  QA = 'qa',
  CM = 'content_manager',
  SUP = 'support',
  VW = 'viewer';

export const ROLES: { slug: string; name: string }[] = [
  { slug: SA, name: 'Super Admin' },
  { slug: RM, name: 'Release Manager' },
  { slug: DEV, name: 'Developer' },
  { slug: QA, name: 'QA' },
  { slug: CM, name: 'Content Manager' },
  { slug: SUP, name: 'Support' },
  { slug: VW, name: 'Viewer' },
];

export const PERMISSIONS: { slug: string; name: string; roles: string[] }[] = [
  ['application.view', 'View applications', [SA, RM, DEV, QA, CM, SUP, VW]],
  ['application.manage', 'Create and edit applications', [SA]],
  ['release.view', 'View releases', [SA, RM, DEV, QA, CM, SUP, VW]],
  ['release.create', 'Create releases', [SA, RM, DEV]],
  ['release.edit', 'Edit releases / submit for test', [SA, RM, DEV]],
  ['release.upload', 'Upload packages / attestations', [SA, RM, DEV]],
  ['release.approve.qa', 'QA approval', [SA, QA]],
  ['release.approve', 'Release Manager approval', [SA, RM]],
  ['release.publish', 'Publish, pause, resume, regenerate manifests', [SA, RM]],
  ['release.withdraw', 'Withdraw releases', [SA, RM]],
  ['release.file.upload', 'Upload release files', [SA, RM, DEV]],
  ['release.file.replace', 'Replace release files', [SA, RM]],
  ['release.file.delete', 'Delete release files', [SA]],
  ['changelog.view', 'View changelogs', [SA, RM, DEV, QA, CM, SUP, VW]],
  ['changelog.manage', 'Edit changelogs', [SA, RM, DEV, CM]],
  ['rollout.view', 'View rollouts', [SA, RM, DEV, QA, SUP]],
  ['rollout.manage', 'Manage rollouts', [SA, RM]],
  ['minimum_version.manage', 'Change minimum / mandatory versions', [SA, RM]],
  ['downloads.view', 'View download analytics', [SA, RM, DEV, CM, SUP, VW]],
  ['devices.view', 'View device statistics', [SA, RM, DEV, QA, SUP]],
  ['content.manage', 'Edit download pages', [SA, CM]],
  ['user.manage', 'Manage users', [SA]],
  ['api_client.manage', 'Manage API clients', [SA]],
  ['storage.manage', 'Manage storage / CDN', [SA]],
  ['security.manage', 'Security settings', [SA]],
  ['signing_key.rotate', 'Rotate signing keys', [SA]],
  ['audit.view', 'View audit log', [SA, RM, SUP]],
  ['campaign.manage', 'Server notification campaigns', [SA, RM]],
  ['installations.view', 'View server installations', [SA, RM, DEV, SUP]],
  ['fleet.manage', 'Managed fleet rollouts', [SA, RM]],
  ['bugtrace.view', 'Bug Trace: view', [SA, RM, DEV, QA, SUP, VW]],
  ['bugtrace.triage', 'Bug Trace: triage', [SA, RM, DEV, QA, SUP]],
  ['bugtrace.manage', 'Bug Trace: projects and alerts', [SA, RM]],
  ['bugtrace.reporter.contact', 'Bug Trace: see reporter contact', [SA, SUP]],
  ['bugtrace.settings', 'Bug Trace: privacy settings / deletion', [SA]],
  ['bugtrace.export', 'Bug Trace: export', [SA, RM]],
  ['bugtrace.tenant.view', 'Bug Trace: see tenant attribution', [SA, SUP]],
].map(([slug, name, roles]) => ({ slug: slug as string, name: name as string, roles: roles as string[] }));

export function permissionsFor(roles: string[]): string[] {
  return PERMISSIONS.filter((p) => p.roles.some((r) => roles.includes(r))).map((p) => p.slug);
}

// ---------------------------------------------------------------- applications

function plat(platform: Platform, architecture: Arch, types: PackageType[], required = true, min_os: string | null = null): AppPlatform {
  return { platform, architecture, package_types: types, default_package_type: types[0] ?? null, required_for_publish: required, min_os_version: min_os };
}

const DESKTOP_PLATFORMS: AppPlatform[] = [
  plat('windows', 'x64', ['exe', 'msi'], true, 'Windows 10 (22H2)'),
  plat('windows', 'arm64', ['exe'], true, 'Windows 11'),
  plat('macos', 'arm64', ['dmg', 'pkg'], true, 'macOS 13 Ventura'),
  plat('macos', 'x64', ['dmg'], true, 'macOS 13 Ventura'),
  plat('linux', 'x64', ['appimage', 'deb', 'rpm'], true, 'glibc 2.31'),
];

interface AppSeed {
  id: string;
  slug: string;
  name: string;
  identifier: string;
  kind: AdminApp['kind'];
  description: string;
  platforms: AppPlatform[];
  channels: ChannelCode[];
  created: string;
}

const APP_SEEDS: AppSeed[] = [
  {
    id: 'app_desktop',
    slug: 'desktop',
    name: 'WorkSuite Desktop',
    identifier: 'lk.worksuite.desktop',
    kind: 'DESKTOP',
    description: 'Mail, Chat, Meet, Files and every WorkSuite module in one desktop app for Windows, macOS and Linux.',
    platforms: DESKTOP_PLATFORMS,
    channels: ['STABLE', 'BETA', 'DEV', 'INTERNAL'],
    created: day(420),
  },
  {
    id: 'app_installer',
    slug: 'installer',
    name: 'WorkSuite Installer',
    identifier: 'lk.worksuite.installer',
    kind: 'DESKTOP',
    description: 'Guided installer for WorkSuite on managed Windows and macOS fleets.',
    platforms: [plat('windows', 'x64', ['exe', 'msi'], true, 'Windows 10'), plat('macos', 'universal', ['pkg'], true, 'macOS 13')],
    channels: ['STABLE', 'INTERNAL'],
    created: day(380),
  },
  {
    id: 'app_quicksupport',
    slug: 'quick-support',
    name: 'Quick Support',
    identifier: 'lk.worksuite.quicksupport',
    kind: 'DESKTOP',
    description: 'Let WorkSuite support connect to your screen with your permission — no installation needed.',
    platforms: [plat('windows', 'x64', ['exe'], true, 'Windows 10'), plat('macos', 'universal', ['dmg'], true, 'macOS 12')],
    channels: ['STABLE'],
    created: day(300),
  },
  {
    id: 'app_syncagent',
    slug: 'sync-agent',
    name: 'Sync Agent',
    identifier: 'lk.worksuite.syncagent',
    kind: 'DESKTOP',
    description: 'Background file sync agent for WorkSuite Files/Drive on servers and workstations.',
    platforms: [plat('windows', 'x64', ['msi'], true, 'Windows Server 2019'), plat('linux', 'x64', ['deb', 'rpm'], true, 'glibc 2.28')],
    channels: ['STABLE', 'BETA'],
    created: day(250),
  },
  {
    id: 'app_server',
    slug: 'server',
    name: 'WorkSuite Server (Web)',
    identifier: 'lk.worksuite.web',
    kind: 'SERVER',
    description: 'Self-hosted WorkSuite web system — Free, Business and Enterprise editions.',
    platforms: [plat('server', 'any', ['zip'], true, 'PHP 8.2+, MariaDB 10.6+')],
    channels: ['STABLE', 'BETA'],
    created: day(200),
  },
];

function adminAppFromSeed(s: AppSeed): AdminApp {
  return {
    id: s.id,
    slug: s.slug,
    name: s.name,
    app_identifier: s.identifier,
    kind: s.kind,
    description: s.description,
    icon: null,
    status: 'ACTIVE',
    public_download_enabled: true,
    private: false,
    latest_stable: null,
    latest_beta: null,
    platforms: s.platforms,
    default_channel: 'STABLE',
    channels: (['STABLE', 'BETA', 'DEV', 'INTERNAL', 'CUSTOM'] as ChannelCode[]).map((code) => ({ code, enabled: s.channels.includes(code) })),
    manifest_settings: { schema: 2, key_id: 'ws-release-2026a', response_key_id: 'ws-response-2026a', update_api_enabled: s.kind !== 'SERVER' },
    security_settings: {
      private: false,
      requires_license_token: s.kind === 'SERVER',
      malware_scan_required: true,
      signatures:
        s.kind === 'SERVER' ? { server: 'release-key' } : { windows: 'authenticode', macos: 'developer-id+notarization', linux: 'gpg' },
    },
    show_previous_versions: true,
    releases_count: 0,
    created_at: s.created,
  };
}

// ---------------------------------------------------------------- download pages

function page(title: string, subtitle: string, description: string, requirements: Record<string, string[]>, help: Record<string, string>, faq: { q: string; a: string }[]): DownloadPage {
  return {
    hero: { title, subtitle, screenshot: null },
    description_md: description,
    description_html: renderMarkdown(description),
    requirements,
    install_help: Object.fromEntries(Object.entries(help).map(([k, md]) => [k, { markdown: md, html: renderMarkdown(md) }])),
    faq: faq.map((f) => ({ ...f, a_html: renderMarkdown(f.a) })),
    support_url: 'https://support.worksuite.lk',
    version: 3,
    status: 'PUBLISHED',
  };
}

export const DESKTOP_REQUIREMENTS: Record<string, string[]> = {
  windows: ['Windows 10 (22H2) or Windows 11', 'x64 or ARM64 processor', '4 GB RAM (8 GB recommended)', '600 MB free disk space'],
  macos: ['macOS 13 Ventura or later', 'Apple Silicon (M1–M4) or Intel', '4 GB RAM', '700 MB free disk space'],
  linux: ['x64, glibc 2.31+ (Ubuntu 20.04+, Debian 11+, Fedora 36+, RHEL 9)', '4 GB RAM', '600 MB free disk space'],
};

function pageFor(s: AppSeed): DownloadPage {
  if (s.slug === 'desktop') {
    return page(
      'Download the latest WorkSuite applications',
      s.description,
      'WorkSuite Desktop brings **Mail, Chat, Meet, Files** and every WorkSuite module together in one fast native app. It keeps working offline and updates itself safely in the background.',
      DESKTOP_REQUIREMENTS,
      {
        windows: '1. Run the downloaded `.exe`.\n2. Follow the installer — no admin rights needed for per-user installs.\n3. IT teams: deploy the **MSI** with Intune or Group Policy.',
        macos: '1. Open the `.dmg` and drag WorkSuite to Applications.\n2. Managed Macs: deploy the signed **PKG** with your MDM.',
        linux: '- **AppImage**: `chmod +x` and run.\n- **DEB/RPM**: install with your package manager; updates arrive through our repository.',
      },
      [
        { q: 'Which Windows build should I choose?', a: 'Most PCs are **x64**. Surface Pro X and Snapdragon laptops use **ARM64** — check Settings › System › About › System type.' },
        { q: 'Is my Mac Apple Silicon or Intel?', a: 'Open  › About This Mac. **Chip: Apple M1/M2/M3/M4** means Apple Silicon; **Processor: Intel** means Intel.' },
        { q: 'How do I verify my download?', a: 'Compare the file’s SHA-256 with `checksums.txt` on the release page, or verify the signed manifest.' },
      ],
    );
  }
  if (s.kind === 'SERVER') {
    return page(
      'WorkSuite Server (Web)',
      s.description,
      'The **Free** edition can be downloaded here. Business and Enterprise packages are delivered to licensed installations through **Admin Center › System › Updates**.',
      { server: ['PHP 8.2 – 8.4 with intl, sodium, imap, mbstring, gd', 'MariaDB 10.6+ or MySQL 8.0+', '1.2 GB free disk space', 'Redis 6+ (recommended)'] },
      { server: '1. Unzip into a new release directory.\n2. Run `php spark worksuite:install`.\n3. Point your web server to `public/`.' },
      [{ q: 'Can I upgrade from 3.1?', a: 'Direct upgrades are supported from **3.2.0**. Older installations receive an ordered upgrade chain.' }],
    );
  }
  return page(
    s.name,
    s.description,
    s.description,
    Object.fromEntries(s.platforms.map((p) => [p.platform, [p.min_os_version ?? 'Current OS', `${p.architecture} processor`]])),
    Object.fromEntries(s.platforms.map((p) => [p.platform, `Run the downloaded installer for ${p.platform}.`])),
    [],
  );
}

// ---------------------------------------------------------------- channels

export const CHANNELS: AdminChannel[] = [
  { id: 'chn_stable', code: 'STABLE', name: 'Stable', description: 'Fully tested releases for everyone. Recommended for production use.', is_public: true, is_default: true, allow_direct_download: true, allow_auto_update: true, access_rule: { audience: 'anyone' }, retention: { binaries: 10 } },
  { id: 'chn_beta', code: 'BETA', name: 'Beta', description: 'Preview upcoming features a few weeks early. Sign in and opt in from your portal profile.', is_public: false, is_default: false, allow_direct_download: true, allow_auto_update: true, access_rule: { audience: 'signed_in_beta', tpt: 'beta' }, retention: { binaries: 5 } },
  { id: 'chn_dev', code: 'DEV', name: 'Dev', description: 'Nightly developer builds for authorised developers and QA.', is_public: false, is_default: false, allow_direct_download: false, allow_auto_update: true, access_rule: { audience: 'staff', roles: ['developer', 'qa'], tpt: 'dev' }, retention: { binaries: 10 } },
  { id: 'chn_internal', code: 'INTERNAL', name: 'Internal', description: 'Livezen staff and internal tenant dog-fooding.', is_public: false, is_default: false, allow_direct_download: false, allow_auto_update: true, access_rule: { audience: 'internal', tpt: 'internal' }, retention: { binaries: 10 } },
  { id: 'chn_custom', code: 'CUSTOM', name: 'Custom', description: 'Per allow-list channels for named tenants and organisations.', is_public: false, is_default: false, allow_direct_download: false, allow_auto_update: true, access_rule: { audience: 'allow_list', tenants: [] }, retention: { binaries: 10 } },
];

// ---------------------------------------------------------------- releases

const DESKTOP_SIZES: Record<string, number> = {
  'windows/x64/exe': 320_400_000,
  'windows/x64/msi': 305_100_000,
  'windows/arm64/exe': 312_300_000,
  'macos/arm64/dmg': 340_200_000,
  'macos/arm64/pkg': 338_700_000,
  'macos/x64/dmg': 351_000_000,
  'linux/x64/appimage': 298_400_000,
  'linux/x64/deb': 281_300_000,
  'linux/x64/rpm': 283_900_000,
};

const SIG_KIND: Record<string, string> = { windows: 'Authenticode', macos: 'Notarized', linux: 'GPG', server: 'Release key' };

export function fileNameFor(app: AdminApp, version: string, platform: Platform, arch: Arch, type: PackageType, edition?: Edition | null): string {
  if (app.kind === 'SERVER') return `worksuite-server-${version}-${(edition ?? 'ALL') === 'ALL' ? 'core-patch' : edition!.toLowerCase()}.zip`;
  const base = app.slug === 'desktop' ? 'worksuite' : `worksuite-${app.slug}`;
  const map: Record<string, string> = {
    'windows/x64/exe': `win-x64.exe`,
    'windows/x64/msi': `win-x64.msi`,
    'windows/arm64/exe': `win-arm64.exe`,
    'macos/arm64/dmg': `mac-arm64.dmg`,
    'macos/arm64/pkg': `mac-arm64.pkg`,
    'macos/x64/dmg': `mac-x64.dmg`,
    'macos/universal/dmg': `mac-universal.dmg`,
    'macos/universal/pkg': `mac-universal.pkg`,
    'linux/x64/appimage': `x64.AppImage`,
    'linux/x64/deb': `amd64.deb`,
    'linux/x64/rpm': `x86_64.rpm`,
  };
  return `${base}-${version}-${map[`${platform}/${arch}/${type}`] ?? `${platform}-${arch}.${type}`}`;
}

export const PIPELINE_ORDER: PipelineStep['step'][] = ['upload', 'quarantine', 'scan', 'sha256', 'metadata', 'signature', 'attestation', 'move', 'ready'];

export function donePipeline(at: string, rev: { file_name: string; sha256: string | null; sig: string; path: string }): PipelineStep[] {
  const details: Record<string, string> = {
    upload: 'Upload complete',
    quarantine: `Stored in quarantine prefix tmp/…/${(rev.sha256 ?? 'a91f').slice(0, 4)}`,
    scan: 'Malware scan (ClamAV) — clean · 6.2 s',
    sha256: `SHA-256 ${(rev.sha256 ?? '').slice(0, 4)}…${(rev.sha256 ?? '').slice(-4)}`,
    metadata: 'Metadata matches release',
    signature: `Digital signature valid — Livezen Technologies (Pvt) Ltd · ${rev.sig}`,
    attestation: 'CI attestation matches',
    move: rev.path,
    ready: 'Package ready',
  };
  return PIPELINE_ORDER.map((step) => ({ step, status: 'done', detail: details[step], at }));
}

interface RevSeed {
  n: number;
  status?: Revision['status'];
  replace_reason?: string | null;
  uploaded_by?: string;
  at?: string;
}

function makeRevision(app: AdminApp, version: string, channel: ChannelCode, platform: Platform, arch: Arch, type: PackageType, edition: Edition | null, r: RevSeed): Revision {
  const file = fileNameFor(app, version, platform, arch, type, edition);
  const size = app.kind === 'SERVER' ? ({ FREE: 118_200_000, BUSINESS: 164_300_000, ENTERPRISE: 212_800_000, ALL: 12_400_000 } as Record<string, number>)[edition ?? 'ALL'] : (DESKTOP_SIZES[`${platform}/${arch}/${type}`] ?? 96_000_000);
  const sha = fakeSha(`${file}#${r.n}`);
  const at = r.at ?? day(3);
  const status = r.status ?? 'READY';
  const path = `${app.slug}/${channel.toLowerCase()}/${version}/${platform}/`;
  return {
    id: newId('rev'),
    revision_number: r.n,
    file_name: file,
    size,
    sha256: sha,
    status,
    reject_code: null,
    signature_status: 'VALID',
    signature_detail: `${SIG_KIND[platform]} · Livezen Technologies (Pvt) Ltd, timestamped`,
    scan_status: 'CLEAN',
    scan_detail: 'ClamAV 1.4 — no threats',
    attestation_match: true,
    metadata: app.kind === 'SERVER' ? serverMetadata(version, edition ?? 'ALL') : { product_version: `${version}`, arch, format: type.toUpperCase() },
    pipeline: donePipeline(at, { file_name: file, sha256: sha, sig: SIG_KIND[platform], path }),
    replace_reason: r.replace_reason ?? null,
    uploaded_by: r.uploaded_by ?? 'CI token (github-actions)',
    uploaded_at: at,
  };
}

export const EDITION_MODULES: Record<Edition, string[]> = {
  FREE: ['core', 'mail', 'chat', 'meet', 'calendar', 'tasks', 'files', 'notes', 'contacts'],
  BUSINESS: ['core', 'mail', 'chat', 'meet', 'calendar', 'tasks', 'files', 'notes', 'contacts', 'crm', 'hr', 'finance', 'projects', 'inventory', 'sales', 'expense'],
  ENTERPRISE: [
    'core', 'mail', 'chat', 'meet', 'calendar', 'tasks', 'files', 'notes', 'contacts', 'crm', 'hr', 'finance', 'projects', 'inventory', 'sales', 'expense',
    'manufacturing', 'fleet', 'payroll', 'mail-infrastructure', 'workforce-monitoring', 'sso-scim', 'multi-node',
  ],
  ALL: ['core'],
};

export function serverMetadata(version: string, edition: Edition) {
  const ed = edition.toLowerCase();
  return {
    package_json: {
      schema: 1,
      product: 'worksuite-server',
      version,
      build: 34012,
      edition: ed === 'all' ? 'all' : ed,
      scope: edition === 'ALL' ? 'core-patch' : 'full',
      min_from_version: '3.2.0',
      channel: 'stable',
      requires: { php: '>=8.2 <8.5', db: 'mariadb>=10.6|mysql>=8.0', ext: ['intl', 'sodium', 'imap', 'mbstring', 'gd'], disk_mb: 1200 },
      modules: EDITION_MODULES[edition],
      migrations: { count: 27, one_way: ['2026-09-25-120000_CreateDesktop', '2026-09-26-090000_SplitLedger'] },
      removed_files: ['app/Modules/Legacy/…'],
      post_update: ['cache:clear', 'desktop:catalog-sync'],
      desktop_compat: '2.6.0 - 2.8.x',
      files_digest: `sha256:${fakeSha(`files-${edition}`).slice(0, 16)}…`,
    },
    validation: [
      { check: 'malware_scan', status: 'pass', detail: 'Malware scan clean · ZIP integrity (no zip-slip paths, no symlinks outside root)' },
      { check: 'manifest', status: 'pass', detail: `worksuite-package.json found · product worksuite-server · edition ${ed}` },
      { check: 'version', status: 'pass', detail: `Version ${version} / build 34012 matches release` },
      { check: 'file_hashes', status: 'pass', detail: '7,412 files · every SHA-256 matches manifest file list' },
      { check: 'forbidden_paths', status: 'pass', detail: 'Forbidden paths absent: .env writable/ public/uploads/ .git/' },
      { check: 'requirements', status: 'pass', detail: 'Requirements: PHP 8.2–8.4, ext intl, sodium, imap · MariaDB 10.6+ · 1.2 GB free' },
      { check: 'migrations', status: 'pass', detail: '27 migrations (25 reversible, 2 one-way) · rollback: code + DB restore' },
      { check: 'edition_content', status: 'pass', detail: `Edition content check: no higher-edition modules inside ${ed} package` },
      { check: 'attestation', status: 'pass', detail: `CI attestation matches ZIP SHA-256 ${fakeSha(ed).slice(0, 4)}…${fakeSha(ed).slice(-4)}` },
    ],
  };
}

function makePackages(app: AdminApp, version: string, channel: ChannelCode, opts: { missing?: string[]; msiReplaced?: boolean; serverStates?: Partial<Record<Edition, 'READY' | 'PROCESSING' | 'REJECTED' | 'EMPTY'>> } = {}): AdminPackage[] {
  const out: AdminPackage[] = [];
  if (app.kind === 'SERVER') {
    for (const ed of ['FREE', 'BUSINESS', 'ENTERPRISE'] as Edition[]) {
      const st = opts.serverStates?.[ed] ?? 'READY';
      const revs: Revision[] = [];
      if (st !== 'EMPTY') {
        if (ed === 'BUSINESS') revs.push({ ...makeRevision(app, version, channel, 'server', 'any', 'zip', ed, { n: 1, status: 'REPLACED', replace_reason: 'Missing inventory migration' }), });
        const r = makeRevision(app, version, channel, 'server', 'any', 'zip', ed, { n: ed === 'BUSINESS' ? 2 : 1, at: day(1) });
        if (st === 'PROCESSING') {
          r.status = 'PROCESSING';
          r.pipeline = r.pipeline.map((p, i) => (i < 4 ? p : i === 4 ? { ...p, status: 'running', detail: 'Manifest check 4/6' } : { ...p, status: 'pending', detail: null, at: null }));
          r.metadata = { package_json: serverMetadata(version, ed).package_json, validation: serverMetadata(version, ed).validation.map((v, i) => (i < 4 ? v : { ...v, status: 'pending' })) };
          r.signature_status = null;
        }
        revs.push(r);
      }
      out.push({
        id: newId('pkg'),
        platform: 'server',
        architecture: 'any',
        package_type: 'zip',
        edition: ed,
        scope: 'full',
        status: st === 'PROCESSING' ? 'PROCESSING' : st === 'EMPTY' ? 'EMPTY' : st,
        health: 'OK',
        minimum_os_version: 'PHP 8.2',
        active_revision: revs.find((r) => r.status !== 'REPLACED') ?? null,
        revisions: revs,
      });
    }
    return out;
  }
  for (const p of app.platforms) {
    for (const t of p.package_types) {
      const key = `${p.platform}/${p.architecture}/${t}`;
      if (opts.missing?.includes(key)) {
        out.push({ id: newId('pkg'), platform: p.platform, architecture: p.architecture, package_type: t, edition: null, scope: null, status: 'EMPTY', health: 'UNKNOWN', minimum_os_version: p.min_os_version, active_revision: null, revisions: [] });
        continue;
      }
      const revs: Revision[] = [];
      if (opts.msiReplaced && t === 'msi') {
        revs.push(makeRevision(app, version, channel, p.platform, p.architecture, t, null, { n: 1, status: 'REPLACED', at: day(5) }));
        revs.push(makeRevision(app, version, channel, p.platform, p.architecture, t, null, { n: 2, replace_reason: 'installer UI string fix', uploaded_by: 'N. Silva', at: day(4) }));
      } else revs.push(makeRevision(app, version, channel, p.platform, p.architecture, t, null, { n: 1 }));
      out.push({
        id: newId('pkg'),
        platform: p.platform,
        architecture: p.architecture,
        package_type: t,
        edition: null,
        scope: null,
        status: 'READY',
        health: 'OK',
        minimum_os_version: p.min_os_version,
        active_revision: revs[revs.length - 1],
        revisions: revs,
      });
    }
  }
  return out;
}

export function emptySections(): ChangelogSections {
  return { new_features: [], improvements: [], bug_fixes: [], performance: [], security: [], known_issues: [], deprecated: [], removed: [] };
}

const SECTION_TITLES: [keyof ChangelogSections, string][] = [
  ['new_features', 'New Features'],
  ['improvements', 'Improvements'],
  ['bug_fixes', 'Bug Fixes'],
  ['performance', 'Performance'],
  ['security', 'Security'],
  ['known_issues', 'Known Issues'],
  ['deprecated', 'Deprecated'],
  ['removed', 'Removed'],
];

export function sectionsToMarkdown(s: ChangelogSections): string {
  return SECTION_TITLES.filter(([k]) => s[k].length)
    .map(([k, title]) => `## ${title}\n${s[k].map((i) => `- ${i}`).join('\n')}`)
    .join('\n\n');
}

export function makeChangelog(summary: string | null, highlights: string[], partial: Partial<ChangelogSections>, updated: string | null = day(2)): Changelog {
  const sections = { ...emptySections(), ...partial };
  const markdown = sectionsToMarkdown(sections);
  return { locale: 'en', summary, highlights, sections, markdown, html: renderMarkdown(markdown), version: summary ? 2 : 0, updated_at: summary ? updated : null };
}

const approvals = (at: string): Approval[] => [
  { kind: 'QA', decision: 'PASS', platform: null, user: 'N. Perera', comment: 'Smoke + regression suites green on all platforms', created_at: at },
  { kind: 'RELEASE', decision: 'APPROVED', platform: null, user: 'R. Fernando', comment: null, created_at: at },
];

function rollout(status: Rollout['status'], stages: number[], stage_no: number, pct: number): Rollout {
  return {
    status,
    mode: stages.length > 1 ? 'STAGED' : 'FULL',
    stages,
    stage_no,
    percentage: pct,
    started_at: day(1),
    paused_at: status === 'PAUSED' ? ago(300) : null,
    completed_at: status === 'DONE' ? day(1) : null,
    auto_advance: true,
    auto_pause: true,
    thresholds: { dwell_hours: 24, min_success_rate: 97, max_failure_rate: 3, max_rollback_rate: 1 },
    rules: [],
    progress: stages.map((s, i) => ({
      stage: i + 1,
      percentage: s,
      eligible: Math.round(2481 * (s / 100)),
      updated: i + 1 < stage_no ? Math.round(2481 * (s / 100) * 0.98) : i + 1 === stage_no ? Math.round(2481 * (s / 100) * 0.62) : 0,
      failed: i + 1 <= stage_no ? Math.round(s * 0.3) : 0,
      rolled_back: i + 1 <= stage_no ? Math.round(s * 0.04) : 0,
      success_rate: i + 1 <= stage_no ? 98.6 : null,
    })),
  };
}

interface ReleaseSeed {
  app: string;
  version: string;
  build: number;
  channel?: ChannelCode;
  status: ReleaseStatus;
  type?: ReleaseType;
  support?: SupportState;
  date?: string;
  published?: string | null;
  min?: string | null;
  changelog?: Changelog;
  packagesOpts?: Parameters<typeof makePackages>[3];
  rollout?: Rollout | null;
  extra?: Partial<AdminReleaseDetail>;
}

const CL_270 = makeChangelog(
  'Offline account support, faster Mail folders and Meet screen-share fixes.',
  ['Desktop offline account support', 'Faster Mail folder loading and improved File Sync', 'Meet fullscreen and screen-share fixes'],
  {
    new_features: ['Desktop offline account support'],
    improvements: ['Faster Mail folder loading', 'Improved File Sync'],
    bug_fixes: ['Fixed Meet fullscreen issue', 'Fixed screen sharing layout'],
    security: ['Hardened update verification (manifest key rotation support)'],
    known_issues: ['[KNOWN ISSUE — none reported at release]'],
  },
);

const RELEASE_SEEDS: ReleaseSeed[] = [
  {
    app: 'app_desktop', version: '2.8.0-beta.2', build: 28002, channel: 'BETA', status: 'TESTING', type: 'OPTIONAL', min: '2.6.0-beta.2',
    changelog: makeChangelog('Unified inbox preview and a new Files side panel.', ['Unified inbox (preview)', 'Files side panel'], { new_features: ['Unified inbox (preview)', 'Files side panel'], improvements: ['Lower memory use on Chat'] }),
    packagesOpts: { missing: ['linux/x64/rpm'] },
    extra: { approvals: [] },
  },
  {
    app: 'app_desktop', version: '2.8.0-beta.1', build: 28001, channel: 'BETA', status: 'PUBLISHED', type: 'OPTIONAL', date: '2026-09-15', published: day(13), min: '2.6.0-beta.2',
    changelog: makeChangelog('First 2.8 preview.', ['Unified inbox (preview)'], { new_features: ['Unified inbox (preview)'] }),
    rollout: rollout('DONE', [100], 1, 100),
  },
  {
    app: 'app_desktop', version: '2.7.1', build: 27101, status: 'APPROVED', type: 'RECOMMENDED', date: '2026-09-29', min: '2.5.0',
    changelog: makeChangelog('Fixes an MSI upgrade issue on Windows ARM64 and a Sync regression on Linux AppImage.', ['Reliable MSI upgrades', 'Linux AppImage sync fix'], { bug_fixes: ['MSI 1603 when upgrading on Windows ARM64', 'Sync regression on Linux AppImage (UC-481)'] }),
    packagesOpts: { msiReplaced: true },
    extra: { approvals: approvals(ago(90)) },
  },
  {
    app: 'app_desktop', version: '2.7.0', build: 27001, status: 'PUBLISHED', type: 'RECOMMENDED', date: '2026-09-27', published: day(1, 4), min: '2.5.0',
    changelog: CL_270, packagesOpts: { msiReplaced: true }, rollout: rollout('ACTIVE', [5, 25, 50, 100], 3, 50),
    extra: { approvals: approvals(day(1, 6)) },
  },
  {
    app: 'app_desktop', version: '2.6.5', build: 26505, status: 'SUPERSEDED', support: 'SUPPORTED', date: '2026-08-12', published: day(47), min: '2.4.0',
    changelog: makeChangelog('Stability fixes for Chat and Calendar.', ['Chat stability'], { bug_fixes: ['Chat reconnect loop after sleep', 'Calendar reminder duplicates'] }),
  },
  {
    app: 'app_desktop', version: '2.6.4', build: 26404, status: 'SUPERSEDED', support: 'DEPRECATED', date: '2026-07-20', published: day(70), min: '2.4.0',
    changelog: makeChangelog('Maintenance release.', [], { improvements: ['Smaller installer'], bug_fixes: ['Proxy auto-config detection'] }),
  },
  {
    app: 'app_desktop', version: '2.5.3', build: 25303, status: 'SUPERSEDED', support: 'DEPRECATED', date: '2026-05-02', published: day(149), min: '2.3.0',
    changelog: makeChangelog('Meet background blur.', [], { new_features: ['Meet background blur'] }),
  },
  {
    app: 'app_desktop', version: '2.4.9', build: 24909, status: 'SUPERSEDED', support: 'UNSUPPORTED', date: '2026-02-10', published: day(230), min: '2.2.0',
    changelog: makeChangelog('Last 2.4 maintenance release.', [], { bug_fixes: ['Tray icon on Linux'] }),
  },
  {
    app: 'app_installer', version: '1.3.3', build: 1303, status: 'DRAFT', type: 'OPTIONAL', min: null,
    changelog: makeChangelog(null, [], {}, null), packagesOpts: { missing: ['macos/universal/pkg', 'windows/x64/msi'] },
  },
  {
    app: 'app_installer', version: '1.3.2', build: 1302, status: 'PUBLISHED', date: '2026-09-02', published: day(26), min: '1.2.0',
    changelog: makeChangelog('Silent install switches for Intune.', ['Silent install for Intune'], { improvements: ['Silent install switches for Intune'] }), rollout: rollout('DONE', [100], 1, 100),
  },
  {
    app: 'app_quicksupport', version: '1.1.0', build: 1100, status: 'PUBLISHED', date: '2026-09-10', published: day(18), min: '1.0.0',
    changelog: makeChangelog('Session recording consent prompt.', ['Consent prompt'], { new_features: ['Session recording consent prompt'] }), rollout: rollout('DONE', [100], 1, 100),
  },
  {
    app: 'app_quicksupport', version: '1.1.1', build: 1101, status: 'WITHDRAWN', date: '2026-09-20', published: day(8), min: '1.0.0',
    changelog: makeChangelog('Clipboard sync.', [], { new_features: ['Clipboard sync'] }),
    extra: { withdrawn_at: day(7), withdraw_reason: 'Crash on macOS 12 when clipboard contains images (QS-112)' },
  },
  {
    app: 'app_syncagent', version: '0.9.4', build: 904, status: 'PUBLISHED', date: '2026-08-30', published: day(29), min: '0.8.0',
    changelog: makeChangelog('Delta sync for large files.', ['Delta sync'], { improvements: ['Delta sync for files over 1 GB'] }), rollout: rollout('DONE', [100], 1, 100),
  },
  {
    app: 'app_syncagent', version: '0.9.5', build: 905, status: 'PAUSED', date: '2026-09-24', published: day(4), min: '0.8.0',
    changelog: makeChangelog('Selective sync.', [], { new_features: ['Selective sync'] }), rollout: rollout('PAUSED', [5, 25, 50, 100], 2, 25),
    extra: { last_error: 'Auto-paused: update failure rate 4.1% above 3% threshold' },
  },
  {
    app: 'app_server', version: '3.4.1', build: 34101, status: 'DRAFT', type: 'RECOMMENDED', min: '3.2.0',
    changelog: makeChangelog(null, [], {}, null),
    packagesOpts: { serverStates: { BUSINESS: 'EMPTY', ENTERPRISE: 'EMPTY' } },
  },
  {
    app: 'app_server', version: '3.4.0', build: 34012, status: 'APPROVED', type: 'RECOMMENDED', date: '2026-09-30', min: '3.2.0',
    changelog: makeChangelog('Desktop catalog sync, Finance ledger split and a faster Mail folder view.', ['Desktop catalog sync', 'Finance ledger split'], { new_features: ['Desktop catalog sync', 'Finance ledger split'], improvements: ['Mail folder list 2× faster'], security: ['Stricter session cookie flags'] }),
    packagesOpts: { serverStates: { ENTERPRISE: 'PROCESSING' } },
    extra: { approvals: approvals(ago(200)) },
  },
  {
    app: 'app_server', version: '3.3.2', build: 33204, status: 'PUBLISHED', date: '2026-09-01', published: day(27), min: '3.1.0',
    changelog: makeChangelog('Security fixes.', ['Security fixes'], { security: ['Patched CSRF bypass in legacy forms'] }),
    packagesOpts: {}, rollout: rollout('DONE', [100], 1, 100),
  },
];

function makeRelease(apps: AdminApp[], s: ReleaseSeed): AdminReleaseDetail {
  const app = apps.find((a) => a.id === s.app)!;
  const channel = s.channel ?? 'STABLE';
  const packages = makePackages(app, s.version, channel, s.packagesOpts);
  const created = s.published ?? day(3);
  const isServer = app.kind === 'SERVER';
  const rel: AdminReleaseDetail = {
    id: `rel_${app.slug.replace(/-/g, '')}_${s.version.replace(/[^0-9a-z]/gi, '')}`,
    app: { id: app.id, slug: app.slug, name: app.name, kind: app.kind },
    version: s.version,
    build: s.build,
    build_revision: s.packagesOpts?.msiReplaced ? `${s.build}.1` : null,
    channel,
    release_type: s.type ?? 'RECOMMENDED',
    status: s.status,
    support_state: s.support ?? 'SUPPORTED',
    visibility: 'PUBLIC',
    minimum_supported_version: s.min ?? null,
    minimum_no_change: s.min == null,
    mandatory: false,
    mandatory_after: null,
    grace_minutes: null,
    rollback_supported: true,
    release_date: s.date ?? null,
    scheduled_publish_at: null,
    published_at: s.published ?? null,
    published_by: s.published ? 'R. Fernando' : null,
    created_by: 'CI token (github-actions)',
    withdrawn_at: null,
    withdraw_reason: null,
    superseded_by: null,
    notes: null,
    known_issues: null,
    auto_pause_on_gate: true,
    last_error: null,
    created_at: created,
    updated_at: created,
    packages_ready: packages.filter((p) => p.status === 'READY').length,
    packages_total: packages.length,
    packages,
    changelog: s.changelog ?? makeChangelog(null, [], {}, null),
    checklist: { passed: false, items: [] },
    approvals: s.published ? approvals(s.published) : [],
    rollout: s.rollout ?? null,
    manifest: null,
    server_meta: isServer
      ? {
          min_from_version: '3.2.0',
          requires: { php: '>=8.2 <8.5', db: 'mariadb>=10.6|mysql>=8.0', ext: ['intl', 'sodium', 'imap', 'mbstring', 'gd'], disk_mb: 1200 },
          migrations: { count: 27, one_way: ['2026-09-25-120000_CreateDesktop', '2026-09-26-090000_SplitLedger'] },
          desktop_compat: '2.6.0 - 2.8.x',
          downtime_estimate_s: 240,
          staging_test: { status: 'PASSED', editions: { FREE: 'PASSED', BUSINESS: 'PASSED', ENTERPRISE: 'PENDING' } },
          admin_notice: 'WorkSuite 3.4.0 adds Desktop catalog sync and the Finance ledger split. Back up before updating; expected downtime ~4 minutes.',
          advisory: null,
        }
      : null,
    campaign: null,
    allowed_actions: [],
    ...s.extra,
  };
  if (isServer) rel.campaign = defaultCampaign();
  return rel;
}

export function defaultCampaign(): Campaign {
  return {
    audience: { editions: ['FREE', 'BUSINESS', 'ENTERPRISE'], channels: ['STABLE'], version_range: '>=3.2.0 <3.4.0', modes: ['MANAGED', 'SELF_HOSTED'] },
    urgency: 'RECOMMENDED',
    deadline: null,
    rollout_pct: 25,
    channels: ['webhook', 'in_app', 'email'],
    fleet_window: 'Sun 02:00–04:00 LKT',
    fleet_stages: [25, 100],
    status: 'DRAFT',
    sent_count: 0,
    eligible_count: 412,
    fleet_status: 'NOT_STARTED',
    fleet_stage_no: null,
  };
}

// ---------------------------------------------------------------- users, clients, audit

export interface MockUser extends AdminUser {
  password: string;
  passkeys: number;
  beta_opt_in: boolean;
}

export const USERS: MockUser[] = [
  { id: 'usr_rfernando', email: 'r.fernando@livezen.lk', name: 'R. Fernando', kind: 'STAFF', status: 'ACTIVE', roles: [SA, RM], mfa_enabled: true, last_login_at: ago(20), created_at: day(400), password: 'x', passkeys: 1, beta_opt_in: true },
  { id: 'usr_nperera', email: 'n.perera@livezen.lk', name: 'N. Perera', kind: 'STAFF', status: 'ACTIVE', roles: [QA], mfa_enabled: true, last_login_at: ago(95), created_at: day(380), password: 'x', passkeys: 0, beta_opt_in: false },
  { id: 'usr_nsilva', email: 'n.silva@livezen.lk', name: 'N. Silva', kind: 'STAFF', status: 'ACTIVE', roles: [DEV], mfa_enabled: true, last_login_at: day(1), created_at: day(300), password: 'x', passkeys: 2, beta_opt_in: true },
  { id: 'usr_kasun', email: 'kasun.s@livezen.lk', name: 'Kasun S.', kind: 'STAFF', status: 'ACTIVE', roles: [DEV], mfa_enabled: true, last_login_at: ago(300), created_at: day(200), password: 'x', passkeys: 0, beta_opt_in: false },
  { id: 'usr_dilini', email: 'dilini.w@livezen.lk', name: 'Dilini W.', kind: 'STAFF', status: 'ACTIVE', roles: [CM], mfa_enabled: true, last_login_at: day(3), created_at: day(150), password: 'x', passkeys: 0, beta_opt_in: false },
  { id: 'usr_viewer', email: 'viewer@livezen.lk', name: 'Audit Viewer', kind: 'STAFF', status: 'ACTIVE', roles: [VW], mfa_enabled: true, last_login_at: day(12), created_at: day(90), password: 'x', passkeys: 0, beta_opt_in: false },
  { id: 'usr_old', email: 'former.contractor@livezen.lk', name: 'Former Contractor', kind: 'STAFF', status: 'DISABLED', roles: [DEV], mfa_enabled: false, last_login_at: day(120), created_at: day(360), password: 'x', passkeys: 0, beta_opt_in: false },
  { id: 'usr_portal', email: 'amali@acme.lk', name: 'Amali Jayasinghe', kind: 'PORTAL', status: 'ACTIVE', roles: ['portal_user', 'beta_tester'], mfa_enabled: false, last_login_at: day(2), created_at: day(60), password: 'x', passkeys: 0, beta_opt_in: true },
];

export const API_CLIENTS: ApiClient[] = [
  { id: 'cli_github', name: 'GitHub Actions — desktop', kind: 'CI', client_id: 'uc_ci_7f2a91', permissions: ['release.view', 'release.create', 'release.upload', 'release.file.upload', 'changelog.manage'], ip_allowlist: [], expires_at: day(-180), last_used_at: ago(120), rotated_at: day(40), revoked_at: null, created_at: day(300) },
  { id: 'cli_server', name: 'GitHub Actions — server', kind: 'CI', client_id: 'uc_ci_33b0e4', permissions: ['release.view', 'release.create', 'release.upload', 'release.file.upload', 'changelog.manage', 'bugtrace.symbols.upload'], ip_allowlist: ['140.82.112.0/20'], expires_at: day(-90), last_used_at: day(1), rotated_at: null, revoked_at: null, created_at: day(200) },
  { id: 'cli_old', name: 'Jenkins (legacy)', kind: 'CI', client_id: 'uc_ci_0019aa', permissions: ['release.view'], ip_allowlist: ['10.2.0.0/16'], expires_at: day(10), last_used_at: day(90), rotated_at: null, revoked_at: day(30), created_at: day(500) },
];

export function audit(id: number, actor: string, action: string, entity: string | null, entityId: string | null, minutesAgo: number, reason: string | null = null, before: unknown = null, after: unknown = null): AuditEntry {
  return {
    id,
    actor_type: actor.startsWith('CI') ? 'api_client' : 'user',
    actor_id: null,
    actor_label: actor,
    action,
    entity_type: entity,
    entity_id: entityId,
    before,
    after,
    reason,
    ip: actor.startsWith('CI') ? '140.82.114.3' : '203.94.72.18',
    created_at: ago(minutesAgo),
    hash: fakeSha(`audit-${id}`),
  };
}

export const AUDIT: AuditEntry[] = [
  audit(1042, 'R. Fernando', 'rollout.increase', 'release', 'rel_desktop_270', 68, 'Stage 2 healthy for 24 h', { percentage: 25 }, { percentage: 50 }),
  audit(1041, 'CI token (github-actions)', 'package.uploaded', 'release', 'rel_desktop_280beta2', 158, null, null, { packages: 8 }),
  audit(1040, 'N. Perera', 'release.start_testing', 'release', 'rel_desktop_280beta2', 200),
  audit(1039, 'N. Perera', 'release.qa', 'release', 'rel_desktop_271', 240, null, null, { decision: 'PASS' }),
  audit(1038, 'R. Fernando', 'release.approve', 'release', 'rel_desktop_271', 230),
  audit(1037, 'System', 'rollout.auto_pause', 'release', 'rel_syncagent_095', 300, 'Update failure rate 4.1% above 3% threshold'),
  audit(1036, 'N. Silva', 'package.replaced', 'release', 'rel_desktop_271', 5700, 'installer UI string fix', { sha256: fakeSha('a') }, { sha256: fakeSha('b') }),
  audit(1035, 'R. Fernando', 'release.publish', 'release', 'rel_desktop_270', 1680),
  audit(1034, 'R. Fernando', 'release.withdraw', 'release', 'rel_quicksupport_111', 10080, 'Crash on macOS 12 when clipboard contains images (QS-112)'),
  audit(1033, 'Dilini W.', 'download_page.publish', 'application', 'app_desktop', 12000),
  audit(1032, 'R. Fernando', 'minimum_version.change', 'application', 'app_desktop', 14000, 'Drop 2.4 support', { minimum_version: '2.4.0' }, { minimum_version: '2.5.0' }),
  audit(1031, 'R. Fernando', 'user.login', 'user', 'usr_rfernando', 20),
];

export const SIGNING_KEYS: SigningKey[] = [
  { key_id: 'ws-release-2026a', purpose: 'release', algorithm: 'Ed25519', public_key: 'MCowBQYDK2VwAyEA3q1k8y1bM1Zp0VqZ9s1YtF2c7rGq0mHhJ5fFz2v9d7E=', status: 'ACTIVE', created_at: day(270), rotated_at: null, retire_after: null },
  { key_id: 'ws-response-2026a', purpose: 'update-response', algorithm: 'Ed25519', public_key: 'MCowBQYDK2VwAyEAbX2c9Qm7kqV0p3sWJ1n4l8y5Rr0tGz6hUe2Xa1Kd9Pw=', status: 'ACTIVE', created_at: day(270), rotated_at: null, retire_after: null },
  { key_id: 'ws-release-2025b', purpose: 'release', algorithm: 'Ed25519', public_key: 'MCowBQYDK2VwAyEAq9Tn2m1Ls0Kc8vY3eRb5Wx7Fj4Hp6Dz1Ag0Nu3Io2Qe=', status: 'RETIRING', created_at: day(640), rotated_at: day(270), retire_after: day(-95) },
];

export const STORAGE: StorageOverview = {
  providers: [
    { id: 'stp_r2', name: 'Cloudflare R2', kind: 'r2', bucket: 'worksuite-releases', region: 'auto (APAC)', endpoint: 'https://<account>.r2.cloudflarestorage.com', cdn_host: 'cdn-updates.worksuitecloud.com', is_active: true, versioning: true, replication: 'S3 ap-south-1', status: 'OK', status_detail: 'OK · 41 ms', last_check_at: ago(1), usage_bytes: 214_000_000_000, object_count: 311, credentials: 'set', credentials_rotated_at: '2026-09-02T00:00:00Z', release_count: 37 },
    { id: 'stp_s3', name: 'AWS S3 (replica)', kind: 's3', bucket: 'worksuite-releases-replica', region: 'ap-south-1', endpoint: null, cdn_host: null, is_active: false, versioning: true, replication: null, status: 'OK', status_detail: 'OK · 88 ms', last_check_at: ago(1), usage_bytes: 213_600_000_000, object_count: 309, credentials: 'set', credentials_rotated_at: '2026-07-11T00:00:00Z', release_count: 37 },
  ],
  cdn: { host: 'cdn-updates.worksuitecloud.com', url_lifetime_s: 900, signing: 'hmac', purge: 'cloudflare', status: 'HEALTHY', last_purge_at: '2026-09-27T11:20:00Z', hit_ratio: 97.8, egress_30d_bytes: 5_100_000_000_000 },
  distribution: { degraded_packages: [] },
  migrations: [],
};

// ---------------------------------------------------------------- server installations

const NAMES = ['acme.lk', 'ceylon-tea-exports', 'colombo-dental', 'harbour-logistics', 'kandy-hotels', 'lanka-textiles', 'galle-fort-resorts', 'jaffna-traders', 'moratuwa-furniture', 'negombo-seafoods', 'app.worksuite.lk', 'nuwara-estates'];
export const INSTALLATIONS: Installation[] = NAMES.map((n, i) => {
  const ed = (['FREE', 'BUSINESS', 'ENTERPRISE'] as Edition[])[i % 3];
  const version = ['3.3.2', '3.3.2', '3.3.1', '3.2.4', '3.3.2', '3.1.9'][i % 6];
  return {
    installation_id: `ins_${fakeSha(n).slice(0, 10)}`,
    name: n,
    edition: ed,
    version,
    build: Number(version.replace(/\./g, '')) * 100 + 4,
    channel: 'STABLE',
    mode: i === 10 ? 'MANAGED' : i % 5 === 4 ? 'AIR_GAPPED' : i % 2 ? 'MANAGED' : 'SELF_HOSTED',
    support_state: version === '3.1.9' ? 'UNSUPPORTED' : version === '3.2.4' ? 'DEPRECATED' : 'SUPPORTED',
    last_report_at: ago(30 + i * 47),
    last_run_status: i === 3 ? 'ROLLED_BACK' : i === 7 ? 'FAILED' : 'SUCCEEDED',
    php_version: ['8.2.24', '8.3.12', '8.3.11'][i % 3],
    db_version: ['MariaDB 10.11', 'MySQL 8.0.39', 'MariaDB 10.6'][i % 3],
  };
});

// ---------------------------------------------------------------- bug trace

function sparkline(seed: number, peak: number): number[] {
  return Array.from({ length: 24 }, (_, i) => Math.max(0, Math.round((Math.sin(i / 3 + seed) + 1) * peak * (i > 18 ? 1.8 : 0.6))));
}

const WEB_FRAMES = [
  {
    file: 'web/src/pages/mail/MailFolderList.tsx',
    function: 'MailFolderList.renderFolder',
    line: 142,
    col: 38,
    in_app: true,
    context: {
      pre: ['const current = folders.find(f => f.id === activeId);', 'const unread = counts[current.id] ?? 0;'],
      line: 'const parent = byId[current.parent].folderId;',
      post: ['return <FolderRow folder={current} … />'],
    },
  },
  { file: 'MailFolderList.tsx', function: 'MailFolderList', line: 98, col: 12, in_app: true },
  { file: 'pages/mail/MailPage.tsx', function: 'MailPage', line: 61, col: 5, in_app: true },
  { file: 'components/ErrorBoundary.tsx', function: 'ErrorBoundary(area="Mail")', line: 22, col: 9, in_app: true },
  { file: 'node_modules/react-dom/cjs/react-dom.production.js', function: 'renderWithHooks', line: 5241, col: 22, in_app: false },
];

function issue(p: Partial<IssueDetail> & Pick<IssueDetail, 'id' | 'title' | 'culprit' | 'type' | 'status' | 'priority'>, platform: string, projectName: string): IssueDetail {
  return {
    project: { id: `prj_${platform}`, name: projectName, platform },
    resolution: null,
    resolve_version: null,
    assignee: null,
    first_seen: ago(180),
    last_seen: ago(3),
    first_release: '3.4.0+34012',
    last_release: '3.4.0+34012',
    events_count: 100,
    users_count: 10,
    installations_count: 3,
    labels: [],
    sparkline: sparkline(p.id.length, 4),
    latest_event: { id: newId('evt'), timestamp: ago(3), message: p.title },
    frames: WEB_FRAMES,
    breadcrumbs: [
      { timestamp: '09:41:02', category: 'navigation', message: '/app/mail/inbox' },
      { timestamp: '09:41:03', category: 'http', message: 'GET /api/mail/folders 200 · 184 ms' },
      { timestamp: '09:41:09', category: 'ui.click', message: 'Folder "Shared/Finance"' },
      { timestamp: '09:41:09', category: 'http', message: 'GET /api/mail/folders/88/messages 200 · 402 ms' },
      { timestamp: '09:41:10', category: 'error', message: 'TypeError in MailFolderList · boundary showed "Mail couldn’t load"', level: 'error' },
    ],
    tags: { browser: 'Chrome 129', os: 'Windows 11', edition: 'Business', area: 'Mail', route: '/app/mail/:folder' },
    distributions: {
      os: [{ name: 'Windows 11', count: 62 }, { name: 'macOS 15', count: 31 }, { name: 'Ubuntu 24.04', count: 7 }],
      browser: [{ name: 'Chrome 129', count: 71 }, { name: 'Edge 18', count: 18 }, { name: 'Firefox 131', count: 11 }],
      release: [{ name: '3.4.0+34012', count: 100 }],
      edition: [{ name: 'Business', count: 64 }, { name: 'Enterprise', count: 36 }],
    },
    releases: [{ release: '3.4.0+34012', events: 1204, first_seen: ago(180), last_seen: ago(3) }],
    hourly: Array.from({ length: 7 * 24 }, (_, i) => ({ hour: ago((7 * 24 - i) * 60), events: i > 150 ? 20 + ((i * 37) % 60) : i > 140 ? (i * 7) % 9 : 0 })),
    reports: [],
    comments: [],
    links: [],
    scrubbed_fields: 3,
    tenant_count: 9,
    ...p,
  } as IssueDetail;
}

export const ISSUES: IssueDetail[] = [
  issue({ id: 'WS-WEB-4Q1', type: 'crash', title: "TypeError: Cannot read properties of undefined (reading 'folderId')", culprit: 'MailFolderList.tsx:142 · mail · crash in ErrorBoundary "Mail"', status: 'NEW', priority: 'P1', events_count: 1204, users_count: 311, installations_count: 9, first_seen: ago(120), last_seen: ago(3), labels: ['mail', 'regression-candidate'], sparkline: sparkline(1, 9) }, 'web', 'WorkSuite Web'),
  issue({ id: 'WS-DSK-1B7', type: 'crash', title: 'EXC_BAD_ACCESS in libsqlite3 · SQLCipher page read', culprit: 'offline.db · DesktopOfflineService.flush()', status: 'REGRESSED', priority: 'P1', events_count: 87, users_count: 54, installations_count: 0, first_seen: day(9), last_seen: ago(11), first_release: '2.6.5', last_release: '2.7.0', frames: [{ file: 'src/main/offline/DesktopOfflineService.ts', function: 'DesktopOfflineService.flush', line: 211, col: 14, in_app: true, context: { pre: ['const batch = this.queue.drain(500);', 'await this.db.begin();'], line: 'await this.db.run(SQL_INSERT, batch);', post: ['await this.db.commit();'] } }, { file: 'libsqlite3.dylib', function: 'sqlite3PagerGet', line: null, col: null, in_app: false }], resolve_version: '2.6.5' }, 'desktop', 'WorkSuite Desktop'),
  issue({ id: 'WS-MOB-2K7', type: 'hang', title: 'ANR · Main thread blocked 6.2 s in ChatListScreen', culprit: 'com.livezentech.worksuite · Android 14', status: 'TRIAGED', priority: 'P2', events_count: 642, users_count: 198, installations_count: 0, first_seen: day(4), last_seen: ago(40), first_release: '1.3.2', last_release: '1.3.2', assignee: { id: 'usr_kasun', name: 'Kasun S.' } }, 'mobile', 'WorkSuite Mobile'),
  issue({ id: 'WS-SRV-0M3', type: 'error', title: 'mysqli_sql_exception: Deadlock found when trying to get lock', culprit: 'Finance\\Libraries\\Posting.php:318 · spark relay work', status: 'TRIAGED', priority: 'P2', events_count: 96, users_count: 0, installations_count: 12, first_seen: day(2), last_seen: ago(60), first_release: '3.3.2', last_release: '3.3.2' }, 'server', 'WorkSuite Server'),
  issue({ id: 'WS-DSK-1C2', type: 'error', title: 'UPDATE_INSTALL_FAILED · MSI 1603 on Windows ARM64', culprit: 'worksuite-updater · linked to rollout 2.7.0 stage 3', status: 'NEW', priority: 'P1', events_count: 36, users_count: 36, installations_count: 0, first_seen: day(1), last_seen: ago(120), first_release: '2.7.0', last_release: '2.7.0' }, 'desktop', 'WorkSuite Desktop'),
  issue({ id: 'WS-WEB-3Z8', type: 'error', title: 'ChunkLoadError: Loading chunk 812 failed', culprit: 'after deploy → auto-reload handled', status: 'RESOLVED', resolution: 'VERSION', resolve_version: '3.4.0', priority: 'P3', events_count: 2410, users_count: 884, installations_count: 40, first_seen: day(12), last_seen: day(5), first_release: '3.3.1', last_release: '3.3.2' }, 'web', 'WorkSuite Web'),
];

function report(p: Partial<ReportDetail> & Pick<ReportDetail, 'reference' | 'title' | 'app' | 'release'>, minutes: number): ReportDetail {
  return {
    severity: 'ANNOYING',
    category: 'BUG',
    status: 'UNTRIAGED',
    created_at: ago(minutes),
    has_screenshot: false,
    suggested_issue: null,
    issue: null,
    description: '',
    steps: [],
    expected: null,
    context: {},
    attachments: [],
    updates: [],
    contact: null,
    ...p,
  };
}

export const REPORTS: ReportDetail[] = [
  report({
    reference: 'BR-20931', title: 'Mail page crashed when opening Finance folder', app: 'Web', release: '3.4.0+34012', severity: 'BLOCKS_WORK', has_screenshot: true,
    suggested_issue: { id: 'WS-WEB-4Q1', title: "TypeError: Cannot read properties of undefined (reading 'folderId')" },
    description: 'I clicked the shared Finance folder and the whole Mail area turned into an error box. Other folders open fine.',
    steps: ['Mail › Shared › Finance', 'Error appears immediately'], expected: 'Folder messages list.',
    context: { app: 'Web · 3.4.0+34012', edition: 'Business', browser: 'Chrome 129 · Windows 11', route: '/app/mail/…', last_error: 'WS-WEB-4Q1', reporter: 'consented to contact', severity: 'Blocks my work' },
    attachments: [{ id: 'att_1', kind: 'screenshot', url: '', meta: { annotated: true, redacted_regions: 1 } }],
    contact: { email: 'l.perera@acme.lk' },
  }, 12),
  report({ reference: 'BR-20929', title: 'Attendance check-in button does nothing', app: 'Mobile', release: '1.3.2', severity: 'BLOCKS_WORK', description: 'Tapping “Check in” shows a spinner then nothing happens.', steps: ['HR › Attendance', 'Tap Check in'], expected: 'Checked-in confirmation', context: { app: 'Mobile · 1.3.2', os: 'Android 14', edition: 'Business' } }, 40),
  report({ reference: 'BR-20925', title: 'Files stuck “Syncing 3 items” after update', app: 'Desktop', release: '2.7.0', has_screenshot: false, description: 'Since updating to 2.7.0 three files never finish syncing.', steps: ['Update to 2.7.0', 'Open Files'], expected: 'Sync completes', context: { app: 'Desktop · 2.7.0', os: 'macOS 15', logs: 'attached (consented)' }, attachments: [{ id: 'att_2', kind: 'logs', url: '', meta: { consented: true } }] }, 60),
  report({ reference: 'BR-20921', title: 'Invoice PDF shows wrong currency symbol (LKR)', app: 'Web', release: '3.3.2', category: 'BUG', description: 'Invoices print “$” instead of “Rs”.', steps: ['Finance › Invoices › Print'], expected: 'LKR symbol', context: { app: 'Web · 3.3.2', edition: 'Enterprise' } }, 180),
  report({ reference: 'BR-20918', title: 'Meet screen share black on second monitor', app: 'Desktop', release: '2.7.0', description: 'Sharing my second screen shows black to others.', steps: ['Start a meeting', 'Share screen 2'], expected: 'Screen visible', context: { app: 'Desktop · 2.7.0', os: 'Windows 11' } }, 300),
  report({ reference: 'BR-20915', title: 'App closes when attaching photo to task', app: 'Mobile', release: '1.3.2', suggested_issue: { id: 'WS-MOB-2K7', title: 'ANR · Main thread blocked 6.2 s in ChatListScreen' }, description: 'The app closes when I pick a photo.', steps: ['Tasks › New', 'Attach › Photo'], expected: 'Photo attached', context: { app: 'Mobile · 1.3.2', os: 'iOS 18' } }, 360),
  report({ reference: 'BR-20901', title: 'Dark mode for the calendar', app: 'Web', release: '3.3.2', category: 'FEATURE_REQUEST', status: 'FEATURE_REQUEST', description: 'Please add dark mode to Calendar.', context: {} }, 3000),
  report({ reference: 'BR-20877', title: 'Chat reconnect loop after sleep', app: 'Desktop', release: '2.6.4', status: 'LINKED', issue: { id: 'WS-DSK-1B7', title: 'EXC_BAD_ACCESS in libsqlite3 · SQLCipher page read' }, description: 'Chat keeps reconnecting after waking my laptop.', context: {} }, 9000),
  report({ reference: 'BR-20850', title: 'Typo on login page', app: 'Web', release: '3.3.1', status: 'CLOSED', description: '“Pasword”.', context: {}, updates: [{ at: ago(8000), message: 'Fixed in 3.3.2 — thanks!', public: true, by: 'Support' }] }, 12000),
];
ISSUES[0].reports = [REPORTS[0]];

export const ALERTS: BugAlert[] = [
  { id: 'alr_default_new_crash', name: 'New crash issue', condition: 'new_issue', threshold: { types: ['crash', 'hang'] }, channels: ['in_app', 'email'], enabled: true, digest: false, quiet_hours: null, project: null },
  { id: 'alr_2', name: 'Crash-free users below 99%', condition: 'crash_free_below', threshold: { value: 99 }, channels: ['in_app', 'email', 'webhook'], enabled: true, digest: false, quiet_hours: '22:00-07:00', project: null },
  { id: 'alr_default_regression', name: 'Regression', condition: 'regression', threshold: {}, channels: ['in_app', 'email'], enabled: true, digest: false, quiet_hours: null, project: null },
  { id: 'alr_default_spike', name: 'Events spike', condition: 'spike', threshold: { multiplier: 5, min_events: 20 }, channels: ['in_app'], enabled: false, digest: true, quiet_hours: null, project: 'btp_dsk' },
];

// ---------------------------------------------------------------- build initial state

export function buildInitialState() {
  const apps = APP_SEEDS.map(adminAppFromSeed);
  const releases = RELEASE_SEEDS.map((s) => makeRelease(apps, s));
  const pages: Record<string, { published: DownloadPage; draft: DownloadPage | null; history: { version: number; updated_by: string | null; created_at: string }[] }> = {};
  for (const s of APP_SEEDS) {
    pages[s.id] = {
      published: pageFor(s),
      draft: null,
      history: [
        { version: 3, updated_by: 'Dilini W.', created_at: day(8) },
        { version: 2, updated_by: 'Dilini W.', created_at: day(40) },
        { version: 1, updated_by: 'R. Fernando', created_at: day(200) },
      ],
    };
  }
  const minimums: Record<string, { channel: ChannelCode; platform: Platform | null; minimum_version: string; enforce_from: string | null; message: string | null; updated_at: string | null }[]> = {
    app_desktop: [
      { channel: 'STABLE', platform: null, minimum_version: '2.5.0', enforce_from: '2026-09-01T00:00:00Z', message: 'Please update WorkSuite Desktop to keep receiving security fixes.', updated_at: day(27) },
      { channel: 'BETA', platform: null, minimum_version: '2.6.0-beta.2', enforce_from: null, message: null, updated_at: day(40) },
    ],
  };
  return {
    apps,
    releases,
    pages,
    minimums,
    minimumHistory: {
      app_desktop: [{ channel: 'STABLE' as ChannelCode, platform: null, minimum_version: '2.5.0', before_version: '2.4.0', enforce_from: '2026-09-01T00:00:00Z', message: null, reason: 'Drop 2.4 support', updated_by: 'R. Fernando', updated_at: day(27), created_at: day(27) }],
    } as Record<string, unknown[]>,
    channels: structuredClone(CHANNELS),
    users: structuredClone(USERS),
    apiClients: structuredClone(API_CLIENTS),
    audit: structuredClone(AUDIT),
    signingKeys: structuredClone(SIGNING_KEYS),
    storage: structuredClone(STORAGE),
    installations: structuredClone(INSTALLATIONS),
    issues: structuredClone(ISSUES),
    reports: structuredClone(REPORTS),
    alerts: structuredClone(ALERTS),
    notifications: [
      { id: 'ntf_1', kind: 'rollout.auto_paused', payload: { title: 'Sync Agent 0.9.5 rollout auto-paused', body: 'Update failure rate 4.1% exceeded the 3% threshold at stage 2.', link: '/admin/releases/rel_syncagent_095/rollout' }, read_at: null, created_at: ago(300) },
      { id: 'ntf_2', kind: 'release.approved', payload: { title: 'Desktop 2.7.1 approved', body: 'Ready to publish on Stable.', link: '/admin/releases/rel_desktop_271' }, read_at: null, created_at: ago(230) },
      { id: 'ntf_3', kind: 'bugtrace.regression', payload: { title: 'Regression: WS-DSK-1B7', body: 'EXC_BAD_ACCESS in libsqlite3 reappeared in 2.7.0.', link: '/admin/bugtrace/issues/WS-DSK-1B7' }, read_at: null, created_at: ago(660) },
      { id: 'ntf_4', kind: 'package.ready', payload: { title: '8 packages ready for 2.8.0-beta.2', body: 'Uploaded by CI token (github-actions).' }, read_at: ago(100), created_at: ago(158) },
    ],
    settings: { cdn_url_lifetime_s: 900, notify_chat_webhook_set: true, default_rollout_stages: [5, 25, 50, 100], download_bot_filter: true, session_idle_minutes: 30, step_up_minutes: 10, public_site_name: 'WorkSuite Downloads' } as Record<string, unknown>,
    subscriptions: [
      { app: 'desktop', channel: 'STABLE' as ChannelCode, enabled: true },
      { app: 'desktop', channel: 'BETA' as ChannelCode, enabled: true },
      { app: 'server', channel: 'STABLE' as ChannelCode, enabled: false },
    ],
    uploads: {} as Record<string, { releaseId: string; pkgId: string; size: number; offset: number; fileName: string; created: boolean; failedOnce: boolean }>,
  };
}

export type MockState = ReturnType<typeof buildInitialState>;
