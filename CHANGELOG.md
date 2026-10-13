# Changelog

All notable changes to **WorkSuite Update Center** (updates.worksuitecloud.com)
are documented in this file.

The format follows [Keep a Changelog 1.1](https://keepachangelog.com/en/1.1.0/)
and the project uses [Semantic Versioning 2.0](https://semver.org/).
`/VERSION` is the single source of truth for the version; see
[docs/versioning.md](docs/versioning.md) for how to cut a release.

Section headings used under each version: **Added**, **Changed**, **Deprecated**,
**Removed**, **Fixed**, **Security**.

## [Unreleased]

Already deployed to download.worksuitecloud.com on 2026-10-13 and 2026-10-14
(backend and admin only; `/VERSION` not bumped): the licence authority for
WorkSuite Server (#12, #13, #15), the installer (#16), and the two-person
switch (#11).

### Added

- **Staging install test, recorded in the admin.** A release's Server metadata
  form now has *Staging install test* (Not reported / Running / Passed / Failed)
  and a report link or note. Until now only an API call could set it, so the
  publish checklist's "Staging install test passed" could never be met from
  the admin. Saved through the audited server-meta endpoint.

### Fixed

- **The installer on Ubuntu 26.04.** The ondrej PPA has no builds for 26.04
  (they moved to packages.sury.org/php), so `apt-get update` failed at step 4.
  From Ubuntu 26 the installer adds the sury repository instead. Found by the
  staging install test of 3.1.0+2830 on a 26.04 VM (still an untested OS:
  `--skip-precheck`).
- **The installer on Ubuntu 26.04's coreutils.** 26.04 ships uutils, whose
  `install` fails reading `/dev/stdin` when the target already exists, so a
  second run stopped at step 8. The twelve config files are now written through
  a temp file (`put_file`).
- **The installer's last check said the licence "needs attention" when it was
  ACTIVE**: `grep -q` under `pipefail` killed `spark license:status` with
  SIGPIPE. It now reads the status whole. The health check is labelled 17/17,
  not 17/16.
- Staging install test, 2026-10-13: WorkSuite Server 3.1.1+2888 (Enterprise) on
  Ubuntu 26.04 (`--skip-precheck --no-ssl`) installed end to end: licence
  ACTIVE, `integrity:check --full` HEALTHY over 9,506 files, all services up.

- **Server › Licences, Entitlements and Licence risk were hidden from every
  admin.** Their `license.*` permissions were added to the role matrix after the
  first seed and never reached the database. Migration `SyncRbacPermissions`
  adds any permission the matrix has and the database lacks, granted to the
  roles the matrix names (view: super admin, release manager, support; manage:
  super admin). Existing grants are untouched.

### Added

- **WorkSuite Server licences (#12).** Server › Licences generates a key
  (`WS-XXXX-XXXX-XXXX-XXXX-XXXX`, shown once; only its SHA-256 and last four
  characters are stored) with an edition, an expiry, an "updates until" date,
  a number of servers and server binding, and renews, suspends, resumes and
  revokes it or frees a server's slot. Permissions `license.view` (Super Admin,
  Release Manager, Support) and `license.manage` (Super Admin).
- **Licence API for servers.** `POST /api/v1/server/licenses/activate` (key in;
  installation key and entitlement out, registering or linking the
  installation), `POST /api/v1/server/licenses/validate` (installation key;
  current state) and `POST /api/v1/server/licenses/inspect` (what a key would
  grant, without activating it). Every answer is signed with the
  update-response key and carries `token_version` 1.
- **Entitlement catalog (#13, IMPL-LIC-01 P0).** Keys such as `crm.enabled`,
  `core.users.max`, `updates.channel.lts` and `support.priority`; each edition's
  value (seeded from the licensing plan's placeholder table, every WorkSuite
  Server module with its `<module>.enabled`); per-licence add-ons. A licence's
  resolved map is signed into every licence answer. Edited at Server ›
  Entitlements and in a licence's Add-ons; published at
  `GET /api/v1/catalog/entitlements`.
- **Two-person switch (#11).** `updatecenter.twoPersonRuleDisabled = true` lets one
  staff member pass QA, approve and publish (server releases included); the
  approvals are still required and recorded. On in production since
  2026-10-13 at the owner's request.

- **Signed installation requests (#15).** Activation also issues a request
  secret (sealed with the app key); servers then authenticate licence calls with
  `WS-HMAC-SHA256` (timestamp within five minutes, a nonce accepted once, HMAC
  over method, path, time, nonce and body hash). Installation keys still work.
- **Fingerprint confidence and transfer (#15).** Servers send per-component
  fingerprint hashes (weights 35/25/15/10/10/5); below 60 the activation becomes
  `TRANSFER_REQUIRED` (no updates) until the licence is activated again on the
  new server. `POST /api/v1/server/licenses/deactivate` gives a server's slot back.
- **Server packages must carry `files_digest`** in `worksuite-package.json` (the
  SHA-256 of `files.sha256`); the validator refuses one without it, because it is
  what lets an installed server prove its files against the release signature.
  The installer keeps the verified manifest beside the release.
- **One-command installer (#16).** `/install.sh` and `/install.sh.sha256` for
  Ubuntu 22.04/24.04, Debian 12, RHEL/Rocky/AlmaLinux 9 and CentOS 7 (best
  effort), and `POST /api/v1/server/install/package` (licence key in; the edition's
  newest published package, a short-lived URL and the signed release manifest).
  The WorkSuite Server download page carries the installation guide.

- **Licence risk engine (P6).** Rules over what installations report and do —
  protected code differs from the signed release, an installation running on
  another machine, more than five activations in 24 hours, more than three
  deactivations in seven days — raise findings at Server › Licence risk. An open
  HIGH finding holds that licence's updates; conditions that clear (a transfer,
  a healthy integrity report) close their own finding. Staff acknowledge or
  resolve with a reason.
- **Integrity reports.** Validations carry the server's code-integrity status;
  COMPROMISED ends update eligibility for that installation.
- **Revocation manifest.** `GET /api/v1/server/revocations/latest`, signed: revoked
  licences, installations whose activation was deactivated, revoked signing keys.
- **Key rotation drill** documented in `docs/key-rotation.md`.

- **Offline activation (P5).** `POST /api/v1/server/licenses/offline` and the
  public page `/offline-activation` turn a server's request file (.wsr) and the
  licence key into a signed licence file (.wsl) for that installation and request,
  valid until the licence ends or a year. Needs `offline.license.enabled`
  (Enterprise). The installer's `--offline` mode installs from a copied package
  and writes the request file at the end.
- **Installation types (P5).** Activations are PRODUCTION (counted against the
  licence's servers), STAGING or DR, each with its own limit
  (`license.staging.max`, `license.dr.max`; placeholders 0/1/2 and 0/0/1). The
  installer takes `--installation-type` and `--cluster`.

- **Portal › My licences (P3).** A customer signed in to the Download Center
  portal, with a verified email, sees the licences whose contact email is
  theirs (anyone else's answers 404): status, edition, expiry, update
  eligibility, servers, entitlements and history. They can show the key
  again (keys issued from now on are kept sealed for this), replace it (the
  old key activates nothing more), deactivate a server — including a lost one,
  whose credentials end at once — run offline activation without typing the
  key, compare editions, and start one 30-day Business or Enterprise trial per
  account. Renewal and edition changes go through sales; the portal has no
  billing yet. Licences gain `customer_ref`, `key_sealed` and `is_trial`; API
  clients may be of kind SERVICE, with the new `license.service` permission,
  for the worksuitecloud.com portal backend when it exists.

### Changed

- `GET /api/v1/server/update` and `POST /api/v1/server/downloads/grant` refuse
  an installation activated with a licence key once that licence is not active
  and update-eligible (402 `LICENSE_UPDATE_NOT_ELIGIBLE`); `updates.core` false
  ends update eligibility too. Installations linked the older way are unchanged.

## [0.6.0] - 2026-10-13

### Added

- **WorkSuite APT and DNF repositories (worksuite-desktop#351)** —
  `scripts/publish-linux-repos.sh` adds WorkSuite Desktop `.deb` builds to a
  reprepro repository (suites `stable`, `beta`, `dev`; component `main`;
  `amd64`, `arm64`) and `.rpm` builds to a per-channel createrepo_c
  repository, signs `Release`/`InRelease` and `repomd.xml` with the WorkSuite
  repository key (imported from the `WORKSUITE_REPO_GPG_PRIVATE_KEY` secret
  into a temporary keyring), and publishes the public key at
  `/apt/worksuite.gpg` and `/rpm/worksuite.gpg` with ready-made source files.
  Idempotent, with `--dry-run`; a different file under a published name is
  refused. `scripts/linux-repo-sign.sh` is reprepro's signing hook.
- nginx serves `/apt/` and `/rpm/` from `/srv/linux-repos/public`: static
  only, no listings or SPA fallback, package MIME types, packages cached as
  immutable and indexes and signatures for 1–5 minutes.
- `docker/linux-repo.Dockerfile` and the `linux-repo` compose profile (a
  `linux-repos` volume, read-only in `update-web`).
- **Linux repositories** workflow: after a desktop release is published,
  fetches its `.deb`/`.rpm` from the Update Center, checks each against the
  `X-WorkSuite-SHA256` it is served with, updates the tree on the server
  (packages before indexes) and verifies the served signatures. CI runs the
  script's tests (`node --test scripts/tests/*.test.mjs`, a throwaway key made
  at test time).
- `docs/linux-repositories.md`: offline key creation and rotation, secrets,
  first-time server setup, verification in clean Ubuntu 22.04/24.04 and
  Fedora containers, and when and how to turn on the desktop switch
  (`WORKSUITE_LINUX_REPO=1`, off until the repository is live).

## [0.5.0] - 2026-10-12

Deployed to download.worksuitecloud.com at 21:20 UTC on 2026-10-12 from
`bf4df00` (main, which now contains everything the deployed branch had). The
migration `CreateServerAutoUpdateTables` ran on production; a database dump
and a copy of the previous code were taken first.

### Added

- **Core System Update Service, portal side (WS-CORE-SYSUPDATE-SRS-001 §10)** —
  `POST /api/v1/server/installations/register` (licence token in,
  `installation_id` + installation key out; only the key hash is stored,
  re-registering revokes the old key), `GET /api/v1/server/auto-update/plan`
  (signed plan, 2-hour expiry: release, upgrade path, ring open time, latest
  allowed time, holds and cancelled plans) and `GET`/`PUT
  /api/v1/server/auto-update/policy` (policy mirror, `If-Match` required,
  `412 POLICY_VERSION_CONFLICT` on a stale version, audited, portal locks
  win). Responses are signed with the update-response key. Shapes in
  `openapi/update-center.yaml` and `docs/api-contract.md`.
- **Tables `server_auto_update_policies` and `server_update_plans` (§11)**,
  plus installation key and heartbeat columns, server release fields
  (severity, commit, upgrade notes, migration reversibility, selected
  editions), ring state and an Enterprise-only `LTS` channel for
  `lk.worksuite.server`.
- **Server release console (FR-DL-REL-01..05)** — create a server release with
  every field, per-edition ZIP uploads (existing tus + `ServerPackageValidator`;
  `professional` accepted as the FREE edition), checklist items for selected
  editions and stated migration reversibility, Release Manager publish with
  step-up MFA and type-to-confirm. Publishing copies each edition package to
  `server/<edition>/<channel>/<version>+<build>/` with `SHA256SUMS`, a
  `SHA256SUMS.sig` signed by the signing service (new `CHECKSUMS` document
  kind) and the signed release manifest.
- **Rings and fleet health gate (FR-DL-RING-01/02, WF-SU-10)** — rings 0–3
  hold 24 h, 48 h, 72 h and 14 days (security and critical releases compress
  to 0 → 2); the scheduler (`server_rings`, every 5 minutes) pauses the next
  ring on a rollback rate over 2 %, failed runs over 5 %, Bug Trace crash-free
  under 99.0 % or down 0.5 points, or a new P0/P1 on 3+ installations; the
  Release Manager can pause, resume or extend; every action is audited.
- **Withdraw cancels plans (FR-DL-WD-01, AC-SU-14)** — open plans are
  cancelled at once, the plan and update responses list the withdrawn release,
  and `request_rollback` asks installations on it to roll back.
- **Admin screens DL-1 to DL-4** — Server Releases (KPIs, channel and status
  filters), New server release (form, edition packages with progress and
  validation states, Markdown notes, checklist, publish), Release rollout
  (ring cards, health gate, what installations will do, campaign, extend /
  pause / resume / withdraw) and the Installations fleet (segments, policy,
  ring, heartbeat, last run, health, adoption per release); new
  *Server › Server Releases* navigation item.

### Changed

- Server API endpoints also accept `Authorization: Installation <key>`.
  `GET /server/update` adds `severity`, `minimum_supported_version`,
  `below_minimum` (an available update becomes `UPDATE_REQUIRED` below the
  channel minimum, FR-DL-MIN-01), `rollout`, `withdrawn_releases` and
  `rollback_requested`; `installations/report` accepts Node, MariaDB, disk,
  policy mode and health; `update-events` accepts `scheduled`, `postponed`
  and `skipped` plan decisions.
- Server releases enforce the two-person rule on every channel: whoever gave
  the QA pass cannot approve or publish (FR-DL-REL-04).
- Campaign e-mails also go to each installation's registered admin contacts
  (deduplicated); unapplied security and critical releases are reminded every
  7 days (FR-DL-NOTIFY-01).
- `GET /admin/installations` adds version, search and segment filters and the
  fleet fields; `GET /admin/installations/stats` adds segment counts and
  adoption per release (FR-DL-FLEET-01). The old Installations page is
  replaced by the DL-4 fleet view.
- Server releases published before ring rollouts existed count as open in
  every ring, so their plans are not held.
## [0.4.0] - 2026-10-09

Deployed to download.worksuitecloud.com at 19:27 UTC on 2026-10-09 from
`0ac23d4`, which merged main into `deploy/download-worksuitecloud`. Until then
the two branches had drifted: main lacked the fixes production runs on (the
two-factor switch, MariaDB index names, the publishing pipeline), so deploying
main alone would have taken them off the live site. #9 (`ee8fa93`) brought main
level with the deployed branch; main is the branch to deploy from again.

The release combines two lines of work that each called themselves 0.4.0: the
bug report form and the download.worksuitecloud.com rename from main, and the
Sparkle appcasts and public Beta channel from the deployed branch. Both are listed
below.

### Added

- **Public "Report a problem" form (FR-BT-REP-09)** — `/report-a-problem`
  on the public site lets anyone report a bug, download/installation
  problem or feature request for a WorkSuite application without signing
  in (app, version, kind, severity, summary, details, steps, expected
  result, OS, optional email with consent). Uses the existing
  `POST /api/v1/bugtrace/reports/public` endpoint with honeypot,
  idempotent `client_report_id` and an optional Cloudflare Turnstile
  widget (`VITE_TURNSTILE_SITE_KEY`). On success it shows the `BR-`
  reference and a private status link (`/report-a-problem/status`,
  token-protected, `noindex`). Linked from the public footer and mobile
  menu, included in prerendered routes, and served by the mock API.
- **Report form: Web / Desktop / Mobile app** — reporters choose where the
  problem happened (defaulted from the application kind and their device,
  or `?platform=`); `POST /bugtrace/reports/public` accepts `platform` and
  files the report under the matching Bug Trace project (WS-WEB, WS-DSK,
  WS-MOB). Download-center app and release pages link to the form with the
  application and version pre-filled.

### Changed

- **Public address is now `download.worksuitecloud.com`** (was
  `updates.worksuitecloud.com`; staging `download-staging.`). It is used for
  canonical URLs, the sitemap, the OpenAPI servers, the header and footer, and
  the SDK defaults (`@worksuite/bugtrace-web`, and through it Electron and
  React Native, plus the PHP `BugTraceReporter`). The old name keeps being
  served unchanged by the same nginx site, because builds in the field call its
  API and an API POST cannot follow a redirect. The CDN host
  (`cdn-updates.`) and the `updates@` mail sender are unchanged. (#7)
- **Report a problem in every WorkSuite app.** The clients now link to the
  public form, each pre-filled with its app, version and platform:
  web (account menu and the screen-error panel; with `VITE_BUGTRACE_KEY` the
  SDK's in-app dialog and crash capture instead — worksuite#143), desktop
  (Help › Report a Problem…, 0.2.216 — worksuite-desktop#316) and mobile
  (Settings › About, 1.2.1 — worksuite-mobile#13).

### Added

- **Sparkle appcasts for macOS apps that update through Sparkle** — WorkSuite
  Office first: `GET /api/v1/apps/{app}/channels/{channel}/appcast.xml?arch=arm64|x64`.
  The feed offers what an anonymous macOS update check on that channel would get:
  the newest published, fully rolled-out release with a READY package for that
  architecture. It carries the build number as `sparkle:version`, the version,
  the minimum macOS, the release notes, `sparkle:criticalUpdate` when the Update
  API would require the update, and the public download URL as the enclosure.
  Only public channels of public applications answer, because Sparkle sends no
  credentials. See [docs/sparkle.md](docs/sparkle.md).
- **Sparkle signatures through the CI attestation.** `scripts/publish-release.mjs`
  reads `<archive>.edsig` (the `sparkle:edSignature` from Sparkle's `sign_update`)
  and records it in the attestation as `files[].sparkle_ed_signature`. With
  `UC_SPARKLE_PUBLIC_KEY` it checks the signature against the archive first, and
  it refuses a macOS file that has none. The appcast offers a package only with
  a signature from a verified attestation matching its SHA-256.
- **WorkSuite Office in the seed catalog** (`lk.worksuite.office`, slug `office`)
  for development and tests. In production it is created in `/admin`, as
  described in docs/sparkle.md.

- **Beta can be public.** A channel an administrator marks public is visible
  to everyone: its downloads (`/download/<app>/beta/…`), release notes,
  changelog and update checks. The application page offers the current Beta —
  labelled "Latest beta", with a note that Beta installers are not code-signed
  yet — when there is no Stable release, and as its own section when it is
  newer than Stable. The "every installer is code-signed" line shows only for
  Stable.
- **A switch to turn two-factor authentication off**
  (`updatecenter.mfaDisabled = true`, off by default). With it on, signing in
  needs only the password, staff are not made to enrol, and publishing,
  withdrawing, rollout changes and the other critical actions ask for no
  step-up code. API clients are unaffected: a CI client still cannot publish in
  production, and Stable still needs two different approvers. Turned on at the
  owner's request (2026-10-03).
- **`scripts/publish-release.mjs`**, the pipeline side of publishing. It
  creates the release, writes the changelog, signs and posts the CI build
  attestation (Ed25519 over the same canonical JSON the server verifies),
  uploads every installer over tus, waits for scan and checks, and submits
  for testing. QA, approval and publishing stay with people. No dependencies.
- **`spark uc:create-ci-client "<name>" <token-file>`** — a CI API client with
  the CI defaults plus `release.edit`, never `release.publish`; the one-time
  token is written to a file (600), not printed.
- **`updatecenter.allowUnsignedTestChannels`** (default off). On BETA, DEV and
  INTERNAL, signature checking is skipped and recorded as "Not checked:
  unsigned builds are allowed on the … channel"; the checklist shows it as
  skipped. STABLE always requires valid signatures; the malware scan is never
  relaxed. For the period before code signing is in place.
- **`spark uc:create-admin <email> "<name>"`** — creates the first Super Admin
  on the server, asking for the password at a hidden prompt. There was no way
  to make one in production: the seeders create no users, `uc:demo` refuses in
  production, and every other path to a staff account needs an existing admin.

### Fixed

- **A synced passkey worked once, then never again.** After each sign-in the
  stored signature counter was set to "previous + 1" instead of the counter
  the authenticator reported. iCloud Keychain and Google Password Manager
  passkeys always report 0, so the second sign-in compared 1 ≥ 0 and was
  refused as a cloned key ("Passkey verification failed"). The real counter
  is stored now, failures are logged with their reason, and a test signs in
  repeatedly with a software authenticator.
- **Windows installers were rejected as the wrong architecture.** An NSIS
  installer is a 32-bit x86 program whatever it installs, so every x64/arm64
  setup.exe failed the metadata step ("Binary architecture x86 does not match
  x64 slot"). An x86 `.exe` is now accepted in x64 and arm64 slots as an
  installer stub; a 64-bit PE for the other CPU is still refused.
- **Migrations failed on MySQL/MariaDB.** The seven-column unique key on the
  daily download analytics generated an index name longer than the 64-character
  limit, so `migrate --all` stopped at `CreateDistributionTables`. SQLite, where
  the tests run, takes any length. `SchemaHelper` now shortens a key name over
  60 characters (prefix plus a hash) and leaves every other name unchanged.
- **Every error page was a 500.** `MetricsExceptionHandler` extended
  CodeIgniter's `ExceptionHandler`, which is `final` in 4.7, so drawing any 404
  or error was a fatal error. It now wraps the default handler.

Found deploying to download.worksuitecloud.com (native: nginx, PHP-FPM,
MariaDB, Redis, Garage for S3 storage, Vault Transit for the response key).

## [0.3.0] - 2026-10-03

### Added

- **Desktop SDK `@worksuite/bugtrace-electron` (FR-BT-SDK-02)** — Electron
  main process: uncaught exceptions and rejections, Crashpad minidumps
  (local capture, policy-checked upload to `/bugtrace/minidump` on next
  start), GPU/renderer/utility process crashes, window and main-loop hangs
  > 5 s, ENH-02 updater failures; persistent offline queue and opt-out.
- **Mobile SDK `@worksuite/bugtrace-react-native` (FR-BT-SDK-03)** — JS
  global handler, native iOS/Android crashes and NDK tombstones via the
  `WorkSuiteBugTrace` native module, ANR/app hangs (native + JS-thread
  watchdog), out-of-memory termination detection, shake-to-report and
  post-crash sheet hooks, AsyncStorage queue.
- **Monitoring (§13.4, FR-UC-OPS-05)** — `GET /metrics` Prometheus
  exporter (bearer token, internal networks only): request rate, 5xx and
  latency histogram per route group (Redis-backed, unhandled exceptions
  included), queue backlog/age, upload rejections and stuck jobs, resolver
  failures, update events, webhook failures, component health, signing /
  signature failures; Prometheus config and alert rules for every §13.4
  signal (`docker/monitoring`, compose profile `monitoring`).
- **Backups (§13.3, FR-UC-OPS-04)** — `uc:export` daily write-once export
  of configuration, changelogs, manifests and incremental audit log (secrets
  never exported, existing objects never overwritten), scheduled by
  `uc:scheduler`; `scripts/backup-db.sh` (encrypted full dump),
  `scripts/ship-binlogs.sh` (15 min RPO), `scripts/restore-drill.sh`;
  `uc:verify-release` re-hashes a release's packages from storage and checks
  the manifest signature; the health page shows the age of the last export
  (DEGRADED after a missed day); runbook in `docs/operations.md`.
- **Native symbolication (FR-BT-ING-04, AC-C61/C62)** — Breakpad `.sym`
  lookup for desktop (Windows/macOS/Linux), iOS and Android NDK frames
  (CI converts PDB/dSYM/ELF with `dump_syms`; upload validates the `MODULE`
  header and records the debug id), R8/ProGuard retrace for Android JVM
  frames including inlined methods, and minidump walking with
  `minidump-stackwalk` (`updatecenter.minidumpStackwalk`, read-only parser).
  Events waiting for symbols (`missing`/`pending`) are re-symbolicated when
  symbols arrive. Issue detail shows the native module / JVM class and the
  real symbolication state.
- **Tenant mirror feed (§2.5 mirror mode, Phase 5)** —
  `GET /api/v1/catalog/mirror/{app}/{channel}` for tenant API clients:
  signed channel and release manifests verbatim, per-package SHA-256 and
  7-day download grants so self-hosted / air-gapped tenants can copy
  packages into their own storage; licensed server editions excluded.
- CI builds, typechecks and tests all three SDKs; `scripts/version.php`
  keeps their versions in sync.

### Fixed

- `@worksuite/bugtrace-web`: native SDKs pass captured frames as
  `error.frames`; frames carry `module`, `debug_id`, `module_offset`.
- `@worksuite/bugtrace-web`: `captureMessage` ignored `ctx.type`, so
  crash/hang messages were sent as `message`; typecheck failed without
  `@types/node`. `allowsAutomatic` / `policyFromTpt` are now exported.

## [0.2.0] - 2026-10-03

### Added

- **Edition switch (FR-TEN-UPD-17, AC-C54)** — `GET /api/v1/server/update`
  accepts `installed_edition`; when it differs from the licensed edition the
  signed response carries `edition_switch` with the same version's package
  for the new edition (`UPGRADE` / `DOWNGRADE`, data kept) and a download grant.

### Fixed

- Backend test suite failed (9 tests) without an encryption key configured;
  `phpunit.dist.xml` now sets a test-only key.

## [0.1.0] - 2026-09-28

First build of the Update Distribution Portal from
WS-UPDATE-CENTER-SRS-001 v1.2 (phases 1–4 core, W1–W2 server distribution,
B1 Bug Trace core).

### Added

- **Platform** — CodeIgniter 4.7 API (`backend/`) with MariaDB/MySQL schema
  for identity, catalog, releases, distribution, analytics, server editions and
  Bug Trace; SQLite support for development and tests.
- **Authentication (FR-UC-AUTH)** — Argon2id passwords, TOTP with one-time
  recovery codes, WebAuthn passkeys, DB-backed sessions (30 min idle / 12 h
  absolute), step-up MFA for critical actions (`X-MFA-Token`), CSRF
  double-submit, exponential lockout, password reset, portal accounts with
  email verification.
- **RBAC (§7.5)** — roles and permissions matrix, route-level permission
  filter, separation of duties on production Stable (uploader ≠ QA ≠
  publisher), scoped CI / tenant API clients with IP allow-lists, expiry and
  rotation.
- **Applications (§4.1, §7.2)** — 8-step creation wizard API, platforms ×
  architectures × package types, channels, HIDDEN/RETIRED states, seed
  applications (Desktop, Installer, Quick Support, Sync Agent, Server Agent,
  Server).
- **Release lifecycle (§4.3)** — DRAFT → UPLOADING → READY_FOR_TEST → TESTING →
  APPROVED → PUBLISHED ⇄ PAUSED → WITHDRAWN / SUPERSEDED with 409 on invalid
  transitions, validation checklist (§7.4), scheduled publish, automatic
  supersede, pause fallback to the previous release, withdraw with
  type-to-confirm and automatic minimum lowering.
- **Packages & uploads (FR-UC-PKG/UPL)** — tus 1.0 resumable uploads (8 MB
  chunks, up to 4 GB) and presigned multipart, quarantine prefix, read-only
  worker pipeline: malware scan (ClamAV), SHA-256, header metadata (PE,
  Mach-O, ELF/AppImage, MSI, DMG, PKG, DEB, RPM) with architecture mismatch
  rejection, platform signature verification, CI attestation match,
  revisions with emergency replacement after publish.
- **Signed manifests (§2.2, §10.4)** — Ed25519 release and channel manifests
  over canonical JSON; Signing Service policy that refuses to sign without a
  matching CI build attestation and a two-person approval; local (dev) and
  remote (mTLS) signing clients; Vault Transit key provider.
- **Desktop Update API (§10.3)** — status resolution (CURRENT … RELEASE_WITHDRAWN),
  effective minimum = max(release, channel, tenant org), mandatory deadlines,
  CRITICAL grace, deterministic rollout cohorts, rollout rules, signed
  responses (15 min), signed download grants, 60 s decision cache.
- **Tenant integration (§2.4–2.8)** — Tenant Policy Token and license token
  verification against the pinned license root, catalog change feed and
  signed webhooks.
- **Public distribution (§5)** — catalog API, download resolver (302 to
  short-lived signed CDN URLs, never streams bytes; 503/410 error pages,
  DEGRADED marking), `checksums.txt`, RSS 2.0 / Atom feeds, sitemap, robots,
  double opt-in release subscriptions.
- **Rollouts & minimum versions (§4.6–4.7)** — staged rollouts
  (5 → 25 → 50 → 100) with pause/resume/increase/complete, auto-advance and
  auto-pause on failure/rollback rates or the Bug Trace gate; minimum
  version policies with impact preview and history.
- **Telemetry & analytics (FR-UC-TEL/DEV/ANL)** — idempotent pseudonymous
  update events, heartbeats, hourly success stats, device version
  distribution, download analytics with CSV export and bot exclusion.
- **Server editions (§17)** — Free/Business/Enterprise/All ZIP packages,
  validation (zip-slip, symlinks, forbidden paths, `worksuite-package.json`
  schema, per-file SHA-256, edition content, post-update allow-list), server
  update API with upgrade chains, installation registry, notification
  campaigns with reminders, managed fleet with auto-pause.
- **Bug Trace (§18)** — ingest API (gzip envelopes, minidumps, attachments,
  user reports with reference + status token), server-side PII scrubbing
  (emails, phones, Sri Lankan NIC, Luhn-checked cards, IBAN, tokens,
  passwords, IPs), fingerprinting and issue grouping with merge/split,
  regression detection, JavaScript source-map symbolication, release
  health (crash-free users/sessions) with a rollout gate, alert rules with
  quiet hours and digests, tenant reporting policy via TPT, privacy
  deletion/export and retention; `@worksuite/bugtrace-web` SDK (13 KB gzip)
  with a "Report a bug" dialog and screenshot annotation; PHP
  `BugTraceReporter` reference for tenant servers.
- **Operations** — storage abstraction (R2, S3, Spaces, MinIO, Local) with
  SigV4 presigning and HMAC-signed CDN URLs, copy-and-verify storage
  migration, component health checks, DB job queue, hash-chained append-only
  audit log with verification and CSV export, in-app/email notifications.
- **Frontend (`frontend/`)** — React 19 + TypeScript + Tailwind v4:
  public download site (platform detection with manual override, app and
  release pages, previous versions with support states, changelog + feeds,
  SEO metadata and JSON-LD) with SSR prerendering so download links work
  without JavaScript; admin portal per §7.1 (dashboard, 8-step application
  wizard, release screen with all nine tabs, resumable tus uploads with live
  pipeline, publish/withdraw modals with step-up MFA or passkey, rollouts,
  analytics, server editions and campaigns, installations and fleet,
  storage/health, users/roles/API clients/security/audit, Bug Trace issues,
  reports inbox, release health and alerts); user portal (downloads incl.
  beta, history, notifications, bug reports, profile, security).
- **Deployment** — Docker images for update-web / update-api / update-worker /
  update-scheduler / signing-service, compose stack (MariaDB, Redis, optional
  ClamAV), nginx site with CSP/HSTS and prerender fallback, mTLS front for the
  signing service, Cloudflare Worker validating signed CDN URLs, OpenAPI 3.1
  at `/api-docs`, GitHub Actions CI, and `uc:worker`, `uc:scheduler`,
  `uc:health`, `uc:keys`, `uc:demo` spark commands.
- **Versioning** — `/VERSION`, `scripts/version.php` (show / bump / set /
  sync / check), version exposed at `GET /api/v1/version`, in `/api/v1/site`,
  admin health and the `X-Update-Center-Version` header.

### Security

- Release keys never reach the API tier; uploaded binaries are only read,
  never executed; storage credentials are resolved from the secret service
  by reference only; telemetry stores no IP addresses or user identities.

[Unreleased]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Livezen-Technologies/worksite-download/releases/tag/v0.1.0
