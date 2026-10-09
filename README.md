# WorkSuite Update Center

**download.worksuitecloud.com** — the separate distribution application for WorkSuite
software (WS-UPDATE-CENTER-SRS-001 v1.2): release management, package distribution,
signed manifests, public download pages, the Desktop Update API, WorkSuite Server
edition updates (§17) and Bug Trace crash / bug reporting (§18).

Current version: see [`VERSION`](VERSION) · changes: [`CHANGELOG.md`](CHANGELOG.md)

```
backend/          CodeIgniter 4.7 API, workers, scheduler, signing-service entry (signing/)
frontend/         React 19 + TypeScript + Tailwind v4 — public site, user portal, admin portal
sdks/             Bug Trace SDKs: web, Electron main process, React Native, tenant PHP reporter
docker/ nginx/    Container images, compose stack, nginx + CDN edge worker
openapi/          OpenAPI 3.1 description (served at /api-docs)
docs/             API contract, versioning
scripts/          version.php (version + changelog management)
```

## Quick start (local, no Docker)

Requirements: PHP 8.2+ with `intl`, `sodium`, `sqlite3`, `zip`; Composer; Node 22.

```bash
cd backend
composer install
cp env .env   # then set, at minimum:
#   CI_ENVIRONMENT = development
#   database.default.DBDriver = SQLite3
#   database.default.database = /absolute/path/backend/writable/dev.sqlite
#   updatecenter.queueSync = true
#   encryption.key = hex2bin:<64 hex chars>
php spark migrate --all && php spark db:seed DatabaseSeeder
php spark uc:demo          # demo users, releases, packages, devices, installations
php spark serve --port 8080

cd ../frontend && npm install && npm run dev   # proxies /api, /download … to :8080
```

Sign in at `/admin` as `admin@livezen.lk` / `worksuite-demo-2026` (other demo roles:
`rm@`, `qa@`, `dev@`, `content@`, `support@`, `viewer@livezen.lk`; portal user
`customer@example.com`).

## Docker

```bash
php -r 'echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));' > docker/dev-secrets/release_key
docker compose up --build
docker compose exec update-api php spark migrate --all
docker compose exec update-api php spark db:seed DatabaseSeeder
```

Containers (§3.5): `update-web` (nginx + React), `update-api` (PHP-FPM),
`update-worker` (`spark uc:worker`, read-only verification tools),
`update-scheduler` (`spark uc:scheduler --loop`), `signing-service` (only holder of
the release key; mTLS front in `nginx/signing.conf`), MariaDB, Redis, optional ClamAV
(`--profile scan`). Production adds Cloudflare R2 + the edge worker in
`docker/cdn-worker/worker.js`.

## Security model (short)

* Release manifests are signed by the **signing-service**, which refuses unless the
  package hashes match a CI build attestation signed by a pinned CI key and a QA +
  Release Manager approval from two different people are recorded (§2.2). The API tier
  holds only the update-response key.
* Uploaded binaries are quarantined and only **read** (headers, hashes, signatures) —
  never executed. Downloads are 302 redirects to 15-minute signed CDN URLs.
* Critical actions need step-up MFA (`X-MFA-Token`), type-to-confirm and a reason, and
  are recorded in a hash-chained audit log (`GET /api/v1/admin/audit/verify`).
* Storage credentials, SMTP and API secrets are only referenced (env / mounted
  secrets / Vault); telemetry is pseudonymous and never stores IP addresses.

## Tests

```bash
cd backend && vendor/bin/phpunit --no-coverage
cd frontend && npm run typecheck && npm test && npm run build
php scripts/version.php check
```

## Spark commands

| Command | Purpose |
|---|---|
| `uc:worker` | Queue consumer (package pipeline, manifests, webhooks, mail, Bug Trace) |
| `uc:scheduler [--loop]` | Scheduled publish, rollout auto-advance / auto-pause, campaign reminders, fleet evaluation, health + storage probes, analytics roll-ups, retention |
| `uc:health` | Component health (non-zero exit when DOWN) |
| `uc:keys [--register-dev]` | Public keys in use |
| `uc:export [--date=]` | Daily write-once export of configuration, changelogs, manifests and audit log (§13.3) |
| `uc:verify-release <id>` | Verify a release manifest signature and re-hash its packages from storage |
| `uc:demo` | Demo data (never in production) |

Backups, the restore drill and Prometheus monitoring (`GET /metrics`, alert rules) are covered in
[docs/operations.md](docs/operations.md).

## Versioning

`VERSION` is the single source of truth; manage it with `php scripts/version.php`
(`bump patch|minor|major [--pre=rc.1]`, `set`, `sync`, `check`). Every change goes
under `## [Unreleased]` in `CHANGELOG.md`. Details: [docs/versioning.md](docs/versioning.md).
