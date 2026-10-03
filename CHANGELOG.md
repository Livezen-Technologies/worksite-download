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

[Unreleased]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Livezen-Technologies/worksite-download/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Livezen-Technologies/worksite-download/releases/tag/v0.1.0
