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

## [0.4.0] - 2026-10-10
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

[Unreleased]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Livezen-Technologies/worksite-download/releases/tag/v0.1.0
