# Update Center API contract (frontend ↔ backend)

Source of truth for the JSON shapes the React app consumes. Base path `/api/v1`.
All timestamps are ISO-8601 UTC strings (`2026-09-27T04:30:00Z`). Sizes are bytes.

## Conventions

* Errors: `{ "error": { "code": "CHECKLIST_FAILED", "message": "…", "details": {…} } }` (codes in SRS §10.8,
  plus `UNAUTHENTICATED` 401, `FORBIDDEN` 403, `VALIDATION_FAILED` 422 with `details` = field → message,
  `CSRF_FAILED` 403, `MFA_REQUIRED` 401, `MFA_INVALID` 401, `MFA_ENROLLMENT_REQUIRED` 403, `NOT_FOUND` 404).
* Lists: `{ "data": [...], "total": 123 }` (+ `limit`, `offset` when paginated; query `?limit=&offset=`).
* Auth for the SPA: cookie `uc_session` (HttpOnly) set by `/auth/login`. Every non-GET request must send
  header `X-CSRF-Token: <csrf>` — the value comes from the `csrf` field of `/auth/login`, `/auth/mfa` or `/auth/me`
  (also mirrored in the readable cookie `uc_csrf`). Always `credentials: 'include'`.
* Step-up MFA (critical actions: publish, withdraw, pause/resume, regenerate manifest, minimum/mandatory change,
  file delete/replace after publish, rollout changes on Stable, key rotation, privacy deletion): send header
  `X-MFA-Token: <6-digit TOTP or recovery code>`. If absent and no verification within 10 min → `401 MFA_REQUIRED`;
  the UI then shows `<MfaPrompt>` and retries with the header. `/auth/step-up` can pre-verify
  (valid 10 min; `step_up_valid_until` in `/auth/me`).
* Rates and shares are **percentages (0–100)**: `success_rate`, `update_success_rate`, `crash_free_*`, `pct`,
  `*_pct`, and rollout `thresholds.min_success_rate / max_failure_rate / max_rollback_rate`.
* Structured policy fields are JSON objects, not display strings: channel `access_rule` / `retention`, Bug Trace
  alert `threshold` (e.g. `{types:['crash']}`, `{multiplier, min_events}`, `{events, users}`, `{value}`).
* IDs in URLs are public ids (`app_…`, `rel_…`, `pkg_…`, `rev_…`, `usr_…`, `cli_…`), never numeric.

## Shared types (TypeScript)

```ts
type Platform = 'windows'|'macos'|'linux'|'android'|'ios'|'server';
type Arch = 'x64'|'arm64'|'universal'|'any';
type PackageType = 'exe'|'msi'|'dmg'|'pkg'|'zip'|'appimage'|'deb'|'rpm'|'apk'|'aab'|'ipa';
type ChannelCode = 'STABLE'|'BETA'|'DEV'|'INTERNAL'|'CUSTOM';
type ReleaseStatus = 'DRAFT'|'UPLOADING'|'READY_FOR_TEST'|'TESTING'|'APPROVED'|'PUBLISHED'|'PAUSED'|'WITHDRAWN'|'SUPERSEDED';
type ReleaseType = 'OPTIONAL'|'RECOMMENDED'|'MANDATORY'|'CRITICAL';
type SupportState = 'SUPPORTED'|'DEPRECATED'|'UNSUPPORTED'|'BLOCKED';
type Edition = 'FREE'|'BUSINESS'|'ENTERPRISE'|'ALL';

interface AppPlatform { platform: Platform; architecture: Arch; package_types: PackageType[];
  default_package_type: PackageType|null; required_for_publish: boolean; min_os_version: string|null }

interface AppSummary { id: string; slug: string; name: string; app_identifier: string;
  kind: 'DESKTOP'|'SERVER'|'MOBILE'|'OTHER'; description: string; icon: string|null;
  status: 'ACTIVE'|'HIDDEN'|'RETIRED'; public_download_enabled: boolean; private: boolean;
  latest_stable: { release_id: string; version: string; build: number; published_at: string; release_date: string|null }|null;
  latest_beta: { release_id: string; version: string; build: number; published_at: string }|null;
  platforms: AppPlatform[] }

interface PublicPackage { id: string; platform: Platform; architecture: Arch; package_type: PackageType;
  edition: Edition|null; label: string /* "Windows x64 · EXE" */; file_name: string; size: number;
  sha256: string; min_os: string|null; download_url: string /* resolver path, e.g. /download/desktop/2.7.0/windows/x64/exe */;
  recommended: boolean /* default type for that platform */; hint: string|null /* "IT / Intune" for MSI */ }

interface ChangelogSections { new_features: string[]; improvements: string[]; bug_fixes: string[];
  performance: string[]; security: string[]; known_issues: string[]; deprecated: string[]; removed: string[] }
interface Changelog { locale: string; summary: string|null; highlights: string[]; sections: ChangelogSections;
  markdown: string; html: string /* sanitised, rendered server-side */; version: number; updated_at: string|null }

interface PublicRelease { id: string; version: string; build: number; channel: ChannelCode;
  release_type: ReleaseType; support_state: SupportState; release_date: string|null; published_at: string|null;
  is_latest: boolean; summary: string|null; notes_url: string /* /apps/desktop/releases/2.7.0 */ }

interface PublicReleaseDetail extends PublicRelease { changelog: Changelog; packages: PublicPackage[];
  checksums_url: string /* /apps/desktop/releases/2.7.0/checksums.txt */; manifest_url: string /* /api/v1/releases/rel_…/manifest */;
  download_available: boolean; unavailable_message: string|null /* "Download unavailable. Please install 2.7.0." */;
  requirements: Record<string,string[]>; previous_versions: PreviousVersion[]; app: AppSummary }

interface PreviousVersion { version: string; build: number; channel: ChannelCode; release_date: string|null;
  support_state: SupportState; download_available: boolean; notes_url: string; is_latest: boolean }

interface DownloadPage { hero: { title: string; subtitle: string; screenshot?: string|null };
  description_md: string; description_html: string; requirements: Record<string,string[]>;
  install_help: Record<string,{ markdown: string; html: string }>; faq: { q: string; a: string; a_html: string }[];
  support_url: string|null; version: number; status: 'DRAFT'|'PUBLISHED' }

interface AuditEntry { id: number; actor_type: string; actor_id: string|null; actor_label: string|null;
  action: string; entity_type: string|null; entity_id: string|null; before: any; after: any;
  reason: string|null; ip: string|null; created_at: string; hash: string }
```

## Public site (no auth)

| Method & path | Response |
|---|---|
| `GET /site` | `{ environment: 'PRODUCTION'|'STAGING'|'DEV', name: 'WorkSuite Downloads', apps: AppSummary[] }` (public, ACTIVE apps only) |
| `GET /apps` | `{ data: AppSummary[] }` |
| `GET /apps/{slug}` | `AppSummary & { page: DownloadPage; latest: PublicReleaseDetail|null; channels: {code,name,description,is_public}[] }` |
| `GET /apps/{slug}/releases?channel=STABLE&limit=&offset=` | `{ data: PublicRelease[], total }` |
| `GET /apps/{slug}/releases/{version}?channel=` | `PublicReleaseDetail` (404 `RELEASE_NOT_AVAILABLE`; BLOCKED versions → 404) |
| `GET /apps/{slug}/versions` | `{ data: PreviousVersion[] }` (visibility PUBLIC; BLOCKED hidden) |
| `GET /apps/{slug}/changelog?limit=&offset=` | `{ data: (PublicRelease & { changelog: Changelog })[], total }` |
| `GET /changelog?limit=&offset=` | same as above, all public apps, each item also has `app: {slug,name}` |
| `GET /channels` | `{ data: {code,name,description,is_public,allow_direct_download}[] }` |
| `POST /subscriptions` `{email, app: slug, channel}` | `202 { status: 'pending_confirmation' }` |
| `GET /subscriptions/confirm?token=` / `GET /subscriptions/unsubscribe?token=` | `{ status }` |

Web (non-API) routes served by the backend: `/download/{slug}/{platform}[/{arch}][/{type}]`,
`/download/{slug}/{version}/{platform}/{arch}[/{type}]`, `/download/{slug}/{channel}/{platform}/{arch}` → `302` to CDN
or an HTML error page (503/410/404); `/apps/{slug}/releases/{version}/checksums.txt`; `/changelog/feed` (RSS 2.0),
`/changelog/atom`, `/apps/{slug}/changelog/feed`, `/apps/{slug}/changelog/atom`; `/sitemap.xml`; `/robots.txt`.

## Auth (`/api/v1/auth`)

| Method & path | Body | Response |
|---|---|---|
| `POST /auth/login` | `{email, password}` | `{ mfa_required: boolean, csrf: string, user: Me|null, methods: ('totp'|'passkey')[] }` — when `mfa_required`, session is pending until `/auth/mfa` or passkey |
| `POST /auth/mfa` | `{code}` | `{ user: Me, csrf }` |
| `POST /auth/passkey/options` | – (pending or signed in) | WebAuthn `publicKey` request options (base64url fields) |
| `POST /auth/passkey/verify` | `{id, clientDataJSON, authenticatorData, signature}` (base64url) | `{ user: Me, csrf }` |
| `POST /auth/logout` | – | `{ ok: true }` |
| `GET /auth/me` | – | `{ user: Me, csrf, environment, step_up_valid_until: string|null }` or 401 |
| `POST /auth/step-up` | `{code}` | `{ step_up_valid_until }` |
| `POST /auth/mfa/totp/setup` | – | `{ secret, otpauth_uri }` |
| `POST /auth/mfa/totp/confirm` | `{code}` | `{ recovery_codes: string[] }` |
| `POST /auth/passkeys/register/options` | – | WebAuthn creation options |
| `POST /auth/passkeys/register` | `{name, clientDataJSON, attestationObject}` | `{ ok: true }` |
| `POST /auth/password/forgot` | `{email}` | `202 {ok:true}` |
| `POST /auth/password/reset` | `{token, password}` | `{ok:true}` |
| `POST /auth/register` (portal) | `{email, password, name}` | `201 {status:'verification_sent'}` |
| `POST /auth/verify-email` | `{token}` | `{ok:true}` |

`Me = { id, email, name, kind: 'STAFF'|'PORTAL', mfa_enabled: boolean, passkeys: number, roles: string[], permissions: string[], beta_opt_in: boolean }`

## Admin (`/api/v1/admin`, staff session or API client)

### Dashboard & shell
* `GET /admin/dashboard` → `{ environment, kpis: { apps, apps_public, releases, releases_testing, releases_draft, downloads_30d, downloads_redirect_ok_30d, active_devices }, latest_releases: {id, app_name, app_slug, version, channel, status}[], version_distribution: { app_name, items: {version, count, pct}[], current_pct, update_available_pct, unsupported_pct }, downloads: { release_id, app_name, version, items: {label, count}[] }|null, active_rollouts: {release_id, app_name, version, channel, stage_no, stages: number[], percentage, success_rate: number|null, status}[], recent_activity: AuditEntry[], health: { ok: boolean, components: HealthComponent[] }, notifications_unread }`
* `GET /admin/notifications` → `{ data: {id, kind, payload:{title, body, link?}, read_at, created_at}[], unread }`; `POST /admin/notifications/{id}/read`; `POST /admin/notifications/read-all`
* `GET /admin/health` → `{ ok, environment, components: HealthComponent[], queue: {backlog, oldest_age_s, failed_24h} }` with `HealthComponent = { component: 'api'|'database'|'redis'|'storage'|'cdn'|'signing'|'queue', status: 'OK'|'DEGRADED'|'DOWN', latency_ms: number|null, detail: string, checked_at }`

### Applications
* `GET /admin/applications` → `{ data: AdminApp[] }`; `AdminApp = AppSummary & { default_channel: ChannelCode, channels: {code, enabled}[], manifest_settings: {schema:number, key_id:string|null, response_key_id:string|null, update_api_enabled:boolean}, security_settings: {private:boolean, requires_license_token:boolean, malware_scan_required:boolean, signatures: Record<Platform,string>}, show_previous_versions: boolean, releases_count: number, created_at }`
* `POST /admin/applications` (wizard §7.2) body: `{ name, slug, app_identifier, description, icon?, status, kind, platforms: {platform, architecture, package_types, required_for_publish, default_package_type, min_os_version}[], channels: ChannelCode[], default_channel: ChannelCode, public_download_enabled, show_previous_versions, manifest_settings, security_settings }` → `201 AdminApp` (409 `APP_EXISTS`)
* `GET /admin/applications/{id}` → `AdminApp`; `PATCH /admin/applications/{id}` (same fields, partial) → `AdminApp`
* `GET /admin/applications/{id}/minimum-versions` → `{ data: {channel: ChannelCode, platform: Platform|null, minimum_version, enforce_from, message, updated_at}[], history: {...,before_version, reason, updated_by, created_at}[] }`
* `GET /admin/applications/{id}/minimum-versions/impact?channel=&platform=&version=&enforce_from=` → `{ active_devices, update_required, unsupported, by_version: {version, count}[] }`
* `PUT /admin/applications/{id}/minimum-versions` (MFA) `{channel, platform|null, minimum_version, enforce_from, message, reason}` → same as GET
* `GET /admin/applications/{id}/download-page` → `{ published: DownloadPage, draft: DownloadPage|null, history: {version, updated_by, created_at}[] }`
* `PUT /admin/applications/{id}/download-page` `{hero, description_md, requirements, install_help: Record<string,string>, faq, support_url}` → saves draft; `POST /admin/applications/{id}/download-page/publish` → publishes draft; `POST /admin/content/preview` `{markdown}` → `{html}`
* `GET /admin/channels` → `{ data: {id, code, name, description, is_public, is_default, allow_direct_download, allow_auto_update, access_rule, retention}[] }`; `PATCH /admin/channels/{id}`
* `GET /admin/platforms` → `{ data: (AppPlatform & {app_id, app_name})[] }`

### Releases
`AdminRelease = { id, app: {id, slug, name, kind}, version, build, build_revision, channel: ChannelCode, release_type, status, support_state, visibility: 'PUBLIC'|'HIDDEN', minimum_supported_version, minimum_no_change: boolean, mandatory, mandatory_after, grace_minutes, rollback_supported, release_date, scheduled_publish_at, published_at, published_by: string|null, created_by: string|null, withdrawn_at, withdraw_reason, superseded_by: {id, version}|null, notes, known_issues, auto_pause_on_gate, last_error, created_at, updated_at, packages_ready: number, packages_total: number }`

* `GET /admin/releases?status=DRAFT,UPLOADING&app=slug&channel=STABLE&platform=windows&q=&limit&offset` → `{ data: AdminRelease[], total, counts: Record<ReleaseStatus, number> }`
* `POST /admin/releases` `{app_id, version, build, channel, release_type, release_date?, minimum_supported_version?, mandatory?, mandatory_after?, grace_minutes?, notes?, known_issues?}` → `201 AdminRelease` (409 `RELEASE_EXISTS`, 422 `BUILD_NOT_INCREASING`)
* `GET /admin/releases/{id}` → `AdminRelease & { packages: AdminPackage[], changelog: Changelog, checklist: Checklist, approvals: Approval[], rollout: Rollout|null, manifest: ManifestInfo|null, server_meta: ServerMeta|null, campaign: Campaign|null, allowed_actions: string[] }`
  * `allowed_actions` ⊆ `edit, upload, submit_test, start_testing, qa, approve, publish, pause, resume, withdraw, supersede, regenerate_manifest, replace_file, delete_file` (already filtered by status + caller permissions)
* `PATCH /admin/releases/{id}` (editable before publish; after publish only notes/known_issues/visibility) → `AdminRelease`
* `GET /admin/releases/{id}/checklist` → `Checklist = { passed: boolean, items: {key, label, status: 'pass'|'fail'|'pending'|'skipped', blocking: boolean, detail: string|null}[] }`
* `PUT /admin/releases/{id}/changelog` `{locale?, summary, highlights: string[], sections: ChangelogSections, markdown?}` → `Changelog`. (If `markdown` is sent without sections, the server parses `## New Features` style headings into sections.)
* `POST /admin/releases/{id}/submit-test` → `AdminRelease` (→ READY_FOR_TEST; 422 `CHECKLIST_FAILED`)
* `POST /admin/releases/{id}/start-testing` → TESTING
* `POST /admin/releases/{id}/qa` `{decision: 'PASS'|'FAIL', platform?: Platform, comment}` → `AdminRelease` (FAIL → back to DRAFT; 403 `SOD_VIOLATION`)
* `POST /admin/releases/{id}/approve` `{comment?}` → APPROVED (403 `SOD_VIOLATION`)
* `GET /admin/releases/{id}/publish-summary` → `{ channel, release_type, rollout: {mode, stages}, minimum_supported_version, effective_minimum, mandatory, mandatory_after, supersedes: {id, version}[], approvals: Approval[], checklist: Checklist, affected: {active_devices, will_be_offered}, health_ok: boolean, requires_mfa: boolean }`
* `POST /admin/releases/{id}/publish` (MFA) `{reason?, scheduled_publish_at?}` → `AdminRelease` (422 `CHECKLIST_FAILED`, 409 `SIGNING_REFUSED`, 503 `DISTRIBUTION_DEGRADED`)
* `POST /admin/releases/{id}/pause` (MFA) `{reason}`; `POST /admin/releases/{id}/resume` (MFA)
* `POST /admin/releases/{id}/withdraw` (MFA) `{reason, confirm_version}` (`confirm_version` must equal the version)
* `POST /admin/releases/{id}/supersede` (MFA) `{reason}`
* `PUT /admin/releases/{id}/policy` (MFA when published) `{minimum_supported_version?, minimum_no_change?, mandatory?, mandatory_after?, grace_minutes?, release_type?, reason?, immediate?: boolean}` → `AdminRelease`
* `PUT /admin/releases/{id}/support-state` (MFA) `{support_state, reason}`
* `GET /admin/releases/{id}/preview` → `{ public: PublicReleaseDetail, update_responses: {sample: {platform, architecture, install_type, current_version}, response: UpdateResponse}[] }`
* `GET /admin/releases/{id}/manifest` → `ManifestInfo = { release: {id, manifest_version, key_id, signature_valid, sha256, generated_at, published_at, json: object}|null, channel: {…same}|null, history: {id, kind, manifest_version, key_id, generated_at, published_at, superseded_at, reason}[] }`
* `POST /admin/releases/{id}/manifest/regenerate` (MFA, `release.publish`) `{reason}` → `ManifestInfo` (response includes `diff: {path, before, after}[]`)
* `PUT /admin/releases/{id}/rollout` `{mode: 'FULL'|'STAGED', stages: number[], auto_advance, auto_pause, thresholds: {dwell_hours, min_success_rate, max_failure_rate, max_rollback_rate}, rules: {kind, value}[]}` → `Rollout`
* `POST /admin/releases/{id}/rollout/{action}` action ∈ `pause|resume|increase|complete` (MFA on Stable) `{percentage?, reason?}` → `Rollout`
  `Rollout = { status: 'PENDING'|'ACTIVE'|'PAUSED'|'DONE', mode, stages: number[], stage_no, percentage, started_at, paused_at, completed_at, auto_advance, auto_pause, thresholds, rules: {kind, value}[], progress: {stage, percentage, eligible, updated, failed, rolled_back, success_rate}[] }`
* `GET /admin/releases/{id}/downloads?group=day` → `{ total, by_package: {label, platform, architecture, package_type, count}[], series: {date, count}[] }`
* `GET /admin/releases/{id}/devices` → `{ adoption: {version, count, pct}[], update_success_rate, failures_by_code: {error_code, count}[], rolled_back, events_by_type: Record<string, number> }`
* `GET /admin/releases/{id}/activity` → `{ data: AuditEntry[] }`
* `POST /admin/releases/{id}/attestation` `{payload: {app_id, version, build, files: {name, sha256, size}[], pipeline, commit}, signature, key_id}` → `{ verified, error|null }`

### Packages and uploads (A2/A3)
`AdminPackage = { id, platform, architecture, package_type, edition: Edition|null, scope: 'full'|'core-patch'|null, status: 'EMPTY'|'PROCESSING'|'READY'|'REJECTED', health: 'OK'|'DEGRADED'|'UNKNOWN', minimum_os_version, active_revision: Revision|null, revisions: Revision[] }`
`Revision = { id, revision_number, file_name, size, sha256, status: 'UPLOADING'|'PROCESSING'|'READY'|'REJECTED'|'REPLACED', reject_code, signature_status, signature_detail, scan_status, scan_detail, attestation_match, metadata: object, pipeline: PipelineStep[], replace_reason, uploaded_by, uploaded_at }`
`PipelineStep = { step: 'upload'|'quarantine'|'scan'|'sha256'|'metadata'|'signature'|'attestation'|'move'|'ready', status: 'pending'|'running'|'done'|'failed'|'skipped', detail: string|null, at: string|null }`

* `POST /admin/releases/{id}/packages` `{platform, architecture, package_type, edition?, file_name, size, protocol?: 'tus'|'multipart', reason?}` → `201 { package: AdminPackage, upload: { id, protocol, tus_endpoint: '/api/v1/uploads/tus', chunk_size: 8388608, parts?: {part_number, url}[] } }`
  * Same endpoint replaces an existing package slot's file (creates a new revision). Requires `reason` when the slot already has a READY revision; after publish requires `release.file.replace` + MFA.
* Upload bytes with **tus 1.0** (`@tus/tus-js-client` compatible): `POST /api/v1/uploads/tus` with headers `Tus-Resumable: 1.0.0`, `Upload-Length`, `Upload-Metadata: upload_id <base64(upload.id)>` → `201 Location: /api/v1/uploads/tus/{upload_id}`; then `HEAD` / `PATCH` (Content-Type `application/offset+octet-stream`, `Upload-Offset`). CSRF header required on POST/PATCH. On the final PATCH the server moves the file to quarantine and queues processing automatically.
* For `multipart` (S3 presigned): PUT each part to its URL, then `POST /admin/releases/{id}/packages/{pkg}/complete {upload_id, parts: {part_number, etag}[]}`.
* `GET /admin/releases/{id}/packages/{pkg}` → `AdminPackage` (poll every 2 s while PROCESSING for live pipeline)
* `DELETE /admin/releases/{id}/packages/{pkg}` (MFA) `{reason, confirm_file_name}` — DRAFT/UPLOADING/READY_FOR_TEST/TESTING only

### Server editions (§17)
* `GET /admin/releases/{id}` includes `server_meta: { min_from_version, requires, migrations: {count, one_way: string[]}, desktop_compat, downtime_estimate_s, staging_test: {status: 'PASSED'|'FAILED'|'PENDING', url?, editions?: Record<Edition,string>}|null, admin_notice, advisory }` and packages with `edition`; each READY server package revision `metadata` contains `package_json` (the worksuite-package.json) and `validation: {check, status, detail}[]`.
* `PUT /admin/releases/{id}/server-meta` → ServerMeta
* `GET /admin/releases/{id}/campaign` / `PUT …/campaign` `{audience: {editions: Edition[], channels, version_range, modes: ('MANAGED'|'SELF_HOSTED'|'AIR_GAPPED')[]}, urgency: ReleaseType, deadline, rollout_pct, channels: ('webhook'|'email'|'in_app'|'whatsapp')[], fleet_window, fleet_stages: number[]}` → `Campaign = {…, status, sent_count, eligible_count, fleet_status, fleet_stage_no}`
* `GET /admin/installations?edition=&mode=&status=` → `{ data: {installation_id, name, edition, version, build, channel, mode, support_state, last_report_at, last_run_status, php_version, db_version}[], total }`
* `GET /admin/installations/stats` → `{ by_edition: Record<Edition,{version, count}[]>, pending_updates, failed_runs: [...], rolled_back: [...], unsupported }`
* `POST /admin/fleet/{release_id}/{action}` action ∈ `start|pause|resume|advance` (MFA) → Campaign

### Server release console (WS-CORE-SYSUPDATE-SRS-001 §4, DL-1…DL-4)
`ServerSeverity = 'PATCH'|'MINOR'|'MAJOR'|'SECURITY'|'CRITICAL'`; server channels are `STABLE|BETA|LTS` (LTS is Enterprise only). The FREE edition is shown as **Professional**; `professional` is accepted as an alias of `FREE` in uploads and `worksuite-package.json`.
* `GET /admin/server/releases?channel=STABLE|BETA|LTS&status=draft|testing|published|paused|withdrawn` → `{ kpis: {linked, online, offline, latest_stable, on_latest_stable, on_latest_pct, below_minimum, minimum_stable, rollbacks_30d, rollback_versions}, data: (AdminRelease & {severity, editions: Edition[], editions_label, ring_summary, adoption_pct, installations_on_version, commit})[], minimums: {channel, minimum_version, enforce_from}[] }`
* `POST /admin/server/releases {version, build, commit?, channel, severity, min_from_version?, notes? /* Markdown */, summary?, known_issues?, upgrade_notes?, migrations_reversible?: boolean|null, editions: ('PROFESSIONAL'|'FREE'|'BUSINESS'|'ENTERPRISE'|'ALL')[], desktop_compat?, admin_notice?, downtime_estimate_s?, release_type?}` → `201 AdminRelease & {severity}` (release_type defaults from severity: patch/minor → RECOMMENDED, major → OPTIONAL, security → MANDATORY, critical → CRITICAL)
* `PATCH /admin/server/releases/{id} {severity?, commit?, upgrade_notes?, migrations_reversible?, editions?}` (before publication)
* `GET /admin/releases/{id}` `server_meta` additionally carries `severity, commit, upgrade_notes, migrations_reversible, editions`; the checklist adds `reversibility` and checks the selected editions.
* `POST /admin/server/releases/{id}/publish {confirm_version, reason?}` (`release.publish`, step-up MFA) — from TESTING (records the Release Manager approval) or APPROVED. `403 SOD_VIOLATION` when the caller gave the QA pass (every channel).
* `GET /admin/server/releases/{id}/rollout` → `{ release: {id, version, build, status, channel, severity, published_at, withdrawn_at}, status: 'PENDING'|'ACTIVE'|'PAUSED'|'DONE', mode: 'RINGS'|null, compressed, pause: {kind: 'GATE'|'MANUAL', reason, at, by, ring}|null, rings: {ring, key, name, state: 'DONE'|'OPEN'|'PAUSED'|'WAITING', opened_at, opens_at, hold_hours, extended_hours, hold_ends_at, installations, updated, detail}[], gate: {state: 'PASSING'|'WATCH'|'FAILING', signals: {key: 'rollback_rate'|'failed_runs'|'crash_free'|'new_p0p1', label, value, display, threshold, state: 'OK'|'WATCH'|'FAIL'}[], reasons: string[], runs, evaluated_at}, outcomes: {updated, auto_scheduled, waiting_for_admin, postponed, offline, held, skipped}, campaign: (Campaign & {delivered: {in_app, email}})|null, thresholds }`
* `POST /admin/server/releases/{id}/rollout/pause {reason}` · `…/resume {reason?}` · `…/extend {hours: 1..336, reason}` (step-up MFA) → rollout
* `POST /admin/releases/{id}/withdraw {reason, confirm_version, request_rollback?: boolean}` — for server releases this cancels every open plan and, with `request_rollback`, tells installations on the release to roll back.
* `PUT /admin/server/installations/{installation_id}/override {ring?: 0..3|null, locks?: Record<field, value>, reason}` (`fleet.manage`, MFA) — audited `server.policy_override`.
* `GET /admin/installations` adds filters `version`, `q`, `segment=stale|on_hold|offline|below_minimum` and fields `node_version, disk_free_mb, health, offline_mode, policy_mode, policy_version, ring, ring_name, ring_override, below_minimum, linked, registered_at`; `GET /admin/installations/stats` adds `segments: {all, on_latest, latest_stable, below_minimum, on_hold, stale}` and `adoption: {release_id, version, status, channel, installations, pct}[]`.

### Server installation API (WS-CORE-SYSUPDATE-SRS-001 §10)
Auth: `Authorization: Installation <installation key>` (from register). The existing `/server/*` endpoints accept the key as well as `License <token>`; `auto-update/*` accept only the key. Responses are signed with the update-response key (`key_id`, `signature`) and valid 15 minutes, except the plan (2 hours). Full shapes are in `openapi/update-center.yaml`.
* `POST /server/installations/register {license_token, name?, version?, build?, channel?: stable|beta|lts, mode?: MANAGED|SELF_HOSTED|AIR_GAPPED, offline_mode?, admin_contacts?: string[]}` → `201` (first link) / `200` (re-link, old key revoked) `{type: 'installation_registration', installation_id, installation_key, key_fingerprint, edition, channel, mode, ring, ring_name, policy_version, portal_url, registered_at, issued_at, expires_at, key_id, signature}`
* `GET /server/auto-update/plan` → `{type: 'auto_update_plan', schema: 1, installation_id, plan_id, state: 'NONE'|'PLANNED'|'POSTPONED'|'SKIPPED'|'HELD'|'DONE', release: {...}|null, path, ring, ring_name, ring_open, ring_open_at, earliest_at, latest_at, auto_install, policy_mode, policy_version, holds: {code, message}[], cancelled: {...}[], minimum_supported_version, below_minimum, issued_at, expires_at, key_id, signature}`
* `GET /server/auto-update/policy` → `{type: 'auto_update_policy', installation_id, version, policy|null, default_mode, …signed}` + `ETag: "<version>"`
* `PUT /server/auto-update/policy` (`If-Match: "<version last synced>"`, `"0"` for the first sync) → `{type: 'auto_update_policy', installation_id, version, policy, overridden: string[], …signed}` + `ETag`; `428 PRECONDITION_REQUIRED`, `412 POLICY_VERSION_CONFLICT {current_version, policy}`, `422 VALIDATION_FAILED`
* `POST /server/update-events` also accepts `scheduled|postponed|skipped` (plan decisions, FR-SU-PLAN-02); `rolled_back` holds the release for that installation.
* `POST /server/installations/report` also accepts `node_version, mariadb_version, disk_free_mb, policy_mode, health: OK|DEGRADED|DOWN, offline_mode, last_run: {result}`.
* `GET /server/update` adds `severity, minimum_supported_version, below_minimum, rollout: {ring, ring_name, ring_open, ring_open_at}|null, withdrawn_releases: {release_id, version, withdrawn_at}[], rollback_requested`; below the channel minimum an available update is `UPDATE_REQUIRED`.

### Analytics & devices
* `GET /admin/analytics/downloads?app=&release=&from=&to=&group=day|week|month` → `{ total, bots_excluded, series: {period, count}[], by_platform: {platform, count}[], by_package: {label, count}[], by_country: {country, count}[], top_releases: {release_id, version, app_name, count}[] }`; `&format=csv` → text/csv
* `GET /admin/analytics/devices?app=&channel=` → `{ active_devices, by_version: {version, count, pct, state: 'CURRENT'|'UPDATE_AVAILABLE'|'UNSUPPORTED'}[], by_platform: {platform, count}[], by_channel: {channel, count}[], current_pct, update_available_pct, unsupported_pct }`
* `GET /admin/analytics/update-status?app=&release=` → `{ releases: {release_id, version, checked, downloaded, installed, failed, rolled_back, success_rate}[], failures_by_code: {error_code, count}[] }`

### Administration
* Users: `GET /admin/users` → `{data: {id, email, name, kind, status, roles, mfa_enabled, last_login_at, created_at}[]}`; `POST /admin/users {email, name, password, roles}`; `PATCH /admin/users/{id} {name?, status?, roles?}`; `POST /admin/users/{id}/reset-mfa {reason}` (MFA)
* Roles: `GET /admin/roles` → `{ roles: {slug, name}[], permissions: {slug, name, roles: string[]}[] }`
* API clients: `GET /admin/api-clients` → `{data: {id, name, kind, client_id, permissions, ip_allowlist, expires_at, last_used_at, rotated_at, revoked_at, created_at}[]}`; `POST /admin/api-clients {name, kind, permissions, ip_allowlist, expires_at}` → `201 {client, secret}` (secret shown once); `POST /admin/api-clients/{id}/rotate` → `{secret}`; `POST /admin/api-clients/{id}/revoke`
* Tenants: `GET /admin/tenants`, `POST /admin/tenants {name, tenant_ref, license_installation_id}`, `POST /admin/tenants/{id}/webhooks {url, events}` → `{webhook, secret}`
* Storage (A5): `GET /admin/storage` → `{ providers: {id, name, kind, bucket, region, endpoint, cdn_host, is_active, versioning, replication, status, status_detail, last_check_at, usage_bytes, object_count, credentials: 'set'|'missing', credentials_rotated_at, release_count}[], cdn: {host, url_lifetime_s, signing: 'hmac'|'presign', purge: 'none'|'cloudflare', status, last_purge_at, hit_ratio: number|null, egress_30d_bytes: number|null}, distribution: {degraded_packages: {release_id, version, package, detail}[]}, migrations: {...}[] }`; `POST /admin/storage` (add provider; credentials only as secret ref); `POST /admin/storage/{id}/test`; `POST /admin/storage/{id}/activate` (starts copy-and-verify migration); `POST /admin/storage/probe` (HEAD every active revision now)
* Security: `GET /admin/signing-keys` → `{data: {key_id, purpose, algorithm, public_key, status, created_at, rotated_at, retire_after}[]}`; `POST /admin/signing-keys/rotate {purpose, new_key_id, approver_code…}` (MFA + second approver)
* Audit: `GET /admin/audit?action=&entity_type=&entity_id=&actor=&from=&to=&limit&offset` → `{data: AuditEntry[], total}`; `&format=csv`; `GET /admin/audit/verify` → `{ok, checked, broken_at}`
* Settings: `GET /admin/settings` / `PUT /admin/settings` → `{cdn_url_lifetime_s, notify_chat_webhook_set, ...}`

### Bug Trace (`/api/v1/admin/bugtrace`)
* `GET /issues?project=&platform=&release=&status=&type=&edition=&assignee=&q=&sort=users|events|first_seen|last_seen&limit&offset` → `{ data: Issue[], total, kpis: {unresolved, regressed, crash_free_users: number|null, events_24h, untriaged_reports} }`
  `Issue = { id: 'WS-WEB-4Q1', project: {id, name, platform}, type: 'crash'|'error'|'hang'|'message', title, culprit, status, resolution, resolve_version, priority: 'P0'..'P4', assignee: {id, name}|null, first_seen, last_seen, first_release, last_release, events_count, users_count, installations_count, labels: string[], sparkline: number[] /* 24 hourly */ }`
* `GET /issues/{id}` → `Issue & { latest_event: Event, frames: {file, function, line, col, in_app, context?: {pre: string[], line: string, post: string[]}}[], breadcrumbs, tags, distributions: {os, browser, release, edition}: {name, count}[], releases: {release, events, first_seen, last_seen}[], hourly: {hour, events}[], reports: ReportSummary[], comments: {id, user, body, kind, created_at}[], links: {kind, ref, url}[], scrubbed_fields: number, tenant_count?: number }`
* `PATCH /issues/{id}` `{status?, resolution?: 'NOW'|'NEXT_RELEASE'|'VERSION', resolve_version?, priority?, assignee_id?, labels?, ignore_until?, known_issue_public?}`; `POST /issues/{id}/merge {into}`; `POST /issues/{id}/comments {body}`; `POST /issues/{id}/links {kind, ref, url}`; `POST /issues/bulk {ids, patch}`
* `GET /reports?status=UNTRIAGED|LINKED|FEATURE_REQUEST|NEED_INFO|CLOSED&q=` → `{ data: ReportSummary[], counts }`; `ReportSummary = {reference, title, severity, category, status, app, release, created_at, has_screenshot, suggested_issue: {id, title}|null, issue: {id,title}|null}`
* `GET /reports/{ref}` → full report incl. `description, steps, expected, context, attachments: {id, kind, url /* short-lived */, meta}[], updates, contact: {email}|null /* only with bugtrace.reporter.contact */` ; `PATCH /reports/{ref} {status?, issue_id?, close_reason?}`; `POST /reports/{ref}/reply {message, public: true}`; `POST /reports/{ref}/create-issue`
* `GET /health?app=&release=` → `{ releases: {release, release_id, adoption_pct, sessions, crash_free_sessions, crash_free_users, new_issues, regressions, reports, update_failures, gate: 'OK'|'WARN'|'BLOCK'}[], series: {date, release, crash_free_users}[], gate_rules: {...} }`
* Alerts `GET/POST/PATCH/DELETE /alerts[/{id}]`; policies `GET /policies`, `PUT /policies/{tenant_ref}`; `POST /privacy/delete {user_ref?|installation_ref?, reason}` (MFA); `GET /projects`

## WorkSuite OS (§18)
Product `os` (kind `OS`, platforms `os/amd64`, `os/arm64`, package types `iso`, `deb`, `sbom`). OS rings map to channels:
`development` = DEV (alias `canary`), `pilot` = BETA (alias `beta`), `stable` = STABLE. Release types: `security`, `feature`, `emergency`.

**Manifest** — `GET /os/manifests/{ring}/{arch}.json` and `GET /desktop/v1/releases/manifest?platform=os&ring=&arch=` (public, `Cache-Control: public, max-age=300`):
```json
{"arch":"amd64","min_version":"1.0.0","release_id":"wsos-1.2.0","ring":"stable","rollout_percent":10,"security":true,
 "state":"active","version":"1.2.0","signature":"ws-release-2026a:<base64 Ed25519>"}
```
Optional: `critical`, `mandatory` (emergency), `deadline` (RFC 3339), `entitlement`, `rollback_directive {from_version, reason}`.
Signed by the signing service with the release key (kind `OS_MANIFEST`) in the OS scheme: Ed25519 over the canonical JSON
without `signature`; **no `key_id` field**, the key id is the prefix of `signature`, value is standard base64. This is exactly
`verifyManifest()` in Worksuite-OS `services/package-service/src/updates.ts`. Devices trust the PEM keys from `GET /api/v1/os/keys`
installed under `/usr/share/worksuite/keys/release/<key_id>.pem`.

**APT** — `GET /os/apt/dists/{trixie-dev|trixie-pilot|trixie-stable}/…` (InRelease/Release.gpg signed by the archive GPG key in CI),
`GET /os/apt/pool/…` → 302 signed CDN URL, `GET /os/apt/worksuite-archive-keyring.gpg`.

**Downloads** — `GET /api/v1/os/releases` → `{ data: {version, ring, channel, arch, release_type, state, published_at, notes,
iso: {file_name, size_bytes, sha256, url, sha256_url}|null, sbom: {file_name, url}|null, apt: {url, suite, keyring_url}}[], keys_url }`;
`GET /os/download/{version}/{arch}/{file}` → 302 to a 15-minute signed URL. Public page: `/os`.

**CI** (API client with `os.publish`, kept in the OS repo as `UPDATE_CENTER_TOKEN`):
`PUT /api/v1/os/artifacts/{version}/{arch}/{file}` (raw body: `worksuite-os-<v>-<arch>.iso`, `.iso.sha256`, `.sbom.spdx.json`),
`PUT /api/v1/os/apt` (tar.gz of the publish.sh tree; every suite needs `InRelease`; `lists/` is ignored; pool only grows),
`POST /api/v1/os/releases {version, ring='development', arch|arches, release_type, min_version, rollout_percent, deadline, entitlement, notes}`.
CI may publish to development and pilot; stable needs `release.publish`.

**Release management** (staff, `release.publish`): `POST /api/v1/admin/os/releases/{version}/{ring}/{pause|resume|pull|rollout|promote}`
`{arch?, reason?, rollout_percent?, to?}`; pull re-serves the previous active release with a signed `rollback_directive`;
promote to stable starts feature releases at 10 %. `POST /api/v1/admin/os/emergency {version, min_version, arches?}` publishes to every
ring as security+critical+mandatory at 100 %. `GET /api/v1/admin/os/releases`. Every change is re-signed and audited (`os.release.*`).

## Portal (`/api/v1/portal`, signed-in portal or staff user)
* `GET /portal/downloads` → `{ apps: (AppSummary & {channels_available: ChannelCode[], latest_by_channel: Record<ChannelCode, PublicReleaseDetail>})[] }`
* `GET /portal/history` → `{ data: {app, version, package, created_at}[] }`
* `PUT /portal/profile {name}`; `POST /portal/beta {opt_in: boolean}`
* `GET /portal/subscriptions` / `PUT /portal/subscriptions {items: {app, channel, enabled}[]}`
* `GET /portal/bug-reports` → `{ data: {reference, title, status, updated_at, fixed_in: string|null}[] }`
