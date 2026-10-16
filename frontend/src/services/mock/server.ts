/**
 * In-browser mock implementation of the Update Center API (docs/api-contract.md).
 * Enabled with VITE_API_MOCK=true. State is in memory (reset on reload); the session survives reloads
 * via sessionStorage. Login: any @livezen.lk address → staff (MFA with any 6 digits, except 000000),
 * other addresses → portal user. "viewer@livezen.lk" gets the read-only Viewer role.
 */
import { compareSemver, isSemver } from '@/core/semver';
import type {
  AdminApp,
  AdminPackage,
  AdminReleaseDetail,
  AppSummary,
  Changelog,
  ChangelogSections,
  Checklist,
  ChecklistItem,
  Edition,
  GateSignal,
  Installation,
  ManifestInfo,
  Me,
  PreviousVersion,
  PublicPackage,
  PublicRelease,
  PublicReleaseDetail,
  ReleaseAction,
  Revision,
  RingState,
  ServerRollout,
  ServerSeverity,
} from '@/types/api';
import {
  PERMISSIONS,
  ROLES,
  ago,
  audit,
  buildInitialState,
  defaultCampaign,
  emptySections,
  fakeSha,
  fileNameFor,
  newId,
  permissionsFor,
  PIPELINE_ORDER,
  sectionsToMarkdown,
  serverMetadata,
  type MockState,
  type MockUser,
} from './fixtures';
import { parseSections, renderMarkdown } from './markdown';

export class MockHttpError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public details: Record<string, unknown> | null = null,
  ) {
    super(message);
  }
}

export interface MockRequest {
  method: string;
  path: string; // without /api/v1
  query: URLSearchParams;
  headers: Record<string, string>;
  body: any;
}

export interface MockResult {
  status?: number;
  body?: unknown;
  text?: string;
  contentType?: string;
}

let state: MockState = buildInitialState();
export function resetMockState(): void {
  state = buildInitialState();
  session = { userId: null, pending: null, stepUpUntil: 0, csrf: 'mock-csrf' };
}
/** Test helper: expire the 10-minute step-up window. */
export function expireStepUp(): void {
  session.stepUpUntil = 0;
  saveSession();
}
export function getMockState(): MockState {
  return state;
}

// ---------------------------------------------------------------- session

interface Session {
  userId: string | null;
  pending: string | null;
  stepUpUntil: number;
  csrf: string;
}
const SESSION_KEY = 'uc.mock.session';
function loadSession(): Session {
  try {
    const raw = typeof sessionStorage !== 'undefined' ? sessionStorage.getItem(SESSION_KEY) : null;
    if (raw) return JSON.parse(raw) as Session;
  } catch {
    /* ignore */
  }
  return { userId: null, pending: null, stepUpUntil: 0, csrf: `mock-${Math.random().toString(36).slice(2, 10)}` };
}
let session: Session = loadSession();
function saveSession() {
  try {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
  } catch {
    /* ignore */
  }
}

function currentUser(): MockUser | null {
  return state.users.find((u) => u.id === session.userId) ?? null;
}
function toMe(u: MockUser): Me {
  return {
    id: u.id,
    email: u.email,
    name: u.name,
    kind: u.kind,
    mfa_enabled: u.mfa_enabled,
    passkeys: u.passkeys,
    roles: u.roles,
    permissions: permissionsFor(u.roles),
    beta_opt_in: u.beta_opt_in,
  };
}
function requireUser(kind?: 'STAFF'): MockUser {
  const u = currentUser();
  if (!u) throw new MockHttpError(401, 'UNAUTHENTICATED', 'Sign in required');
  if (kind === 'STAFF' && u.kind !== 'STAFF') throw new MockHttpError(403, 'FORBIDDEN', 'Staff only');
  return u;
}
function requirePerm(perm: string): MockUser {
  const u = requireUser('STAFF');
  if (!permissionsFor(u.roles).includes(perm)) throw new MockHttpError(403, 'FORBIDDEN', `Missing permission ${perm}`);
  return u;
}
function requireMfa(req: MockRequest): void {
  const token = req.headers['x-mfa-token'];
  if (token) {
    if (!/^\d{6}$|^[a-z0-9]{4,5}-[a-z0-9]{4,5}$/i.test(token) || token === '000000') throw new MockHttpError(401, 'MFA_INVALID', 'That code is not valid. Try again.');
    session.stepUpUntil = Date.now() + 10 * 60_000;
    saveSession();
    return;
  }
  if (session.stepUpUntil > Date.now()) return;
  throw new MockHttpError(401, 'MFA_REQUIRED', 'Confirm this action with your authenticator code.');
}
function log(action: string, entityType: string | null, entityId: string | null, reason: string | null = null, before: unknown = null, after: unknown = null) {
  const u = currentUser();
  const id = (state.audit[0]?.id ?? 1000) + 1;
  state.audit.unshift({ ...audit(id, u?.name ?? 'System', action, entityType, entityId, 0, reason, before, after), created_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z') });
}

// ---------------------------------------------------------------- derived data

const PUBLIC_STATUSES = new Set(['PUBLISHED', 'SUPERSEDED', 'PAUSED']);

function releasesOf(appId: string) {
  return state.releases.filter((r) => r.app.id === appId);
}

function deriveApp(app: AdminApp): AdminApp {
  const rels = releasesOf(app.id);
  const stable = rels.filter((r) => r.channel === 'STABLE' && r.status === 'PUBLISHED').sort((a, b) => compareSemver(b.version, a.version))[0];
  const beta = rels.filter((r) => r.channel === 'BETA' && r.status === 'PUBLISHED').sort((a, b) => compareSemver(b.version, a.version))[0];
  return {
    ...app,
    latest_stable: stable ? { release_id: stable.id, version: stable.version, build: stable.build, published_at: stable.published_at!, release_date: stable.release_date } : null,
    latest_beta: beta ? { release_id: beta.id, version: beta.version, build: beta.build, published_at: beta.published_at! } : null,
    releases_count: rels.length,
  };
}

function appSummary(app: AdminApp): AppSummary {
  const d = deriveApp(app);
  return {
    id: d.id,
    slug: d.slug,
    name: d.name,
    app_identifier: d.app_identifier,
    kind: d.kind,
    description: d.description,
    icon: d.icon,
    status: d.status,
    public_download_enabled: d.public_download_enabled,
    private: d.private,
    latest_stable: d.latest_stable,
    latest_beta: d.latest_beta,
    platforms: d.platforms,
  };
}

function appBySlug(slug: string): AdminApp {
  const a = state.apps.find((x) => x.slug === slug && x.status === 'ACTIVE');
  if (!a) throw new MockHttpError(404, 'NOT_FOUND', 'Application not found');
  return a;
}
function appById(id: string): AdminApp {
  const a = state.apps.find((x) => x.id === id);
  if (!a) throw new MockHttpError(404, 'NOT_FOUND', 'Application not found');
  return a;
}
function releaseById(id: string): AdminReleaseDetail {
  const r = state.releases.find((x) => x.id === id);
  if (!r) throw new MockHttpError(404, 'NOT_FOUND', 'Release not found');
  return r;
}

function notesUrl(slug: string, version: string) {
  return `/apps/${slug}/releases/${version}`;
}

function latestStable(appId: string) {
  return releasesOf(appId)
    .filter((r) => r.channel === 'STABLE' && r.status === 'PUBLISHED')
    .sort((a, b) => compareSemver(b.version, a.version))[0];
}

function toPublicRelease(r: AdminReleaseDetail): PublicRelease {
  const latest = latestStable(r.app.id);
  return {
    id: r.id,
    version: r.version,
    build: r.build,
    channel: r.channel,
    release_type: r.release_type,
    support_state: r.support_state,
    release_date: r.release_date,
    published_at: r.published_at,
    is_latest: latest?.id === r.id,
    summary: r.changelog.summary,
    notes_url: notesUrl(r.app.slug, r.version),
  };
}

const HINT: Record<string, string> = { msi: 'IT / Intune', pkg: 'managed' };

function toPublicPackages(r: AdminReleaseDetail, app: AdminApp): PublicPackage[] {
  return r.packages
    .filter((p) => p.status === 'READY' && p.active_revision && (app.kind !== 'SERVER' || p.edition === 'FREE' || p.edition === 'ALL'))
    .map((p) => {
      const plat = app.platforms.find((x) => x.platform === p.platform && x.architecture === p.architecture);
      const rev = p.active_revision!;
      const archLabel = p.platform === 'macos' ? (p.architecture === 'arm64' ? 'Apple Silicon' : p.architecture === 'x64' ? 'Intel' : 'Universal') : p.architecture === 'arm64' ? 'ARM64' : p.architecture;
      const platLabel = { windows: 'Windows', macos: 'macOS', linux: 'Linux', android: 'Android', ios: 'iOS', server: 'Server' }[p.platform];
      const type = { appimage: 'AppImage' }[p.package_type as string] ?? p.package_type.toUpperCase();
      return {
        id: p.id,
        platform: p.platform,
        architecture: p.architecture,
        package_type: p.package_type,
        edition: p.edition,
        label: p.edition ? `${p.edition.charAt(0)}${p.edition.slice(1).toLowerCase()} edition · ZIP` : `${platLabel} ${archLabel} · ${type}`,
        file_name: rev.file_name,
        size: rev.size,
        sha256: rev.sha256 ?? '',
        min_os: p.minimum_os_version,
        download_url:
          app.kind === 'SERVER'
            ? `/download/${app.slug}/${r.version}/server/any/zip`
            : `/download/${app.slug}/${r.version}/${p.platform}/${p.architecture}/${p.package_type}`,
        recommended: plat?.default_package_type === p.package_type,
        hint: HINT[p.package_type] ?? null,
      };
    });
}

function previousVersions(app: AdminApp): PreviousVersion[] {
  const latest = latestStable(app.id);
  return releasesOf(app.id)
    .filter((r) => r.channel === 'STABLE' && PUBLIC_STATUSES.has(r.status) && r.support_state !== 'BLOCKED' && r.visibility === 'PUBLIC')
    .sort((a, b) => compareSemver(b.version, a.version))
    .map((r) => ({
      version: r.version,
      build: r.build,
      channel: r.channel,
      release_date: r.release_date,
      support_state: r.support_state,
      download_available: r.support_state === 'SUPPORTED' || r.support_state === 'DEPRECATED',
      notes_url: notesUrl(app.slug, r.version),
      is_latest: latest?.id === r.id,
    }));
}

function toPublicDetail(r: AdminReleaseDetail): PublicReleaseDetail {
  const app = appById(r.app.id);
  const latest = latestStable(app.id);
  const available = r.support_state === 'SUPPORTED' || r.support_state === 'DEPRECATED';
  const page = state.pages[app.id]?.published;
  return {
    ...toPublicRelease(r),
    changelog: r.changelog,
    packages: available ? toPublicPackages(r, app) : [],
    checksums_url: `/apps/${app.slug}/releases/${r.version}/checksums.txt`,
    manifest_url: `/api/v1/releases/${r.id}/manifest`,
    download_available: available,
    unavailable_message: available ? null : `Download unavailable. Please install ${latest?.version ?? 'the latest version'}.`,
    requirements: page?.requirements ?? {},
    previous_versions: previousVersions(app),
    app: appSummary(app),
  };
}

// ---------------------------------------------------------------- pipeline simulation

const STEP_MS = 1300;
const pipelineClock: Record<string, number> = {};

function advancePipelines() {
  const now = Date.now();
  for (const r of state.releases) {
    for (const p of r.packages) {
      for (const rev of p.revisions) {
        const t0 = pipelineClock[rev.id];
        if (rev.status !== 'PROCESSING' || t0 === undefined) continue;
        const elapsed = now - t0;
        const done = Math.floor(elapsed / STEP_MS);
        const isServer = p.platform === 'server';
        rev.pipeline = rev.pipeline.map((s, i) => {
          if (i === 0) return s;
          if (i < done) return { ...s, status: 'done', at: s.at ?? new Date(t0 + i * STEP_MS).toISOString(), detail: stepDetail(s.step, rev, p) };
          if (i === done) return { ...s, status: 'running', detail: runningDetail(s.step) };
          return s;
        });
        if (isServer && rev.metadata.validation) {
          const pct = Math.min(1, done / (PIPELINE_ORDER.length - 1));
          const n = Math.floor(rev.metadata.validation.length * pct);
          rev.metadata.validation = rev.metadata.validation.map((v, i) => ({ ...v, status: i < n ? 'pass' : 'pending' }));
        }
        if (done >= PIPELINE_ORDER.length - 1) {
          rev.status = 'READY';
          rev.pipeline = rev.pipeline.map((s) => ({ ...s, status: 'done', at: s.at ?? new Date().toISOString(), detail: s.detail ?? stepDetail(s.step, rev, p) }));
          rev.signature_status = 'VALID';
          rev.scan_status = 'CLEAN';
          rev.attestation_match = true;
          for (const other of p.revisions) if (other !== rev && other.status === 'READY') other.status = 'REPLACED';
          p.active_revision = rev;
          p.status = 'READY';
          p.health = 'OK';
          delete pipelineClock[rev.id];
          r.packages_ready = r.packages.filter((x) => x.status === 'READY').length;
          if (r.status === 'UPLOADING' && r.packages.every((x) => x.status !== 'PROCESSING')) r.status = 'DRAFT';
        }
      }
    }
  }
}

function runningDetail(step: string): string {
  return (
    {
      quarantine: 'Moving to quarantine prefix…',
      scan: 'Malware scan (ClamAV) running…',
      sha256: 'Calculating SHA-256…',
      metadata: 'Reading package metadata…',
      signature: 'Verifying code signature…',
      attestation: 'Matching CI build attestation…',
      move: 'Moving to release storage',
      ready: 'Finalising',
    } as Record<string, string>
  )[step];
}
function stepDetail(step: string, rev: Revision, p: AdminPackage): string {
  const sig = { windows: 'Authenticode', macos: 'Developer ID + notarization', linux: 'GPG', server: 'Release key' }[p.platform as string] ?? 'signature';
  return (
    {
      quarantine: `tmp/…/${(rev.sha256 ?? 'a91f').slice(0, 4)}`,
      scan: 'Malware scan (ClamAV) — clean · 6.2 s',
      sha256: `SHA-256 generated ${(rev.sha256 ?? '').slice(0, 4)}…${(rev.sha256 ?? '').slice(-4)}`,
      metadata: p.platform === 'windows' ? `Metadata — PE32+ ${p.architecture}, product version matches release` : 'Metadata matches release',
      signature: `Digital signature valid — Livezen Technologies (Pvt) Ltd · ${sig}`,
      attestation: 'CI attestation matches',
      move: `release storage/${p.platform}/`,
      ready: 'Package ready',
    } as Record<string, string>
  )[step];
}

function startProcessing(r: AdminReleaseDetail, p: AdminPackage, rev: Revision) {
  rev.status = 'PROCESSING';
  rev.sha256 = fakeSha(`${rev.file_name}-${Date.now()}`);
  rev.pipeline = PIPELINE_ORDER.map((step, i) => ({ step, status: i === 0 ? 'done' : 'pending', detail: i === 0 ? 'resumable tus upload' : null, at: i === 0 ? new Date().toISOString() : null }));
  p.status = 'PROCESSING';
  pipelineClock[rev.id] = Date.now();
  if (r.status === 'DRAFT') r.status = 'UPLOADING';
  log('package.uploaded', 'release', r.id, null, null, { file: rev.file_name, revision: rev.revision_number });
}

// ---------------------------------------------------------------- checklist & actions

function computeChecklist(r: AdminReleaseDetail): Checklist {
  const app = appById(r.app.id);
  const prev = releasesOf(app.id).filter((x) => x.channel === r.channel && x.id !== r.id && compareSemver(x.version, r.version) < 0).sort((a, b) => b.build - a.build)[0];
  const ready = r.packages.filter((p) => p.status === 'READY');
  const item = (key: string, label: string, ok: boolean | null, detail: string | null = null, blocking = true): ChecklistItem => ({
    key,
    label,
    status: ok === null ? 'pending' : ok ? 'pass' : 'fail',
    blocking,
    detail,
  });
  const items: ChecklistItem[] = [];
  const dup = releasesOf(app.id).some((x) => x.id !== r.id && x.version === r.version && x.channel === r.channel);
  items.push(item('version', 'Version entered · semver valid', isSemver(r.version) && !dup, dup ? 'Version already exists on this channel' : null));
  items.push(item('build', prev ? `Build number ${r.build} > ${prev.build} (${prev.version})` : `Build number ${r.build} valid`, !prev || r.build > prev.build));
  if (app.kind === 'SERVER') {
    for (const ed of ['FREE', 'BUSINESS', 'ENTERPRISE'] as Edition[]) {
      const p = r.packages.find((x) => x.edition === ed);
      items.push(item(`pkg_${ed}`, `${ed.charAt(0)}${ed.slice(1).toLowerCase()} edition package ${p?.status === 'PROCESSING' ? 'verifying' : 'uploaded'}`, p?.status === 'READY' ? true : p?.status === 'PROCESSING' ? null : false));
    }
  } else {
    for (const plat of ['windows', 'macos', 'linux'] as const) {
      const required = app.platforms.filter((x) => x.platform === plat && x.required_for_publish);
      if (!required.length) continue;
      const missing = r.packages.filter((p) => p.platform === plat && p.status !== 'READY');
      const processing = missing.some((p) => p.status === 'PROCESSING');
      items.push(item(`pkg_${plat}`, `${{ windows: 'Windows', macos: 'macOS', linux: 'Linux' }[plat]} packages uploaded`, missing.length === 0 ? true : processing ? null : false, missing.length ? `${missing.length} missing` : null));
    }
  }
  items.push(item('sha256', `SHA-256 calculated (${ready.length}/${r.packages.length})`, ready.length === r.packages.length && r.packages.length > 0 ? true : ready.length ? null : false));
  items.push(item('signatures', 'Package signatures checked', ready.every((p) => p.active_revision?.signature_status === 'VALID') && ready.length > 0));
  items.push(item('scan', 'Malware scan clean', ready.length > 0 && ready.every((p) => p.active_revision?.scan_status === 'CLEAN')));
  items.push(item('attestation', 'Package hashes match CI attestation', ready.length > 0 && ready.every((p) => p.active_revision?.attestation_match)));
  if (app.kind === 'SERVER') {
    items.push(item('forbidden', 'No .env / writable / uploads inside', true));
    items.push(item('migrations', 'Migrations listed + reversible flags', true));
    items.push(item('upgrade_path', `Upgrade path from ≥ ${r.server_meta?.min_from_version ?? '—'}`, !!r.server_meta?.min_from_version));
    items.push(item('staging', 'Staging install test passed', r.server_meta?.staging_test?.status === 'PASSED' ? true : r.server_meta?.staging_test?.status === 'FAILED' ? false : null));
    items.push(item('desktop_compat', `Desktop compatibility: ${r.server_meta?.desktop_compat ?? '—'}`, !!r.server_meta?.desktop_compat));
  }
  const clOk = !!r.changelog.summary && Object.values(r.changelog.sections).some((s) => s.length > 0);
  items.push(item('changelog', app.kind === 'SERVER' ? 'Changelog + admin notice written' : 'Changelog entered', clOk));
  items.push(item('minimum', 'Minimum version configured', !!r.minimum_supported_version || r.minimum_no_change));
  const manifestReady = ready.length === r.packages.length && r.packages.length > 0;
  items.push(item('manifest', `Manifest generated${r.status === 'PUBLISHED' ? '' : ' (draft)'}`, manifestReady));
  items.push(item('manifest_sig', 'Manifest signature verified', manifestReady));
  const qa = r.approvals.some((a) => a.kind === 'QA' && a.decision === 'PASS');
  const rm = r.approvals.some((a) => a.kind === 'RELEASE');
  items.push(item('approvals', 'QA approval + Release Manager approval', qa && rm ? true : null, qa ? (rm ? null : 'Waiting for Release Manager') : 'Waiting for QA', r.channel === 'STABLE'));
  items.push(item('cdn_probe', 'Storage objects readable via CDN (HEAD probe)', manifestReady));
  return { passed: items.every((i) => !i.blocking || i.status === 'pass' || i.status === 'skipped'), items };
}

function allowedActions(r: AdminReleaseDetail, u: MockUser | null): ReleaseAction[] {
  if (!u) return [];
  const perms = permissionsFor(u.roles);
  const has = (p: string) => perms.includes(p);
  const out: ReleaseAction[] = [];
  const pre = ['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING', 'APPROVED'].includes(r.status);
  if (pre && has('release.edit')) out.push('edit');
  if (['DRAFT', 'UPLOADING', 'READY_FOR_TEST'].includes(r.status) && has('release.file.upload')) out.push('upload');
  if (['DRAFT', 'UPLOADING'].includes(r.status) && has('release.edit')) out.push('submit_test');
  if (r.status === 'READY_FOR_TEST' && (has('release.approve.qa') || has('release.edit'))) out.push('start_testing');
  if (r.status === 'TESTING' && has('release.approve.qa')) out.push('qa');
  if (r.status === 'TESTING' && has('release.approve') && r.approvals.some((a) => a.kind === 'QA' && a.decision === 'PASS')) out.push('approve');
  if (r.status === 'APPROVED' && has('release.publish')) out.push('publish');
  if (r.status === 'PUBLISHED' && has('release.publish')) out.push('pause', 'regenerate_manifest');
  if (r.status === 'PAUSED' && has('release.publish')) out.push('resume', 'regenerate_manifest');
  if (['PUBLISHED', 'PAUSED'].includes(r.status) && has('release.withdraw')) out.push('withdraw');
  if (r.status === 'PUBLISHED' && has('release.publish')) out.push('supersede');
  if (['PUBLISHED', 'PAUSED'].includes(r.status) && has('release.file.replace')) out.push('replace_file');
  if (['DRAFT', 'UPLOADING', 'READY_FOR_TEST', 'TESTING'].includes(r.status) && has('release.file.delete')) out.push('delete_file');
  return out;
}

function manifestInfo(r: AdminReleaseDetail): ManifestInfo {
  const ready = r.packages.filter((p) => p.status === 'READY');
  const json = {
    schema: 2,
    app: r.app.slug,
    app_identifier: appById(r.app.id).app_identifier,
    version: r.version,
    build: r.build,
    channel: r.channel.toLowerCase(),
    release_type: r.release_type.toLowerCase(),
    minimum_supported_version: r.minimum_supported_version,
    mandatory: r.mandatory,
    mandatory_after: r.mandatory_after,
    published_at: r.published_at,
    packages: ready.map((p) => ({
      platform: p.platform,
      arch: p.architecture,
      type: p.package_type,
      ...(p.edition ? { edition: p.edition.toLowerCase() } : {}),
      file: p.active_revision?.file_name,
      size: p.active_revision?.size,
      sha256: p.active_revision?.sha256,
      signature: p.active_revision?.signature_status === 'VALID' ? { kind: p.platform === 'windows' ? 'authenticode' : p.platform === 'macos' ? 'notarized' : 'gpg', valid: true } : null,
    })),
  };
  const rec = {
    id: `mf_${r.id}`,
    manifest_version: r.status === 'PUBLISHED' ? 3 : 1,
    key_id: 'ws-release-2026a',
    signature_valid: ready.length === r.packages.length && ready.length > 0 ? true : null,
    sha256: fakeSha(JSON.stringify(json)),
    generated_at: r.updated_at,
    published_at: r.published_at,
    json,
  };
  return {
    release: ready.length ? rec : null,
    channel: r.status === 'PUBLISHED' ? { ...rec, id: `mfc_${r.id}`, json: { channel: r.channel.toLowerCase(), latest: { [r.app.slug]: r.version } } } : null,
    history: [
      { id: `mfh_${r.id}_1`, kind: 'release', manifest_version: 1, key_id: 'ws-release-2026a', generated_at: r.created_at, published_at: null, superseded_at: r.published_at, reason: 'Initial draft' },
      ...(r.published_at ? [{ id: `mfh_${r.id}_2`, kind: 'release', manifest_version: 3, key_id: 'ws-release-2026a', generated_at: r.published_at, published_at: r.published_at, superseded_at: null, reason: 'Publish' }] : []),
    ],
  };
}

function releaseDetail(r: AdminReleaseDetail): AdminReleaseDetail {
  advancePipelines();
  r.checklist = computeChecklist(r);
  r.allowed_actions = allowedActions(r, currentUser());
  r.manifest = manifestInfo(r);
  r.packages_ready = r.packages.filter((p) => p.status === 'READY').length;
  r.packages_total = r.packages.length;
  return r;
}

function listRelease(r: AdminReleaseDetail) {
  releaseDetail(r);
  const { packages: _p, changelog: _c, checklist: _k, approvals: _a, rollout: _r, manifest: _m, server_meta: _s, campaign: _cp, allowed_actions: _aa, ...rest } = r;
  return rest;
}

// ---------------------------------------------------------------- routing

type Handler = (req: MockRequest, params: Record<string, string>) => MockResult | unknown;
const routes: { method: string; re: RegExp; keys: string[]; handler: Handler }[] = [];
function route(method: string, pattern: string, handler: Handler) {
  const keys: string[] = [];
  const re = new RegExp(`^${pattern.replace(/\{(\w+)\}/g, (_m, k: string) => (keys.push(k), '([^/]+)'))}$`);
  routes.push({ method, re, keys, handler });
}
const ok = (body: unknown, status = 200): MockResult => ({ status, body });

function paginate<T>(items: T[], q: URLSearchParams) {
  const limit = Number(q.get('limit') ?? 50);
  const offset = Number(q.get('offset') ?? 0);
  return { data: items.slice(offset, offset + limit), total: items.length, limit, offset };
}

// ---- public
route('GET', '/site', () => ({ environment: 'STAGING', name: 'WorkSuite Downloads', apps: state.apps.filter((a) => a.status === 'ACTIVE' && !a.private).map(appSummary) }));
route('GET', '/apps', () => ({ data: state.apps.filter((a) => a.status === 'ACTIVE' && !a.private).map(appSummary) }));
route('GET', '/apps/{slug}', (_r, p) => {
  const app = appBySlug(p.slug);
  const latest = latestStable(app.id);
  return {
    ...appSummary(app),
    page: state.pages[app.id].published,
    latest: latest ? toPublicDetail(latest) : null,
    channels: state.channels.filter((c) => app.channels.find((x) => x.code === c.code && x.enabled)).map((c) => ({ code: c.code, name: c.name, description: c.description, is_public: c.is_public })),
  };
});
route('GET', '/apps/{slug}/releases', (req, p) => {
  const app = appBySlug(p.slug);
  const channel = req.query.get('channel') ?? 'STABLE';
  const rels = releasesOf(app.id)
    .filter((r) => r.channel === channel && PUBLIC_STATUSES.has(r.status) && r.support_state !== 'BLOCKED')
    .sort((a, b) => compareSemver(b.version, a.version))
    .map(toPublicRelease);
  return paginate(rels, req.query);
});
route('GET', '/apps/{slug}/releases/{version}', (_req, p) => {
  const app = appBySlug(p.slug);
  const r = releasesOf(app.id).find((x) => x.version === decodeURIComponent(p.version) && PUBLIC_STATUSES.has(x.status) && x.support_state !== 'BLOCKED');
  if (!r) throw new MockHttpError(404, 'RELEASE_NOT_AVAILABLE', 'This version is not available.');
  return toPublicDetail(r);
});
route('GET', '/apps/{slug}/versions', (_r, p) => ({ data: previousVersions(appBySlug(p.slug)) }));
route('GET', '/apps/{slug}/changelog', (req, p) => {
  const app = appBySlug(p.slug);
  const rels = releasesOf(app.id)
    .filter((r) => PUBLIC_STATUSES.has(r.status) && r.channel === 'STABLE')
    .sort((a, b) => compareSemver(b.version, a.version))
    .map((r) => ({ ...toPublicRelease(r), changelog: r.changelog }));
  return paginate(rels, req.query);
});
route('GET', '/changelog', (req) => {
  const rels = state.releases
    .filter((r) => PUBLIC_STATUSES.has(r.status) && r.channel === 'STABLE')
    .sort((a, b) => (b.published_at ?? '').localeCompare(a.published_at ?? ''))
    .map((r) => ({ ...toPublicRelease(r), changelog: r.changelog, app: { slug: r.app.slug, name: r.app.name } }));
  return paginate(rels, req.query);
});
route('GET', '/os/releases', () => ({
  keys_url: '/api/v1/os/keys',
  data: (['amd64', 'arm64'] as const).map((arch) => ({
    version: '1.2.0', ring: 'stable', channel: 'STABLE', arch, release_type: 'SECURITY', state: 'active', published_at: '2026-10-01 09:00:00', notes: null,
    iso: { file_name: `worksuite-os-1.2.0-${arch}.iso`, size_bytes: 2_400_000_000, sha256: 'a'.repeat(64), url: `/os/download/1.2.0/${arch}/worksuite-os-1.2.0-${arch}.iso`, sha256_url: `/os/download/1.2.0/${arch}/worksuite-os-1.2.0-${arch}.iso.sha256` },
    sbom: { file_name: `worksuite-os-1.2.0-${arch}.sbom.spdx.json`, url: `/os/download/1.2.0/${arch}/worksuite-os-1.2.0-${arch}.sbom.spdx.json` },
    apt: { url: '/os/apt', suite: 'trixie-stable', keyring_url: '/os/apt/worksuite-archive-keyring.gpg' },
  })),
}));
route('GET', '/channels', () => ({ data: state.channels.map((c) => ({ code: c.code, name: c.name, description: c.description, is_public: c.is_public, allow_direct_download: c.allow_direct_download })) }));
route('POST', '/subscriptions', (req) => {
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(req.body?.email ?? '')) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { email: 'Enter a valid email address' });
  return ok({ status: 'pending_confirmation' }, 202);
});
route('GET', '/subscriptions/confirm', () => ({ status: 'confirmed' }));
route('GET', '/subscriptions/unsubscribe', () => ({ status: 'unsubscribed' }));
const publicReports = new Map<string, { reference: string; title: string; token: string; created_at: string }>();
route('POST', '/bugtrace/reports/public', (req) => {
  const b = req.body ?? {};
  if (b.website) return ok({ reference: 'BR-0', status_url: null, status_token: null }, 201);
  if (!b.captcha_token) throw new MockHttpError(422, 'CAPTCHA_FAILED', 'Captcha verification failed');
  const errors: Record<string, string> = {};
  if (!String(b.title ?? '').trim()) errors.title = 'Required';
  if (b.platform !== undefined && !['web', 'desktop', 'mobile'].includes(b.platform)) errors.platform = 'One of web, desktop, mobile';
  if (b.contact?.consent && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(b.contact.email ?? '')) errors['contact.email'] = 'Invalid e-mail address';
  if (Object.keys(errors).length) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', errors);
  const reference = `BR-${21000 + publicReports.size + state.reports.length}`;
  const token = newId('tok');
  publicReports.set(reference, { reference, title: b.title, token, created_at: new Date().toISOString() });
  return ok({ reference, status_url: `/api/v1/bugtrace/reports/${reference}/status`, status_token: token }, 201);
});
route('GET', '/bugtrace/reports/{ref}/status', (req, p) => {
  const r = publicReports.get(p.ref);
  if (!r || req.query.get('token') !== r.token) throw new MockHttpError(404, 'NOT_FOUND', 'Report not found');
  return { reference: r.reference, title: r.title, status: 'received', fixed_in: null, created_at: r.created_at, updated_at: r.created_at, updates: [] };
});

// ---- auth
route('POST', '/auth/login', (req) => {
  const email = String(req.body?.email ?? '').trim().toLowerCase();
  const password = String(req.body?.password ?? '');
  if (!email || password.length < 1 || password === 'wrong') throw new MockHttpError(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
  let u = state.users.find((x) => x.email === email);
  if (!u) {
    const staff = email.endsWith('@livezen.lk');
    u = { id: newId('usr'), email, name: email.split('@')[0], kind: staff ? 'STAFF' : 'PORTAL', status: 'ACTIVE', roles: staff ? ['super_admin'] : ['portal_user'], mfa_enabled: staff, last_login_at: null, created_at: new Date().toISOString(), password, passkeys: 0, beta_opt_in: false };
    state.users.push(u);
  }
  if (u.status !== 'ACTIVE') throw new MockHttpError(403, 'ACCOUNT_DISABLED', 'This account is disabled.');
  if (u.kind === 'STAFF') {
    session = { ...session, userId: null, pending: u.id };
    saveSession();
    return { mfa_required: true, csrf: session.csrf, user: null, methods: u.passkeys > 0 ? ['totp', 'passkey'] : ['totp'] };
  }
  session = { ...session, userId: u.id, pending: null };
  saveSession();
  return { mfa_required: false, csrf: session.csrf, user: toMe(u), methods: [] };
});
route('POST', '/auth/mfa', (req) => {
  if (!session.pending) throw new MockHttpError(401, 'UNAUTHENTICATED', 'Sign in again.');
  const code = String(req.body?.code ?? '');
  if (!/^\d{6}$/.test(code) || code === '000000') throw new MockHttpError(401, 'MFA_INVALID', 'That code is not valid. Try again.');
  session = { ...session, userId: session.pending, pending: null, stepUpUntil: Date.now() + 10 * 60_000 };
  saveSession();
  return { user: toMe(currentUser()!), csrf: session.csrf };
});
route('POST', '/auth/passkey/options', () => ({ challenge: 'bW9jay1jaGFsbGVuZ2U', rpId: location.hostname, timeout: 60000, userVerification: 'required', allowCredentials: [] }));
route('POST', '/auth/passkey/verify', () => {
  const id = session.pending ?? session.userId;
  if (!id) throw new MockHttpError(401, 'UNAUTHENTICATED', 'Sign in again.');
  session = { ...session, userId: id, pending: null, stepUpUntil: Date.now() + 10 * 60_000 };
  saveSession();
  return { user: toMe(currentUser()!), csrf: session.csrf };
});
route('POST', '/auth/logout', () => {
  session = { ...session, userId: null, pending: null, stepUpUntil: 0 };
  saveSession();
  return { ok: true };
});
route('GET', '/auth/me', () => {
  const u = requireUser();
  return { user: toMe(u), csrf: session.csrf, environment: 'STAGING', step_up_valid_until: session.stepUpUntil > Date.now() ? new Date(session.stepUpUntil).toISOString() : null };
});
route('POST', '/auth/step-up', (req) => {
  requireUser();
  const code = String(req.body?.code ?? '');
  if (!/^\d{6}$/.test(code) || code === '000000') throw new MockHttpError(401, 'MFA_INVALID', 'That code is not valid.');
  session.stepUpUntil = Date.now() + 10 * 60_000;
  saveSession();
  return { step_up_valid_until: new Date(session.stepUpUntil).toISOString() };
});
route('POST', '/auth/mfa/totp/setup', () => {
  const u = requireUser();
  const secret = 'JBSWY3DPEHPK3PXP';
  return { secret, otpauth_uri: `otpauth://totp/WorkSuite%20Update%20Center:${encodeURIComponent(u.email)}?secret=${secret}&issuer=WorkSuite%20Update%20Center&digits=6&period=30` };
});
route('POST', '/auth/mfa/totp/confirm', (req) => {
  const u = requireUser();
  if (!/^\d{6}$/.test(String(req.body?.code ?? ''))) throw new MockHttpError(401, 'MFA_INVALID', 'That code is not valid.');
  u.mfa_enabled = true;
  return { recovery_codes: Array.from({ length: 10 }, (_, i) => `${fakeSha(`rc${i}`).slice(0, 5)}-${fakeSha(`rd${i}`).slice(0, 5)}`) };
});
route('POST', '/auth/passkeys/register/options', () => {
  const u = requireUser();
  return { challenge: 'bW9jay1jaGFsbGVuZ2U', rp: { name: 'WorkSuite Update Center', id: location.hostname }, user: { id: 'dXNlcg', name: u.email, displayName: u.name }, pubKeyCredParams: [{ type: 'public-key', alg: -7 }], timeout: 60000, attestation: 'none' };
});
route('POST', '/auth/passkeys/register', () => {
  const u = requireUser();
  u.passkeys++;
  return { ok: true };
});
route('POST', '/auth/password/forgot', () => ok({ ok: true }, 202));
route('POST', '/auth/password/reset', (req) => {
  if (String(req.body?.password ?? '').length < 12) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { password: 'Use at least 12 characters' });
  return { ok: true };
});
route('POST', '/auth/register', (req) => {
  if (String(req.body?.password ?? '').length < 12) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { password: 'Use at least 12 characters' });
  return ok({ status: 'verification_sent' }, 201);
});
route('POST', '/auth/verify-email', () => ({ ok: true }));

// ---- admin shell
function healthComponents() {
  const at = ago(0);
  return [
    { component: 'api', status: 'OK', latency_ms: 84, detail: 'p95 84 ms', checked_at: at },
    { component: 'database', status: 'OK', latency_ms: 3, detail: 'MariaDB · replica lag 0 s', checked_at: at },
    { component: 'redis', status: 'OK', latency_ms: 1, detail: 'mem 22%', checked_at: at },
    { component: 'storage', status: 'OK', latency_ms: 41, detail: 'Cloudflare R2 · 41 ms', checked_at: at },
    { component: 'cdn', status: 'OK', latency_ms: 18, detail: '97.8% hit', checked_at: at },
    { component: 'signing', status: 'OK', latency_ms: 22, detail: 'KMS · key ws-release-2026a', checked_at: at },
    { component: 'queue', status: 'DEGRADED', latency_ms: null, detail: '14 jobs · scan backlog', checked_at: at },
  ] as const;
}

route('GET', '/admin/dashboard', () => {
  requireUser('STAFF');
  advancePipelines();
  const desktop = state.apps.find((a) => a.slug === 'desktop')!;
  const r270 = state.releases.find((r) => r.id === 'rel_desktop_270');
  return {
    environment: 'STAGING',
    kpis: {
      apps: state.apps.length,
      apps_public: state.apps.filter((a) => a.public_download_enabled && !a.private).length - 1,
      releases: 37 + (state.releases.length - 16),
      releases_testing: state.releases.filter((r) => r.status === 'TESTING').length + 1,
      releases_draft: state.releases.filter((r) => ['DRAFT', 'UPLOADING'].includes(r.status)).length,
      downloads_30d: 18_412,
      downloads_redirect_ok_30d: 18_390,
      active_devices: 2481,
    },
    latest_releases: [...state.releases]
      .filter((r) => r.status !== 'SUPERSEDED')
      .sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))
      .slice(0, 5)
      .map((r) => ({ id: r.id, app_name: r.app.name, app_slug: r.app.slug, version: r.version, channel: r.channel, status: r.status })),
    version_distribution: {
      app_name: desktop.name,
      items: [
        { version: '2.7.0', count: 1290, pct: 52 },
        { version: '2.6.5', count: 794, pct: 32 },
        { version: '2.5.x', count: 273, pct: 11 },
        { version: '≤2.4', count: 124, pct: 5 },
      ],
      current_pct: 84,
      update_available_pct: 11,
      unsupported_pct: 5,
    },
    downloads: r270
      ? { release_id: r270.id, app_name: 'Desktop', version: '2.7.0', items: [{ label: 'Windows x64', count: 8491 }, { label: 'macOS ARM64', count: 1941 }, { label: 'Linux x64', count: 642 }] }
      : null,
    active_rollouts: state.releases
      .filter((r) => r.rollout && (r.rollout.status === 'ACTIVE' || r.rollout.status === 'PAUSED'))
      .map((r) => ({ release_id: r.id, app_name: r.app.name.replace('WorkSuite ', ''), version: r.version, channel: r.channel, stage_no: r.rollout!.stage_no, stages: r.rollout!.stages, percentage: r.rollout!.percentage, success_rate: r.rollout!.status === 'PAUSED' ? 95.9 : 98.6, status: r.rollout!.status })),
    recent_activity: state.audit.slice(0, 6),
    health: { ok: true, components: healthComponents() },
    notifications_unread: state.notifications.filter((n) => !n.read_at).length,
  };
});
route('GET', '/admin/notifications', () => {
  requireUser('STAFF');
  return { data: state.notifications, unread: state.notifications.filter((n) => !n.read_at).length };
});
route('POST', '/admin/notifications/read-all', () => {
  state.notifications.forEach((n) => (n.read_at ??= new Date().toISOString()));
  return { ok: true };
});
route('POST', '/admin/notifications/{id}/read', (_r, p) => {
  const n = state.notifications.find((x) => x.id === p.id);
  if (n) n.read_at ??= new Date().toISOString();
  return { ok: true };
});
route('GET', '/admin/health', () => ({ ok: true, environment: 'STAGING', components: healthComponents(), queue: { backlog: 14, oldest_age_s: 95, failed_24h: 1 } }));

// ---- applications
route('GET', '/admin/applications', () => {
  requirePerm('application.view');
  return { data: state.apps.map(deriveApp) };
});
route('POST', '/admin/applications', (req) => {
  requirePerm('application.manage');
  const b = req.body ?? {};
  const errors: Record<string, string> = {};
  if (!b.name) errors.name = 'Name is required';
  if (!/^[a-z0-9-]{2,40}$/.test(b.slug ?? '')) errors.slug = 'Use 2–40 lowercase letters, digits or dashes';
  if (!/^[a-z0-9]+(\.[a-z0-9-]+)+$/.test(b.app_identifier ?? '')) errors.app_identifier = 'Use reverse-DNS, e.g. lk.worksuite.desktop';
  if (Object.keys(errors).length) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', errors);
  if (state.apps.some((a) => a.slug === b.slug || a.app_identifier === b.app_identifier)) throw new MockHttpError(409, 'APP_EXISTS', 'An application with this slug or identifier already exists.');
  const app: AdminApp = {
    id: newId('app'),
    slug: b.slug,
    name: b.name,
    app_identifier: b.app_identifier,
    kind: b.kind,
    description: b.description ?? '',
    icon: b.icon ?? null,
    status: b.status ?? 'ACTIVE',
    public_download_enabled: !!b.public_download_enabled,
    private: !!b.security_settings?.private,
    latest_stable: null,
    latest_beta: null,
    platforms: b.platforms ?? [],
    default_channel: b.default_channel ?? 'STABLE',
    channels: (['STABLE', 'BETA', 'DEV', 'INTERNAL', 'CUSTOM'] as const).map((code) => ({ code, enabled: (b.channels ?? []).includes(code) })),
    manifest_settings: b.manifest_settings,
    security_settings: b.security_settings,
    show_previous_versions: !!b.show_previous_versions,
    releases_count: 0,
    created_at: new Date().toISOString(),
  };
  state.apps.push(app);
  state.pages[app.id] = {
    published: { hero: { title: app.name, subtitle: app.description }, description_md: app.description, description_html: renderMarkdown(app.description), requirements: {}, install_help: {}, faq: [], support_url: null, version: 1, status: 'PUBLISHED' },
    draft: null,
    history: [],
  };
  log('application.create', 'application', app.id, null, null, { slug: app.slug });
  return ok(app, 201);
});
route('GET', '/admin/applications/{id}', (_r, p) => {
  requirePerm('application.view');
  return deriveApp(appById(p.id));
});
route('PATCH', '/admin/applications/{id}', (req, p) => {
  requirePerm('application.manage');
  const app = appById(p.id);
  const before = { ...app };
  Object.assign(app, req.body ?? {});
  if (req.body?.channels && Array.isArray(req.body.channels) && typeof req.body.channels[0] === 'string') {
    app.channels = (['STABLE', 'BETA', 'DEV', 'INTERNAL', 'CUSTOM'] as const).map((code) => ({ code, enabled: req.body.channels.includes(code) }));
  }
  log('application.update', 'application', app.id, null, { name: before.name }, req.body);
  return deriveApp(app);
});
function minimumsResponse(appId: string) {
  return { data: state.minimums[appId] ?? [], history: state.minimumHistory[appId] ?? [] };
}
route('GET', '/admin/applications/{id}/minimum-versions', (_r, p) => minimumsResponse(p.id));
route('GET', '/admin/applications/{id}/minimum-versions/impact', (req) => {
  const v = req.query.get('version') ?? '0.0.0';
  const dist = [
    { version: '2.7.0', count: 1290 },
    { version: '2.6.5', count: 794 },
    { version: '2.6.4', count: 160 },
    { version: '2.5.3', count: 113 },
    { version: '2.4.9', count: 124 },
  ];
  const below = dist.filter((d) => isSemver(v) && compareSemver(d.version, v) < 0);
  const n = below.reduce((s, d) => s + d.count, 0);
  return { active_devices: 2481, update_required: n, unsupported: below.filter((d) => compareSemver(d.version, '2.5.0') < 0).reduce((s, d) => s + d.count, 0), by_version: dist };
});
route('PUT', '/admin/applications/{id}/minimum-versions', (req, p) => {
  requirePerm('minimum_version.manage');
  requireMfa(req);
  const b = req.body;
  if (!isSemver(b.minimum_version ?? '')) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { minimum_version: 'Enter a valid SemVer version' });
  if (!b.reason) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { reason: 'A reason is required' });
  const list = (state.minimums[p.id] ??= []);
  const existing = list.find((m) => m.channel === b.channel && m.platform === (b.platform ?? null));
  const before = existing?.minimum_version ?? null;
  const rec = { channel: b.channel, platform: b.platform ?? null, minimum_version: b.minimum_version, enforce_from: b.enforce_from ?? null, message: b.message ?? null, updated_at: new Date().toISOString() };
  if (existing) Object.assign(existing, rec);
  else list.push(rec);
  (state.minimumHistory[p.id] ??= []).unshift({ ...rec, before_version: before, reason: b.reason, updated_by: currentUser()?.name ?? null, created_at: rec.updated_at });
  log('minimum_version.change', 'application', p.id, b.reason, { minimum_version: before }, { minimum_version: b.minimum_version });
  return minimumsResponse(p.id);
});
route('GET', '/admin/applications/{id}/download-page', (_r, p) => state.pages[p.id] ?? { published: null, draft: null, history: [] });
route('PUT', '/admin/applications/{id}/download-page', (req, p) => {
  requirePerm('content.manage');
  const pg = state.pages[p.id];
  const b = req.body;
  pg.draft = {
    hero: b.hero,
    description_md: b.description_md,
    description_html: renderMarkdown(b.description_md ?? ''),
    requirements: b.requirements ?? {},
    install_help: Object.fromEntries(Object.entries((b.install_help ?? {}) as Record<string, string>).map(([k, v]) => [k, { markdown: v, html: renderMarkdown(v) }])),
    faq: (b.faq ?? []).map((f: { q: string; a: string }) => ({ ...f, a_html: renderMarkdown(f.a) })),
    support_url: b.support_url ?? null,
    version: pg.published.version + 1,
    status: 'DRAFT',
  };
  log('download_page.save_draft', 'application', p.id);
  return pg;
});
route('POST', '/admin/applications/{id}/download-page/publish', (_r, p) => {
  requirePerm('content.manage');
  const pg = state.pages[p.id];
  if (!pg.draft) throw new MockHttpError(409, 'NO_DRAFT', 'There is no draft to publish.');
  pg.published = { ...pg.draft, status: 'PUBLISHED' };
  pg.history.unshift({ version: pg.published.version, updated_by: currentUser()?.name ?? null, created_at: new Date().toISOString() });
  pg.draft = null;
  log('download_page.publish', 'application', p.id);
  return pg;
});
route('POST', '/admin/content/preview', (req) => ({ html: renderMarkdown(String(req.body?.markdown ?? '')) }));
route('GET', '/admin/channels', () => ({ data: state.channels }));
route('PATCH', '/admin/channels/{id}', (req, p) => {
  requirePerm('application.manage');
  const c = state.channels.find((x) => x.id === p.id);
  if (!c) throw new MockHttpError(404, 'NOT_FOUND', 'Channel not found');
  Object.assign(c, req.body);
  log('channel.update', 'channel', c.id, null, null, req.body);
  return c;
});
route('GET', '/admin/platforms', () => ({ data: state.apps.flatMap((a) => a.platforms.map((pl) => ({ ...pl, app_id: a.id, app_name: a.name }))) }));

// ---- releases
route('GET', '/admin/releases', (req) => {
  requirePerm('release.view');
  const statuses = req.query.get('status')?.split(',').filter(Boolean);
  const app = req.query.get('app');
  const channel = req.query.get('channel');
  const platform = req.query.get('platform');
  const q = (req.query.get('q') ?? '').toLowerCase();
  const all = state.releases;
  const filtered = all
    .filter((r) => !statuses || statuses.includes(r.status))
    .filter((r) => !app || r.app.slug === app)
    .filter((r) => !channel || r.channel === channel)
    .filter((r) => !platform || r.packages.some((p) => p.platform === platform))
    .filter((r) => !q || `${r.app.name} ${r.version}`.toLowerCase().includes(q))
    .sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''));
  const counts: Record<string, number> = {};
  for (const r of all) counts[r.status] = (counts[r.status] ?? 0) + 1;
  return { ...paginate(filtered.map(listRelease), req.query), counts };
});
route('POST', '/admin/releases', (req) => {
  requirePerm('release.create');
  const b = req.body ?? {};
  const app = appById(b.app_id);
  const errors: Record<string, string> = {};
  if (!isSemver(b.version ?? '')) errors.version = 'Enter a valid SemVer version (e.g. 2.7.0)';
  if (!Number.isInteger(Number(b.build)) || Number(b.build) <= 0) errors.build = 'Build must be a positive integer';
  if (Object.keys(errors).length) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', errors);
  if (releasesOf(app.id).some((r) => r.version === b.version && r.channel === b.channel)) throw new MockHttpError(409, 'RELEASE_EXISTS', `${app.name} ${b.version} already exists on ${b.channel}.`);
  const prevMax = Math.max(0, ...releasesOf(app.id).filter((r) => r.channel === b.channel).map((r) => r.build));
  if (Number(b.build) <= prevMax) throw new MockHttpError(422, 'BUILD_NOT_INCREASING', `Build must be greater than ${prevMax}.`, { build: `Must be greater than ${prevMax}` });
  const now = new Date().toISOString();
  const packages: AdminPackage[] =
    app.kind === 'SERVER'
      ? (['FREE', 'BUSINESS', 'ENTERPRISE'] as Edition[]).map((ed) => ({ id: newId('pkg'), platform: 'server', architecture: 'any', package_type: 'zip', edition: ed, scope: 'full', status: 'EMPTY', health: 'UNKNOWN', minimum_os_version: null, active_revision: null, revisions: [] }))
      : app.platforms.flatMap((pl) => pl.package_types.map((t) => ({ id: newId('pkg'), platform: pl.platform, architecture: pl.architecture, package_type: t, edition: null, scope: null, status: 'EMPTY' as const, health: 'UNKNOWN' as const, minimum_os_version: pl.min_os_version, active_revision: null, revisions: [] })));
  const rel: AdminReleaseDetail = {
    id: newId('rel'),
    app: { id: app.id, slug: app.slug, name: app.name, kind: app.kind },
    version: b.version,
    build: Number(b.build),
    build_revision: null,
    channel: b.channel,
    release_type: b.release_type ?? 'RECOMMENDED',
    status: 'DRAFT',
    support_state: 'SUPPORTED',
    visibility: 'PUBLIC',
    minimum_supported_version: b.minimum_supported_version ?? null,
    minimum_no_change: !b.minimum_supported_version,
    mandatory: !!b.mandatory,
    mandatory_after: b.mandatory_after ?? null,
    grace_minutes: b.grace_minutes ?? null,
    rollback_supported: true,
    release_date: b.release_date ?? null,
    scheduled_publish_at: null,
    published_at: null,
    published_by: null,
    created_by: currentUser()?.name ?? null,
    withdrawn_at: null,
    withdraw_reason: null,
    superseded_by: null,
    notes: b.notes ?? null,
    known_issues: b.known_issues ?? null,
    auto_pause_on_gate: true,
    last_error: null,
    created_at: now,
    updated_at: now,
    packages_ready: 0,
    packages_total: packages.length,
    packages,
    changelog: { locale: 'en', summary: null, highlights: [], sections: emptySections(), markdown: '', html: '', version: 0, updated_at: null },
    checklist: { passed: false, items: [] },
    approvals: [],
    rollout: null,
    manifest: null,
    server_meta: app.kind === 'SERVER' ? { min_from_version: null, requires: null, migrations: null, desktop_compat: null, downtime_estimate_s: null, staging_test: null, admin_notice: null, advisory: null } : null,
    campaign: app.kind === 'SERVER' ? defaultCampaign() : null,
    allowed_actions: [],
  };
  state.releases.unshift(rel);
  log('release.create', 'release', rel.id, null, null, { version: rel.version, channel: rel.channel });
  return ok(listRelease(rel), 201);
});
route('GET', '/admin/releases/{id}', (_r, p) => {
  requirePerm('release.view');
  return releaseDetail(releaseById(p.id));
});
route('PATCH', '/admin/releases/{id}', (req, p) => {
  requirePerm('release.edit');
  const r = releaseById(p.id);
  const post = ['PUBLISHED', 'PAUSED', 'WITHDRAWN', 'SUPERSEDED'].includes(r.status);
  const allowed = post ? ['notes', 'known_issues', 'visibility'] : Object.keys(req.body ?? {});
  const bad = Object.keys(req.body ?? {}).filter((k) => !allowed.includes(k));
  if (bad.length) throw new MockHttpError(409, 'RELEASE_IMMUTABLE', `After publish only notes, known issues and visibility can change (${bad.join(', ')}).`);
  Object.assign(r, req.body);
  r.updated_at = new Date().toISOString();
  log('release.update', 'release', r.id, null, null, req.body);
  return listRelease(r);
});
route('GET', '/admin/releases/{id}/checklist', (_r, p) => computeChecklist(releaseById(p.id)));
route('PUT', '/admin/releases/{id}/changelog', (req, p) => {
  requirePerm('changelog.manage');
  const r = releaseById(p.id);
  const b = req.body ?? {};
  if ((b.summary ?? '').length > 280) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { summary: 'Keep the summary to 280 characters' });
  if ((b.highlights ?? []).length > 5) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { highlights: 'At most 5 highlights' });
  let sections: ChangelogSections = { ...emptySections(), ...(b.sections ?? {}) };
  if (b.markdown && !b.sections) sections = { ...emptySections(), ...(parseSections(b.markdown) as Partial<ChangelogSections>) };
  const markdown = sectionsToMarkdown(sections);
  const cl: Changelog = { locale: b.locale ?? 'en', summary: b.summary ?? null, highlights: b.highlights ?? [], sections, markdown, html: renderMarkdown(markdown), version: r.changelog.version + 1, updated_at: new Date().toISOString() };
  r.changelog = cl;
  log('changelog.update', 'release', r.id);
  return cl;
});
function transition(r: AdminReleaseDetail, to: AdminReleaseDetail['status'], action: string, reason: string | null = null) {
  const before = r.status;
  r.status = to;
  r.updated_at = new Date().toISOString();
  log(action, 'release', r.id, reason, { status: before }, { status: to });
  return listRelease(r);
}
route('POST', '/admin/releases/{id}/submit-test', (_r, p) => {
  requirePerm('release.edit');
  const r = releaseById(p.id);
  const cl = computeChecklist(r);
  const failing = cl.items.filter((i) => i.blocking && i.status !== 'pass' && i.key !== 'approvals');
  if (failing.length) throw new MockHttpError(422, 'CHECKLIST_FAILED', 'The release checklist has blocking items.', { items: failing.map((f) => f.label) });
  return transition(r, 'READY_FOR_TEST', 'release.submit_test');
});
route('POST', '/admin/releases/{id}/start-testing', (_r, p) => transition(releaseById(p.id), 'TESTING', 'release.start_testing'));
route('POST', '/admin/releases/{id}/qa', (req, p) => {
  const u = requirePerm('release.approve.qa');
  const r = releaseById(p.id);
  if (r.channel === 'STABLE' && r.packages.some((pk) => pk.active_revision?.uploaded_by === u.name)) throw new MockHttpError(403, 'SOD_VIOLATION', 'You uploaded packages for this release and cannot give the QA approval.');
  r.approvals.push({ kind: 'QA', decision: req.body.decision, platform: req.body.platform ?? null, user: u.name, comment: req.body.comment ?? null, created_at: new Date().toISOString() });
  if (req.body.decision === 'FAIL') return transition(r, 'DRAFT', 'release.qa_fail', req.body.comment);
  log('release.qa', 'release', r.id, req.body.comment ?? null, null, { decision: 'PASS', platform: req.body.platform ?? null });
  return listRelease(r);
});
route('POST', '/admin/releases/{id}/approve', (req, p) => {
  const u = requirePerm('release.approve');
  const r = releaseById(p.id);
  r.approvals.push({ kind: 'RELEASE', decision: 'APPROVED', platform: null, user: u.name, comment: req.body?.comment ?? null, created_at: new Date().toISOString() });
  return transition(r, 'APPROVED', 'release.approve');
});
route('GET', '/admin/releases/{id}/publish-summary', (_r, p) => {
  const r = releaseDetail(releaseById(p.id));
  const prev = latestStable(r.app.id);
  const chMin = state.minimums[r.app.id]?.find((m) => m.channel === r.channel && !m.platform)?.minimum_version ?? null;
  const eff = [r.minimum_supported_version, chMin].filter(Boolean).sort((a, b) => compareSemver(b!, a!))[0] ?? null;
  return {
    channel: r.channel,
    release_type: r.release_type,
    rollout: r.rollout ? { mode: r.rollout.mode, stages: r.rollout.stages } : { mode: 'STAGED', stages: [25, 50, 100] },
    minimum_supported_version: r.minimum_supported_version,
    effective_minimum: eff,
    mandatory: r.mandatory,
    mandatory_after: r.mandatory_after,
    supersedes: prev && prev.id !== r.id && prev.channel === r.channel ? [{ id: prev.id, version: prev.version }] : [],
    approvals: r.approvals,
    checklist: r.checklist,
    affected: { active_devices: 2481, will_be_offered: 1191 },
    health_ok: true,
    requires_mfa: true,
  };
});
route('POST', '/admin/releases/{id}/publish', (req, p) => {
  requirePerm('release.publish');
  requireMfa(req);
  const r = releaseDetail(releaseById(p.id));
  if (!r.checklist.passed) throw new MockHttpError(422, 'CHECKLIST_FAILED', 'The release checklist has blocking items.', { items: r.checklist.items.filter((i) => i.blocking && i.status !== 'pass').map((i) => i.label) });
  const prev = latestStable(r.app.id);
  if (prev && prev.id !== r.id && prev.channel === r.channel) {
    prev.status = 'SUPERSEDED';
    prev.superseded_by = { id: r.id, version: r.version };
    if (prev.rollout) prev.rollout.status = 'DONE';
  }
  r.published_at = new Date().toISOString();
  r.published_by = currentUser()?.name ?? null;
  r.release_date ??= r.published_at.slice(0, 10);
  r.rollout = {
    status: 'ACTIVE', mode: 'STAGED', stages: [25, 50, 100], stage_no: 1, percentage: 25, started_at: r.published_at, paused_at: null, completed_at: null, auto_advance: true, auto_pause: true,
    thresholds: { dwell_hours: 24, min_success_rate: 97, max_failure_rate: 3, max_rollback_rate: 1 }, rules: [],
    progress: [25, 50, 100].map((s, i) => ({ stage: i + 1, percentage: s, eligible: Math.round(2481 * s / 100), updated: 0, failed: 0, rolled_back: 0, success_rate: i === 0 ? null : null })),
  };
  if (r.campaign) r.campaign.status = 'SENT';
  return transition(r, 'PUBLISHED', 'release.publish', req.body?.reason ?? null);
});
route('POST', '/admin/releases/{id}/pause', (req, p) => {
  requirePerm('release.publish');
  requireMfa(req);
  const r = releaseById(p.id);
  if (r.rollout) r.rollout = { ...r.rollout, status: 'PAUSED', paused_at: new Date().toISOString() };
  return transition(r, 'PAUSED', 'release.pause', req.body?.reason ?? null);
});
route('POST', '/admin/releases/{id}/resume', (req, p) => {
  requirePerm('release.publish');
  requireMfa(req);
  const r = releaseById(p.id);
  if (r.rollout) r.rollout = { ...r.rollout, status: 'ACTIVE', paused_at: null };
  r.last_error = null;
  return transition(r, 'PUBLISHED', 'release.resume');
});
route('POST', '/admin/releases/{id}/withdraw', (req, p) => {
  requirePerm('release.withdraw');
  requireMfa(req);
  const r = releaseById(p.id);
  if (!req.body?.reason) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { reason: 'A reason is required' });
  if (req.body?.confirm_version !== r.version) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Type the version to confirm', { confirm_version: `Type ${r.version} to confirm` });
  r.withdrawn_at = new Date().toISOString();
  r.withdraw_reason = req.body.reason;
  if (r.rollout) r.rollout.status = 'DONE';
  return transition(r, 'WITHDRAWN', 'release.withdraw', req.body.reason);
});
route('POST', '/admin/releases/{id}/supersede', (req, p) => {
  requirePerm('release.publish');
  requireMfa(req);
  return transition(releaseById(p.id), 'SUPERSEDED', 'release.supersede', req.body?.reason ?? null);
});
route('PUT', '/admin/releases/{id}/policy', (req, p) => {
  requirePerm('minimum_version.manage');
  const r = releaseById(p.id);
  if (['PUBLISHED', 'PAUSED'].includes(r.status)) {
    requireMfa(req);
    if (!req.body?.reason) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { reason: 'A reason is required after publish' });
  }
  const { reason: _reason, immediate: _imm, ...rest } = req.body ?? {};
  const before = { minimum_supported_version: r.minimum_supported_version, mandatory: r.mandatory, mandatory_after: r.mandatory_after, grace_minutes: r.grace_minutes, release_type: r.release_type };
  Object.assign(r, rest);
  log('release.policy', 'release', r.id, req.body?.reason ?? null, before, rest);
  return listRelease(r);
});
route('PUT', '/admin/releases/{id}/support-state', (req, p) => {
  requirePerm('release.publish');
  requireMfa(req);
  const r = releaseById(p.id);
  const before = r.support_state;
  r.support_state = req.body.support_state;
  log('release.support_state', 'release', r.id, req.body.reason ?? null, { support_state: before }, { support_state: r.support_state });
  return listRelease(r);
});
route('GET', '/admin/releases/{id}/preview', (_r, p) => {
  const r = releaseDetail(releaseById(p.id));
  return {
    public: toPublicDetail(r),
    update_responses: [
      { sample: { platform: 'windows', architecture: 'x64', install_type: 'exe', current_version: '2.6.5' }, response: { status: 'UPDATE_AVAILABLE', version: r.version, mandatory: r.mandatory, release_type: r.release_type } },
      { sample: { platform: 'macos', architecture: 'arm64', install_type: 'dmg', current_version: '2.4.9' }, response: { status: 'UPDATE_REQUIRED', version: r.version, minimum: r.minimum_supported_version } },
    ],
  };
});
route('GET', '/admin/releases/{id}/manifest', (_r, p) => manifestInfo(releaseById(p.id)));
route('POST', '/admin/releases/{id}/manifest/regenerate', (req, p) => {
  requirePerm('release.publish');
  requireMfa(req);
  const r = releaseById(p.id);
  if (!req.body?.reason) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { reason: 'A reason is required' });
  r.updated_at = new Date().toISOString();
  log('manifest.regenerate', 'release', r.id, req.body.reason);
  const m = manifestInfo(r);
  m.history.unshift({ id: newId('mfh'), kind: 'release', manifest_version: (m.release?.manifest_version ?? 0) + 1, key_id: 'ws-release-2026a', generated_at: r.updated_at, published_at: r.published_at ? r.updated_at : null, superseded_at: null, reason: req.body.reason });
  return { ...m, diff: [{ path: 'generated_at', before: m.release?.generated_at ?? null, after: r.updated_at }, { path: 'signature', before: 'ed25519:…9f1a', after: 'ed25519:…c77e' }] };
});
route('PUT', '/admin/releases/{id}/rollout', (req, p) => {
  requirePerm('rollout.manage');
  const r = releaseById(p.id);
  const b = req.body;
  r.rollout = {
    ...(r.rollout ?? { status: 'PENDING', stage_no: 0, percentage: 0, started_at: null, paused_at: null, completed_at: null, progress: [] }),
    mode: b.mode,
    stages: b.stages,
    auto_advance: b.auto_advance,
    auto_pause: b.auto_pause,
    thresholds: b.thresholds,
    rules: b.rules ?? [],
  } as AdminReleaseDetail['rollout'];
  r.rollout!.progress = b.stages.map((s: number, i: number) => r.rollout!.progress[i] ?? { stage: i + 1, percentage: s, eligible: Math.round((2481 * s) / 100), updated: 0, failed: 0, rolled_back: 0, success_rate: null });
  log('rollout.configure', 'release', r.id, null, null, b);
  return r.rollout;
});
route('POST', '/admin/releases/{id}/rollout/{action}', (req, p) => {
  requirePerm('rollout.manage');
  const r = releaseById(p.id);
  if (r.channel === 'STABLE') requireMfa(req);
  const ro = r.rollout;
  if (!ro) throw new MockHttpError(409, 'NO_ROLLOUT', 'This release has no rollout.');
  if (p.action === 'pause') Object.assign(ro, { status: 'PAUSED', paused_at: new Date().toISOString() });
  if (p.action === 'resume') Object.assign(ro, { status: 'ACTIVE', paused_at: null });
  if (p.action === 'increase') {
    const next = req.body?.percentage ?? ro.stages[ro.stage_no] ?? 100;
    const idx = ro.stages.findIndex((s) => s >= next);
    Object.assign(ro, { percentage: next, stage_no: idx >= 0 ? idx + 1 : ro.stages.length });
  }
  if (p.action === 'complete') Object.assign(ro, { status: 'DONE', percentage: 100, stage_no: ro.stages.length, completed_at: new Date().toISOString() });
  log(`rollout.${p.action}`, 'release', r.id, req.body?.reason ?? null, null, { percentage: ro.percentage });
  return ro;
});
route('GET', '/admin/releases/{id}/downloads', (_r, p) => {
  const r = releaseById(p.id);
  const by = r.packages.filter((x) => x.status === 'READY').map((x, i) => ({ label: `${x.platform} ${x.architecture} · ${x.package_type.toUpperCase()}`, platform: x.platform, architecture: x.architecture, package_type: x.package_type, count: Math.round(8491 / (i + 1)) }));
  return { total: by.reduce((s, x) => s + x.count, 0), by_package: by, series: Array.from({ length: 14 }, (_, i) => ({ date: ago((13 - i) * 1440).slice(0, 10), count: Math.round(400 + 900 * Math.exp(-i / 5) + (i * 53) % 120) })) };
});
route('GET', '/admin/releases/{id}/devices', () => ({
  adoption: [{ version: '2.7.0', count: 1290, pct: 52 }, { version: '2.6.5', count: 794, pct: 32 }, { version: '2.6.4', count: 160, pct: 6 }, { version: 'older', count: 237, pct: 10 }],
  update_success_rate: 98.6,
  failures_by_code: [{ error_code: 'MSI_1603', count: 36 }, { error_code: 'SIGNATURE_MISMATCH', count: 2 }, { error_code: 'DISK_FULL', count: 9 }, { error_code: 'DOWNLOAD_TIMEOUT', count: 14 }],
  rolled_back: 7,
  events_by_type: { checked: 5210, downloaded: 1402, installed: 1290, failed: 61, rolled_back: 7 },
}));
route('GET', '/admin/releases/{id}/activity', (_r, p) => ({ data: state.audit.filter((a) => a.entity_id === p.id) }));

// ---- packages
route('POST', '/admin/releases/{id}/packages', (req, p) => {
  const r = releaseById(p.id);
  const b = req.body ?? {};
  const post = ['PUBLISHED', 'PAUSED'].includes(r.status);
  requirePerm(post ? 'release.file.replace' : 'release.file.upload');
  if (post) requireMfa(req);
  let pkg = r.packages.find((x) => x.platform === b.platform && x.architecture === b.architecture && x.package_type === b.package_type && (x.edition ?? null) === (b.edition ?? null));
  if (pkg?.active_revision && pkg.status === 'READY' && !b.reason) throw new MockHttpError(422, 'VALIDATION_FAILED', 'A reason is required to replace a ready package', { reason: 'Explain why the file is being replaced' });
  if (!pkg) {
    pkg = { id: newId('pkg'), platform: b.platform, architecture: b.architecture, package_type: b.package_type, edition: b.edition ?? null, scope: b.edition === 'ALL' ? 'core-patch' : b.edition ? 'full' : null, status: 'EMPTY', health: 'UNKNOWN', minimum_os_version: null, active_revision: null, revisions: [] };
    r.packages.push(pkg);
  }
  const app = appById(r.app.id);
  const rev: Revision = {
    id: newId('rev'),
    revision_number: pkg.revisions.length + 1,
    file_name: b.file_name || fileNameFor(app, r.version, b.platform, b.architecture, b.package_type, b.edition),
    size: Number(b.size ?? 0),
    sha256: null,
    status: 'UPLOADING',
    reject_code: null,
    signature_status: null,
    signature_detail: null,
    scan_status: null,
    scan_detail: null,
    attestation_match: null,
    metadata: app.kind === 'SERVER' ? serverMetadata(r.version, (b.edition ?? 'ALL') as Edition) : {},
    pipeline: PIPELINE_ORDER.map((step, i) => ({ step, status: i === 0 ? 'running' : 'pending', detail: null, at: null })),
    replace_reason: b.reason ?? null,
    uploaded_by: currentUser()?.name ?? null,
    uploaded_at: new Date().toISOString(),
  };
  if (rev.metadata.validation) rev.metadata.validation = rev.metadata.validation.map((v) => ({ ...v, status: 'pending' }));
  pkg.revisions.push(rev);
  const uploadId = newId('upl');
  state.uploads[uploadId] = { releaseId: r.id, pkgId: pkg.id, size: rev.size, offset: 0, fileName: rev.file_name, created: false, failedOnce: false };
  return ok({ package: pkg, upload: { id: uploadId, protocol: 'tus', tus_endpoint: '/api/v1/uploads/tus', chunk_size: 8388608 } }, 201);
});
route('GET', '/admin/releases/{id}/packages/{pkg}', (_r, p) => {
  advancePipelines();
  const pkg = releaseById(p.id).packages.find((x) => x.id === p.pkg);
  if (!pkg) throw new MockHttpError(404, 'NOT_FOUND', 'Package not found');
  return pkg;
});
route('DELETE', '/admin/releases/{id}/packages/{pkg}', (req, p) => {
  requirePerm('release.file.delete');
  requireMfa(req);
  const r = releaseById(p.id);
  const pkg = r.packages.find((x) => x.id === p.pkg);
  if (!pkg) throw new MockHttpError(404, 'NOT_FOUND', 'Package not found');
  if (req.body?.confirm_file_name !== pkg.active_revision?.file_name) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Type the file name to confirm', { confirm_file_name: 'Does not match' });
  pkg.revisions.forEach((rv) => (rv.status = 'REPLACED'));
  pkg.active_revision = null;
  pkg.status = 'EMPTY';
  log('package.delete', 'release', r.id, req.body.reason);
  return { ok: true };
});

// ---- server
route('PUT', '/admin/releases/{id}/server-meta', (req, p) => {
  const r = releaseById(p.id);
  r.server_meta = { ...r.server_meta!, ...req.body };
  return r.server_meta;
});
route('GET', '/admin/releases/{id}/campaign', (_r, p) => releaseById(p.id).campaign ?? defaultCampaign());
route('PUT', '/admin/releases/{id}/campaign', (req, p) => {
  requirePerm('campaign.manage');
  const r = releaseById(p.id);
  r.campaign = { ...(r.campaign ?? defaultCampaign()), ...req.body };
  log('campaign.update', 'release', r.id, null, null, req.body);
  return r.campaign;
});
route('GET', '/admin/installations', (req) => {
  requirePerm('installations.view');
  const ed = req.query.get('edition');
  const mode = req.query.get('mode');
  const status = req.query.get('status');
  const version = req.query.get('version');
  const segment = req.query.get('segment');
  const q = (req.query.get('q') ?? '').toLowerCase();
  const stale = (i: Installation) => !i.offline_mode && (!i.last_report_at || Date.now() - new Date(i.last_report_at).getTime() > 86_400_000);
  const rows = state.installations
    .filter((i) => (!ed || i.edition === ed) && (!mode || i.mode === mode) && (!status || i.support_state === status || i.last_run_status === status))
    .filter((i) => (!version || i.version === version) && (!q || `${i.name} ${i.installation_id}`.toLowerCase().includes(q)))
    .filter((i) => !segment || (segment === 'below_minimum' ? i.below_minimum : segment === 'on_hold' ? i.last_run_status === 'ROLLED_BACK' : segment === 'stale' ? stale(i) : segment === 'offline' ? i.offline_mode : true));
  return paginate(rows, req.query);
});
route('GET', '/admin/installations/stats', () => {
  const by: Record<string, { version: string; count: number }[]> = {};
  for (const i of state.installations) {
    const list = (by[i.edition] ??= []);
    const e = list.find((x) => x.version === i.version);
    if (e) e.count += 34;
    else list.push({ version: i.version, count: 34 + (i.name.length % 7) * 3 });
  }
  return {
    by_edition: by,
    pending_updates: 412,
    failed_runs: state.installations.filter((i) => i.last_run_status === 'FAILED').map((i) => ({ installation_id: i.installation_id, name: i.name, version: '3.3.2', error: 'Pre-check failed: PHP extension sodium missing', at: i.last_report_at })),
    rolled_back: state.installations.filter((i) => i.last_run_status === 'ROLLED_BACK').map((i) => ({ installation_id: i.installation_id, name: i.name, version: '3.3.2', at: i.last_report_at })),
    unsupported: state.installations.filter((i) => i.support_state === 'UNSUPPORTED').length * 11,
    segments: {
      all: state.installations.length,
      on_latest: state.installations.filter((i) => i.version === '3.3.2').length,
      latest_stable: '3.3.2',
      below_minimum: state.installations.filter((i) => i.below_minimum).length,
      on_hold: state.installations.filter((i) => i.last_run_status === 'ROLLED_BACK').length,
      stale: state.installations.filter((i) => !i.offline_mode && (!i.last_report_at || Date.now() - new Date(i.last_report_at).getTime() > 86_400_000)).length,
    },
    adoption: serverReleases()
      .filter((r) => ['PUBLISHED', 'SUPERSEDED', 'WITHDRAWN', 'PAUSED'].includes(r.status))
      .map((r) => {
        const n = state.installations.filter((i) => i.version === r.version).length;
        return { release_id: r.id, version: r.version, status: r.status, channel: r.channel, installations: n, pct: Math.round((1000 * n) / Math.max(1, state.installations.length)) / 10 };
      }),
  };
});

// ---- server release console (WS-CORE-SYSUPDATE DL-1…DL-4)
const RING_HOLD = [24, 48, 72, 336];
const RING_NAMES = ['Ring 0 · Canary', 'Ring 1 · Early', 'Ring 2 · Broad', 'Ring 3 · Conservative'];
const ringState: Record<string, { opened: (string | null)[]; extended: number[]; pause: ServerRollout['pause'] }> = {};
function serverReleases() {
  return state.releases.filter((r) => r.app.kind === 'SERVER');
}
function severityOf(r: AdminReleaseDetail): ServerSeverity {
  if (r.server_meta?.severity) return r.server_meta.severity;
  if (r.release_type === 'CRITICAL') return 'CRITICAL';
  const [, minor, patch] = r.version.split('.').map(Number);
  return patch ? 'PATCH' : minor ? 'MINOR' : 'MAJOR';
}
function rings(r: AdminReleaseDetail) {
  const published = r.published_at ? new Date(r.published_at).getTime() : Date.now();
  return (ringState[r.id] ??= { opened: [new Date(published).toISOString(), new Date(published + 24 * 3_600_000).toISOString(), null, null], extended: [0, 0, 0, 0], pause: null });
}
function serverRow(r: AdminReleaseDetail) {
  const eds = r.packages.filter((p) => p.status === 'READY').map((p) => p.edition!).filter(Boolean);
  const editions = eds.length ? eds : ((r.server_meta?.editions ?? ['FREE', 'BUSINESS', 'ENTERPRISE']) as Edition[]);
  const label = (e: string) => (e === 'FREE' ? 'Professional' : e === 'ALL' ? 'All editions (core patch)' : e.charAt(0) + e.slice(1).toLowerCase());
  const on = state.installations.filter((i) => i.version === r.version).length;
  const rs = ['PUBLISHED', 'PAUSED', 'SUPERSEDED', 'WITHDRAWN'].includes(r.status) ? rings(r) : null;
  const cur = rs ? rs.opened.reduce((m, o, i) => (o ? i : m), 0) : 0;
  const summary = !rs ? (r.status === 'APPROVED' ? 'Ready to publish' : ['READY_FOR_TEST', 'TESTING'].includes(r.status) ? 'Waiting for QA' : 'Draft') : r.status === 'WITHDRAWN' ? `Stopped at ring ${cur}` : rs.pause ? `Paused at ring ${cur}` : r.status === 'SUPERSEDED' ? 'Ring 3 · complete' : `Ring ${cur} · open`;
  return { ...listRelease(r), severity: severityOf(r), editions, editions_label: editions.map(label).join(', '), ring_summary: summary, adoption_pct: Math.round((1000 * on) / Math.max(1, state.installations.length)) / 10, installations_on_version: on, commit: r.server_meta?.commit ?? null };
}
const GROUPS: Record<string, string[]> = { draft: ['DRAFT', 'UPLOADING', 'READY_FOR_TEST'], testing: ['TESTING', 'APPROVED'], published: ['PUBLISHED', 'SUPERSEDED'], paused: ['PAUSED'], withdrawn: ['WITHDRAWN'] };
route('GET', '/admin/server/releases', (req) => {
  requirePerm('release.view');
  const ch = req.query.get('channel');
  const st = req.query.get('status');
  const rows = serverReleases()
    .filter((r) => (!ch || r.channel === ch) && (!st || (GROUPS[st] ?? [st]).includes(r.status)))
    .sort((a, b) => compareSemver(b.version, a.version))
    .map(serverRow);
  const inst = state.installations;
  const onLatest = inst.filter((i) => i.version === '3.3.2').length;
  return {
    kpis: { linked: inst.length, online: inst.filter((i) => !i.offline_mode).length, offline: inst.filter((i) => i.offline_mode).length, latest_stable: '3.3.2', on_latest_stable: onLatest, on_latest_pct: Math.round((1000 * onLatest) / inst.length) / 10, below_minimum: inst.filter((i) => i.below_minimum).length, minimum_stable: '3.2.0', rollbacks_30d: inst.filter((i) => i.last_run_status === 'ROLLED_BACK').length, rollback_versions: ['3.3.2'] },
    data: rows,
    minimums: [{ channel: 'STABLE', minimum_version: '3.2.0', enforce_from: null }, { channel: 'BETA', minimum_version: null, enforce_from: null }, { channel: 'LTS', minimum_version: '3.0.0', enforce_from: null }],
  };
});
route('POST', '/admin/server/releases', (req) => {
  const b = req.body ?? {};
  const out = handleMock({ method: 'POST', path: '/admin/releases', query: new URLSearchParams(), headers: req.headers, body: { app_id: 'app_server', version: b.version, build: b.build, channel: b.channel, notes: b.notes, known_issues: b.known_issues, release_type: b.severity === 'CRITICAL' ? 'CRITICAL' : b.severity === 'SECURITY' ? 'MANDATORY' : 'RECOMMENDED' } });
  const created = out.body as { id: string };
  const r = releaseById(created.id);
  const editions = (b.editions ?? ['FREE', 'BUSINESS', 'ENTERPRISE']) as Edition[];
  r.packages = r.packages.filter((p) => editions.includes(p.edition as Edition));
  r.server_meta = { ...r.server_meta!, min_from_version: b.min_from_version ?? null, desktop_compat: b.desktop_compat ?? null, admin_notice: b.admin_notice ?? null, severity: b.severity, commit: b.commit ?? null, upgrade_notes: b.upgrade_notes ?? null, migrations_reversible: b.migrations_reversible ?? null, editions };
  if (b.notes) r.changelog = { ...r.changelog, summary: `WorkSuite Server ${r.version}`, markdown: b.notes, html: renderMarkdown(b.notes), sections: { ...emptySections(), ...(parseSections(b.notes) as Partial<ChangelogSections>) } };
  return ok({ ...listRelease(r), severity: b.severity }, 201);
});
route('PATCH', '/admin/server/releases/{id}', (req, p) => {
  requirePerm('release.edit');
  const r = releaseById(p.id);
  r.server_meta = { ...r.server_meta!, ...req.body };
  return { ...listRelease(r), severity: severityOf(r) };
});
route('POST', '/admin/server/releases/{id}/publish', (req, p) => {
  const u = requirePerm('release.publish');
  const r = releaseById(p.id);
  if (req.body?.confirm_version !== r.version) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Type the version to confirm', { confirm_version: `Type ${r.version} to confirm` });
  if (r.approvals.some((a) => a.kind === 'QA' && a.decision === 'PASS' && a.user === u.name)) throw new MockHttpError(403, 'SOD_VIOLATION', 'The QA approver cannot also publish this server release');
  requireMfa(req);
  if (r.status === 'TESTING') r.status = 'APPROVED';
  const res = handleMock({ method: 'POST', path: `/admin/releases/${p.id}/publish`, query: new URLSearchParams(), headers: req.headers, body: req.body });
  delete ringState[r.id];
  ringState[r.id] = { opened: [new Date().toISOString(), null, null, null], extended: [0, 0, 0, 0], pause: null };
  return res.body;
});
function rolloutView(r: AdminReleaseDetail): ServerRollout {
  const rs = rings(r);
  const compressed = ['SECURITY', 'CRITICAL'].includes(severityOf(r));
  const now = Date.now();
  const byRing = [0, 1, 2, 3].map((n) => state.installations.filter((i) => (i.ring ?? 2) === n));
  const ringsOut = [0, 1, 2, 3].map((n) => {
    const opened = rs.opened[n];
    const holdEnd = opened ? new Date(opened).getTime() + (RING_HOLD[n] + rs.extended[n]) * 3_600_000 : null;
    const next = rs.opened.findIndex((o) => !o);
    const st: RingState = opened ? (holdEnd! <= now ? 'DONE' : 'OPEN') : rs.pause && next === n ? 'PAUSED' : 'WAITING';
    const prev = n === 0 ? null : compressed && n === 2 ? 0 : n - 1;
    const detail = st === 'DONE' ? `${n === 0 ? 'Livezen-hosted and internal · ' : ''}held ${RING_HOLD[n] + rs.extended[n]} h` : st === 'OPEN' ? `Next ring opens in ${Math.max(0, Math.ceil((holdEnd! - now) / 3_600_000))} h` : st === 'PAUSED' ? `Paused: ${rs.pause?.reason ?? ''}` : prev === null ? 'Opens at publish' : `Opens after ${RING_HOLD[prev] + rs.extended[prev]} h of ring ${prev}`;
    const updated = byRing[n].filter((i) => compareSemver(i.version, r.version) >= 0).length;
    return { ring: n as 0 | 1 | 2 | 3, key: (['canary', 'early', 'broad', 'conservative'] as const)[n], name: RING_NAMES[n], state: st, opened_at: opened, opens_at: opened, hold_hours: RING_HOLD[n], extended_hours: rs.extended[n], hold_ends_at: holdEnd ? new Date(holdEnd).toISOString() : null, installations: byRing[n].length, updated, detail };
  });
  const signals: GateSignal[] = [
    { key: 'rollback_rate', label: 'Rollback rate', value: 0, display: '0 %', threshold: 'over 2 %', state: 'OK' },
    { key: 'failed_runs', label: 'Failed runs', value: 3.2, display: '1 of 31 · 3.2 %', threshold: 'over 5 %', state: 'WATCH' },
    { key: 'crash_free', label: 'Crash-free sessions', value: 99.6, display: '99.6 %', threshold: 'under 99.0 %, or down 0.5 pt', state: 'OK' },
    { key: 'new_p0p1', label: 'New P0/P1 on 3+ installations', value: 0, display: '0', threshold: '1 or more', state: 'OK' },
  ];
  return {
    release: { id: r.id, version: r.version, build: r.build, status: r.status, channel: r.channel, severity: severityOf(r), published_at: r.published_at, withdrawn_at: r.withdrawn_at },
    status: r.status === 'WITHDRAWN' ? 'DONE' : rs.pause ? 'PAUSED' : 'ACTIVE',
    mode: 'RINGS',
    compressed,
    pause: rs.pause,
    rings: ringsOut,
    gate: { state: 'WATCH', signals, reasons: [], runs: 31, evaluated_at: new Date().toISOString() },
    outcomes: { updated: 30, auto_scheduled: 27, waiting_for_admin: 74, postponed: 5, offline: 12, held: 0, skipped: 0 },
    campaign: r.campaign ? { ...r.campaign, delivered: { in_app: 136, email: 211 } } : null,
  };
}
route('GET', '/admin/server/releases/{id}/rollout', (_r, p) => {
  requirePerm('rollout.view');
  return rolloutView(releaseById(p.id));
});
route('POST', '/admin/server/releases/{id}/rollout/{action}', (req, p) => {
  const u = requirePerm('release.publish');
  requireMfa(req);
  const r = releaseById(p.id);
  const rs = rings(r);
  const next = rs.opened.findIndex((o) => !o);
  const cur = rs.opened.reduce((m, o, i) => (o ? i : m), 0);
  if (p.action === 'pause') rs.pause = { kind: 'MANUAL', reason: req.body?.reason ?? '', at: new Date().toISOString(), by: u.name, ring: next < 0 ? null : next };
  else if (p.action === 'resume') rs.pause = null;
  else if (p.action === 'extend') rs.extended[cur] += Number(req.body?.hours ?? 24);
  else throw new MockHttpError(404, 'UNKNOWN_ACTION', 'Unknown ring action');
  log(`server.ring_${p.action}`, 'release', r.id, req.body?.reason ?? null);
  return rolloutView(r);
});
route('POST', '/admin/fleet/{release}/{action}', (req, p) => {
  requirePerm('fleet.manage');
  requireMfa(req);
  const r = releaseById(p.release);
  const c = (r.campaign ??= defaultCampaign());
  const map: Record<string, string> = { start: 'RUNNING', pause: 'PAUSED', resume: 'RUNNING', advance: 'RUNNING' };
  c.fleet_status = map[p.action];
  c.fleet_stage_no = p.action === 'start' ? 1 : p.action === 'advance' ? Math.min((c.fleet_stage_no ?? 0) + 1, c.fleet_stages.length) : c.fleet_stage_no;
  log(`fleet.${p.action}`, 'release', r.id);
  return c;
});

// ---- analytics
const COUNTRIES = [['LK', 9120], ['IN', 2410], ['AE', 1840], ['GB', 1320], ['AU', 990], ['SG', 870], ['US', 760], ['MV', 420]] as const;
function downloadsAnalytics(q: URLSearchParams) {
  const group = q.get('group') ?? 'day';
  const n = group === 'month' ? 6 : group === 'week' ? 12 : 30;
  const series = Array.from({ length: n }, (_, i) => ({ period: ago((n - 1 - i) * (group === 'month' ? 43200 : group === 'week' ? 10080 : 1440)).slice(0, group === 'month' ? 7 : 10), count: Math.round(380 + 260 * Math.sin(i / 3) + (i === n - 2 ? 1400 : 0) + ((i * 97) % 140)) }));
  return {
    total: series.reduce((s, x) => s + x.count, 0),
    bots_excluded: 1284,
    series,
    by_platform: [{ platform: 'windows', count: 11240 }, { platform: 'macos', count: 4630 }, { platform: 'linux', count: 1810 }, { platform: 'server', count: 732 }],
    by_package: [{ label: 'Windows x64 · EXE', count: 8491 }, { label: 'Windows x64 · MSI', count: 2105 }, { label: 'macOS Apple Silicon · DMG', count: 3410 }, { label: 'macOS Intel · DMG', count: 1220 }, { label: 'Linux · AppImage', count: 980 }, { label: 'Windows ARM64 · EXE', count: 644 }, { label: 'Linux · DEB', count: 612 }, { label: 'Linux · RPM', count: 218 }],
    by_country: COUNTRIES.map(([country, count]) => ({ country, count })),
    top_releases: [{ release_id: 'rel_desktop_270', version: '2.7.0', app_name: 'WorkSuite Desktop', count: 11074 }, { release_id: 'rel_desktop_265', version: '2.6.5', app_name: 'WorkSuite Desktop', count: 3921 }, { release_id: 'rel_installer_132', version: '1.3.2', app_name: 'WorkSuite Installer', count: 1422 }],
  };
}
route('GET', '/admin/analytics/downloads', (req) => {
  requirePerm('downloads.view');
  const d = downloadsAnalytics(req.query);
  if (req.query.get('format') === 'csv') return { text: `period,count\n${d.series.map((s) => `${s.period},${s.count}`).join('\n')}\n`, contentType: 'text/csv' };
  return d;
});
route('GET', '/admin/analytics/devices', () => {
  requirePerm('devices.view');
  return {
    active_devices: 2481,
    by_version: [
      { version: '2.7.0', count: 1290, pct: 52, state: 'CURRENT' },
      { version: '2.6.5', count: 794, pct: 32, state: 'CURRENT' },
      { version: '2.6.4', count: 160, pct: 6.4, state: 'UPDATE_AVAILABLE' },
      { version: '2.5.3', count: 113, pct: 4.6, state: 'UPDATE_AVAILABLE' },
      { version: '2.4.9', count: 124, pct: 5, state: 'UNSUPPORTED' },
    ],
    by_platform: [{ platform: 'windows', count: 1602 }, { platform: 'macos', count: 671 }, { platform: 'linux', count: 208 }],
    by_channel: [{ channel: 'STABLE', count: 2310 }, { channel: 'BETA', count: 142 }, { channel: 'INTERNAL', count: 29 }],
    current_pct: 84,
    update_available_pct: 11,
    unsupported_pct: 5,
  };
});
route('GET', '/admin/analytics/update-status', () => {
  requirePerm('devices.view');
  return {
    releases: [
      { release_id: 'rel_desktop_270', version: '2.7.0', checked: 5210, downloaded: 1402, installed: 1290, failed: 61, rolled_back: 7, success_rate: 98.6 },
      { release_id: 'rel_desktop_265', version: '2.6.5', checked: 8120, downloaded: 2040, installed: 1998, failed: 30, rolled_back: 2, success_rate: 99.4 },
      { release_id: 'rel_syncagent_095', version: 'Sync Agent 0.9.5', checked: 610, downloaded: 212, installed: 198, failed: 9, rolled_back: 5, success_rate: 95.9 },
    ],
    failures_by_code: [{ error_code: 'MSI_1603', count: 36 }, { error_code: 'DOWNLOAD_TIMEOUT', count: 14 }, { error_code: 'DISK_FULL', count: 9 }, { error_code: 'SIGNATURE_MISMATCH', count: 2 }],
  };
});

// ---- administration
route('GET', '/admin/users', () => {
  requirePerm('user.manage');
  return { data: state.users.map(({ password: _pw, passkeys: _pk, beta_opt_in: _b, ...u }) => u) };
});
route('POST', '/admin/users', (req) => {
  requirePerm('user.manage');
  const b = req.body ?? {};
  if (state.users.some((u) => u.email === b.email)) throw new MockHttpError(409, 'USER_EXISTS', 'A user with this email already exists.');
  if (String(b.password ?? '').length < 12) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { password: 'Use at least 12 characters' });
  const u: MockUser = { id: newId('usr'), email: b.email, name: b.name, kind: 'STAFF', status: 'ACTIVE', roles: b.roles ?? [], mfa_enabled: false, last_login_at: null, created_at: new Date().toISOString(), password: b.password, passkeys: 0, beta_opt_in: false };
  state.users.push(u);
  log('user.create', 'user', u.id, null, null, { email: u.email, roles: u.roles });
  return ok(u, 201);
});
route('PATCH', '/admin/users/{id}', (req, p) => {
  requirePerm('user.manage');
  const u = state.users.find((x) => x.id === p.id);
  if (!u) throw new MockHttpError(404, 'NOT_FOUND', 'User not found');
  const before = { status: u.status, roles: u.roles };
  Object.assign(u, req.body);
  log('user.update', 'user', u.id, null, before, req.body);
  return u;
});
route('POST', '/admin/users/{id}/reset-mfa', (req, p) => {
  requirePerm('user.manage');
  requireMfa(req);
  const u = state.users.find((x) => x.id === p.id)!;
  u.mfa_enabled = false;
  u.passkeys = 0;
  log('user.reset_mfa', 'user', u.id, req.body?.reason ?? null);
  return { ok: true };
});
route('GET', '/admin/roles', () => ({ roles: ROLES, permissions: PERMISSIONS }));
route('GET', '/admin/api-clients', () => {
  requirePerm('api_client.manage');
  return { data: state.apiClients };
});
route('POST', '/admin/api-clients', (req) => {
  requirePerm('api_client.manage');
  const b = req.body ?? {};
  if ((b.permissions ?? []).includes('release.publish')) throw new MockHttpError(422, 'VALIDATION_FAILED', 'CI tokens never hold release.publish', { permissions: 'CI tokens never hold release.publish' });
  const c = { id: newId('cli'), name: b.name, kind: b.kind ?? 'CI', client_id: `uc_ci_${Math.random().toString(16).slice(2, 8)}`, permissions: b.permissions ?? [], ip_allowlist: b.ip_allowlist ?? [], expires_at: b.expires_at ?? null, last_used_at: null, rotated_at: null, revoked_at: null, created_at: new Date().toISOString() };
  state.apiClients.unshift(c);
  log('api_client.create', 'api_client', c.id);
  return ok({ client: c, secret: `ucs_${fakeSha(c.id)}` }, 201);
});
route('POST', '/admin/api-clients/{id}/rotate', (_r, p) => {
  requirePerm('api_client.manage');
  const c = state.apiClients.find((x) => x.id === p.id)!;
  c.rotated_at = new Date().toISOString();
  log('api_client.rotate', 'api_client', c.id);
  return { secret: `ucs_${fakeSha(c.id + c.rotated_at)}` };
});
route('POST', '/admin/api-clients/{id}/revoke', (_r, p) => {
  requirePerm('api_client.manage');
  const c = state.apiClients.find((x) => x.id === p.id)!;
  c.revoked_at = new Date().toISOString();
  log('api_client.revoke', 'api_client', c.id);
  return { ok: true };
});
route('GET', '/admin/storage', () => state.storage);
route('POST', '/admin/storage/{id}/test', (_r, p) => {
  const pr = state.storage.providers.find((x) => x.id === p.id);
  if (!pr) throw new MockHttpError(404, 'NOT_FOUND', 'Provider not found');
  pr.last_check_at = new Date().toISOString();
  return { ok: true, status: 'OK', latency_ms: 38 + Math.round(Math.random() * 10), detail: 'PUT/HEAD/GET/DELETE probe object succeeded' };
});
route('POST', '/admin/storage/probe', () => ({ ok: true, checked: 311, degraded: 0 }));
route('POST', '/admin/storage/{id}/activate', (_r, p) => {
  requirePerm('storage.manage');
  state.storage.migrations.unshift({ id: newId('mig'), from: 'stp_r2', to: p.id, status: 'COPYING', progress: 0 });
  return { ok: true };
});
route('GET', '/admin/signing-keys', () => ({ data: state.signingKeys }));
route('POST', '/admin/signing-keys/rotate', (req) => {
  requirePerm('signing_key.rotate');
  requireMfa(req);
  if (!req.body?.approver_code) throw new MockHttpError(422, 'VALIDATION_FAILED', 'Check the highlighted fields', { approver_code: 'Second approver code required' });
  log('signing_key.rotate', 'signing_key', req.body.new_key_id, req.body.reason ?? null);
  return { ok: true };
});
route('GET', '/admin/audit', (req) => {
  requirePerm('audit.view');
  const q = req.query;
  const rows = state.audit.filter(
    (a) => (!q.get('action') || a.action.includes(q.get('action')!)) && (!q.get('entity_type') || a.entity_type === q.get('entity_type')) && (!q.get('entity_id') || a.entity_id === q.get('entity_id')) && (!q.get('actor') || (a.actor_label ?? '').toLowerCase().includes(q.get('actor')!.toLowerCase())),
  );
  if (q.get('format') === 'csv') return { text: `id,created_at,actor,action,entity_type,entity_id,reason,hash\n${rows.map((a) => [a.id, a.created_at, a.actor_label, a.action, a.entity_type, a.entity_id, a.reason ?? '', a.hash].join(',')).join('\n')}\n`, contentType: 'text/csv' };
  return paginate(rows, q);
});
route('GET', '/admin/audit/verify', () => ({ ok: true, checked: state.audit.length + 1030, broken_at: null }));
route('GET', '/admin/settings', () => state.settings);
route('PUT', '/admin/settings', (req) => {
  requirePerm('security.manage');
  Object.assign(state.settings, req.body);
  log('settings.update', 'settings', null, null, null, req.body);
  return state.settings;
});

// ---- bug trace
const BT = '/admin/bugtrace';
route('GET', `${BT}/issues`, (req) => {
  requirePerm('bugtrace.view');
  const q = req.query;
  const status = q.get('status');
  const statusSet = status === 'UNRESOLVED' ? ['NEW', 'TRIAGED', 'IN_PROGRESS', 'REGRESSED'] : status ? status.split(',') : null;
  const types = q.get('type')?.split(',');
  const term = (q.get('q') ?? '').toLowerCase();
  const sort = q.get('sort') ?? 'users';
  const rows = state.issues
    .filter((i) => !statusSet || statusSet.includes(i.status))
    .filter((i) => !q.get('platform') || i.project.platform === q.get('platform'))
    .filter((i) => !types || types.includes(i.type))
    .filter((i) => !q.get('release') || i.last_release === q.get('release') || i.first_release === q.get('release'))
    .filter((i) => !term || `${i.id} ${i.title} ${i.culprit}`.toLowerCase().includes(term))
    .sort((a, b) => (sort === 'events' ? b.events_count - a.events_count : sort === 'first_seen' ? b.first_seen.localeCompare(a.first_seen) : sort === 'last_seen' ? b.last_seen.localeCompare(a.last_seen) : b.users_count - a.users_count || b.events_count - a.events_count));
  return {
    ...paginate(rows.map(({ latest_event: _e, frames: _f, breadcrumbs: _b, tags: _t, distributions: _d, releases: _r, hourly: _h, reports: _rp, comments: _c, links: _l, ...i }) => i), q),
    kpis: { unresolved: 128, regressed: state.issues.filter((i) => i.status === 'REGRESSED').length + 2, crash_free_users: 99.42, events_24h: 41200, untriaged_reports: state.reports.filter((r) => r.status === 'UNTRIAGED').length + 8 },
  };
});
route('GET', `${BT}/issues/{id}`, (_r, p) => {
  const i = state.issues.find((x) => x.id === decodeURIComponent(p.id));
  if (!i) throw new MockHttpError(404, 'NOT_FOUND', 'Issue not found');
  return i;
});
route('PATCH', `${BT}/issues/{id}`, (req, p) => {
  requirePerm('bugtrace.triage');
  const i = state.issues.find((x) => x.id === decodeURIComponent(p.id))!;
  const b = req.body ?? {};
  if (b.assignee_id !== undefined) {
    const u = state.users.find((x) => x.id === b.assignee_id);
    i.assignee = u ? { id: u.id, name: u.name } : null;
  }
  if (b.resolution) {
    i.status = 'RESOLVED';
    i.resolution = b.resolution;
    i.resolve_version = b.resolve_version ?? (b.resolution === 'NEXT_RELEASE' ? 'next release' : null);
  }
  for (const k of ['status', 'priority', 'labels'] as const) if (b[k] !== undefined) (i as any)[k] = b[k];
  if (b.status && b.status !== 'RESOLVED') i.resolution = null;
  i.comments.push({ id: newId('cmt'), user: currentUser()?.name ?? 'System', body: `Changed ${Object.keys(b).join(', ')}`, kind: 'activity', created_at: new Date().toISOString() });
  return i;
});
route('POST', `${BT}/issues/{id}/comments`, (req, p) => {
  const i = state.issues.find((x) => x.id === decodeURIComponent(p.id))!;
  i.comments.push({ id: newId('cmt'), user: currentUser()?.name ?? 'You', body: req.body.body, kind: 'comment', created_at: new Date().toISOString() });
  return { ok: true };
});
route('POST', `${BT}/issues/{id}/links`, (req, p) => {
  const i = state.issues.find((x) => x.id === decodeURIComponent(p.id))!;
  i.links.push(req.body);
  return { ok: true };
});
route('POST', `${BT}/issues/{id}/merge`, (req, p) => {
  const i = state.issues.find((x) => x.id === decodeURIComponent(p.id))!;
  i.status = 'DUPLICATE';
  i.comments.push({ id: newId('cmt'), user: currentUser()?.name ?? 'You', body: `Merged into ${req.body.into}`, kind: 'activity', created_at: new Date().toISOString() });
  return { ok: true };
});
route('POST', `${BT}/issues/bulk`, (req) => {
  for (const id of req.body.ids ?? []) {
    const i = state.issues.find((x) => x.id === id);
    if (i) Object.assign(i, req.body.patch);
  }
  return { ok: true };
});
route('GET', `${BT}/reports`, (req) => {
  requirePerm('bugtrace.view');
  const st = req.query.get('status');
  const term = (req.query.get('q') ?? '').toLowerCase();
  const counts: Record<string, number> = {};
  for (const r of state.reports) counts[r.status] = (counts[r.status] ?? 0) + 1;
  counts.UNTRIAGED = (counts.UNTRIAGED ?? 0) + 8;
  const rows = state.reports.filter((r) => (!st || st.split(',').includes(r.status)) && (!term || `${r.reference} ${r.title}`.toLowerCase().includes(term)));
  return { data: rows.map(({ description: _d, steps: _s, expected: _e, context: _c, attachments: _a, updates: _u, contact: _ct, ...r }) => r), counts };
});
route('GET', `${BT}/reports/{ref}`, (_r, p) => {
  const u = requirePerm('bugtrace.view');
  const r = state.reports.find((x) => x.reference === p.ref);
  if (!r) throw new MockHttpError(404, 'NOT_FOUND', 'Report not found');
  return { ...r, contact: permissionsFor(u.roles).includes('bugtrace.reporter.contact') ? r.contact : null };
});
route('PATCH', `${BT}/reports/{ref}`, (req, p) => {
  requirePerm('bugtrace.triage');
  const r = state.reports.find((x) => x.reference === p.ref)!;
  if (req.body.issue_id) {
    const i = state.issues.find((x) => x.id === req.body.issue_id);
    r.issue = i ? { id: i.id, title: i.title } : { id: req.body.issue_id, title: req.body.issue_id };
    r.status = 'LINKED';
    if (i && !i.reports.some((x) => x.reference === r.reference)) i.reports.push(r);
  }
  if (req.body.status) r.status = req.body.status;
  if (req.body.close_reason) r.updates.push({ at: new Date().toISOString(), message: `Closed: ${req.body.close_reason}`, public: true, by: currentUser()?.name });
  return r;
});
route('POST', `${BT}/reports/{ref}/reply`, (req, p) => {
  const r = state.reports.find((x) => x.reference === p.ref)!;
  r.updates.push({ at: new Date().toISOString(), message: req.body.message, public: true, by: currentUser()?.name });
  return { ok: true };
});
route('POST', `${BT}/reports/{ref}/create-issue`, (_r, p) => {
  const r = state.reports.find((x) => x.reference === p.ref)!;
  const base = state.issues[0];
  const i: typeof base = { ...structuredClone(base), id: `WS-${r.app.slice(0, 3).toUpperCase()}-${Math.random().toString(36).slice(2, 5).toUpperCase()}`, title: r.title, culprit: 'Created from user report', status: 'NEW', events_count: 0, users_count: 1, reports: [r], comments: [] };
  state.issues.unshift(i);
  r.issue = { id: i.id, title: i.title };
  r.status = 'LINKED';
  return { id: i.id };
});
route('GET', `${BT}/health`, () => ({
  releases: [
    { release: 'Desktop 2.7.0', release_id: 'rel_desktop_270', adoption_pct: 50, sessions: 18400, crash_free_sessions: 99.62, crash_free_users: 99.1, new_issues: 4, regressions: 1, reports: 7, update_failures: 36, gate: 'WARN' },
    { release: 'Desktop 2.6.5', release_id: 'rel_desktop_265', adoption_pct: 32, sessions: 12200, crash_free_sessions: 99.71, crash_free_users: 99.35, new_issues: 0, regressions: 0, reports: 1, update_failures: 0, gate: 'OK' },
    { release: 'Server/Web 3.4.0', release_id: 'rel_server_340', adoption_pct: 25, sessions: 8100, crash_free_sessions: 99.2, crash_free_users: 98.7, new_issues: 6, regressions: 0, reports: 9, update_failures: 2, gate: 'BLOCK' },
    { release: 'Mobile 1.3.2', release_id: null, adoption_pct: 81, sessions: 30100, crash_free_sessions: 99.84, crash_free_users: 99.6, new_issues: 2, regressions: 0, reports: 4, update_failures: 0, gate: 'OK' },
  ],
  series: Array.from({ length: 14 }, (_, i) => [
    { date: ago((13 - i) * 720).slice(0, 13), release: '3.4.0', crash_free_users: +(99.6 - (i > 3 ? (i - 3) * 0.1 : 0) + (i > 10 ? 0.05 : 0)).toFixed(2) },
    { date: ago((13 - i) * 720).slice(0, 13), release: '3.3.2', crash_free_users: +(99.72 - (i % 3) * 0.02).toFixed(2) },
  ]).flat(),
  gate_rules: { crash_free_users_below: 99.0, drop_vs_previous_pt: 0.5, new_p0_p1_with_users: 100, update_install_failures_pct: 5, action: 'Alert Release Manager · suggest pause', auto_pause: 'Off (per release opt-in)' },
}));
route('GET', `${BT}/alerts`, () => ({ data: state.alerts }));
route('POST', `${BT}/alerts`, (req) => {
  requirePerm('bugtrace.manage');
  const a = { id: newId('alr'), enabled: true, channels: [], ...req.body };
  state.alerts.push(a);
  return ok(a, 201);
});
route('PATCH', `${BT}/alerts/{id}`, (req, p) => {
  requirePerm('bugtrace.manage');
  const a = state.alerts.find((x) => x.id === p.id)!;
  Object.assign(a, req.body);
  return a;
});
route('DELETE', `${BT}/alerts/{id}`, (_r, p) => {
  requirePerm('bugtrace.manage');
  state.alerts = state.alerts.filter((x) => x.id !== p.id);
  return { ok: true };
});
route('GET', `${BT}/projects`, () => ({ data: [{ id: 'prj_web', name: 'WorkSuite Web', platform: 'web' }, { id: 'prj_desktop', name: 'WorkSuite Desktop', platform: 'desktop' }, { id: 'prj_mobile', name: 'WorkSuite Mobile', platform: 'mobile' }, { id: 'prj_server', name: 'WorkSuite Server', platform: 'server' }] }));

// ---- portal
route('GET', '/portal/downloads', () => {
  const u = requireUser();
  return {
    apps: state.apps
      .filter((a) => a.status === 'ACTIVE')
      .map((a) => {
        const chans = a.channels.filter((c) => c.enabled && (c.code === 'STABLE' || (c.code === 'BETA' && u.beta_opt_in))).map((c) => c.code);
        const latest_by_channel: Record<string, PublicReleaseDetail> = {};
        for (const ch of chans) {
          const r = releasesOf(a.id).filter((x) => x.channel === ch && x.status === 'PUBLISHED').sort((x, y) => compareSemver(y.version, x.version))[0];
          if (r) latest_by_channel[ch] = toPublicDetail(r);
        }
        return { ...appSummary(a), channels_available: chans, latest_by_channel };
      }),
  };
});
route('GET', '/portal/history', () => ({
  data: [
    { app: 'WorkSuite Desktop', version: '2.7.0', package: 'Windows x64 · EXE', created_at: ago(1500) },
    { app: 'WorkSuite Desktop', version: '2.8.0-beta.1', package: 'macOS Apple Silicon · DMG', created_at: ago(18000) },
    { app: 'Quick Support', version: '1.1.0', package: 'Windows x64 · EXE', created_at: ago(26000) },
  ],
}));
route('PUT', '/portal/profile', (req) => {
  const u = requireUser();
  u.name = req.body.name;
  return { ok: true };
});
route('POST', '/portal/beta', (req) => {
  const u = requireUser();
  u.beta_opt_in = !!req.body.opt_in;
  return { ok: true };
});
route('GET', '/portal/subscriptions', () => ({ data: state.subscriptions }));
route('PUT', '/portal/subscriptions', (req) => {
  state.subscriptions = req.body.items;
  return { ok: true };
});
route('GET', '/portal/bug-reports', () => ({
  data: [
    { reference: 'BR-20931', title: 'Mail page crashed when opening Finance folder', status: 'LINKED', updated_at: ago(12), fixed_in: null },
    { reference: 'BR-20850', title: 'Typo on login page', status: 'CLOSED', updated_at: ago(8000), fixed_in: '3.3.2' },
  ],
}));

// ---------------------------------------------------------------- dispatcher

export function handleMock(req: MockRequest): MockResult {
  if (req.method !== 'GET' && !req.path.startsWith('/auth/login') && !req.path.startsWith('/subscriptions') && !req.path.startsWith('/bugtrace/reports/public') && !req.path.startsWith('/auth/register') && !req.path.startsWith('/auth/password') && !req.path.startsWith('/auth/verify-email')) {
    if (req.headers['x-csrf-token'] !== session.csrf) throw new MockHttpError(403, 'CSRF_FAILED', 'Your session token expired. Reload the page.');
  }
  for (const r of routes) {
    if (r.method !== req.method) continue;
    const m = r.re.exec(req.path);
    if (!m) continue;
    const params: Record<string, string> = {};
    r.keys.forEach((k, i) => (params[k] = decodeURIComponent(m[i + 1])));
    const out = r.handler(req, params);
    if (out && typeof out === 'object' && ('status' in out || 'text' in out) && ('body' in out || 'text' in out)) return out as MockResult;
    return { status: 200, body: out };
  }
  throw new MockHttpError(404, 'NOT_FOUND', `Mock: no route for ${req.method} ${req.path}`);
}

// ---------------------------------------------------------------- tus (uploads)

export function tusCreate(uploadId: string, length: number): string {
  const u = state.uploads[uploadId];
  if (!u) throw new MockHttpError(404, 'NOT_FOUND', 'Unknown upload');
  u.created = true;
  u.size = length;
  return `/api/v1/uploads/tus/${uploadId}`;
}
export function tusHead(uploadId: string): { offset: number; length: number } {
  const u = state.uploads[uploadId];
  if (!u || !u.created) throw new MockHttpError(404, 'NOT_FOUND', 'Unknown upload');
  return { offset: u.offset, length: u.size };
}
export function tusPatch(uploadId: string, offset: number, bytes: number): number {
  const u = state.uploads[uploadId];
  if (!u) throw new MockHttpError(404, 'NOT_FOUND', 'Unknown upload');
  if (offset !== u.offset) throw new MockHttpError(409, 'OFFSET_MISMATCH', 'Offset mismatch');
  // Simulate one network drop mid-upload (first upload of the session) to demonstrate resume.
  if (!u.failedOnce && u.size > 16 * 1024 * 1024 && u.offset >= u.size * 0.3) {
    u.failedOnce = true;
    throw new Error('network drop');
  }
  u.offset += bytes;
  if (u.offset >= u.size) {
    const r = releaseById(u.releaseId);
    const p = r.packages.find((x) => x.id === u.pkgId)!;
    const rev = p.revisions[p.revisions.length - 1];
    startProcessing(r, p, rev);
  }
  return u.offset;
}
