# Operations: backups, recovery and monitoring

Implements SRS §13.3 (backup and recovery) and §13.4 (monitoring and alerts),
FR-UC-OPS-04 / FR-UC-OPS-05.

## Backups (§13.3)

| What | How | Where |
|---|---|---|
| Database (full) | `scripts/backup-db.sh` daily. A consistent `mariadb-dump --single-transaction` with the binlog position, gzip, encrypted with `age`. | `$BACKUP_S3_URI/full/` |
| Database (point in time) | Binary logging on (`log_bin`, `binlog_format=ROW`). `scripts/ship-binlogs.sh` runs every 5 min, which gives a 15-minute RPO. | `$BACKUP_S3_URI/binlog/` |
| Configuration, changelogs, manifests, audit log | `php spark uc:export`, run daily by `uc:scheduler` (`export_daily`). It writes one gzip JSON per document. The audit log is exported incrementally. Secrets are never exported. | Provider named in `updatecenter.exportProvider`, under `exports/YYYY-MM-DD/` |
| Package binaries | Object versioning on the release bucket, plus cross-provider replication R2 → S3. Storage admin shows the status. | Provider settings |
| Signing keys | KMS-managed backup and escrow per provider. Break-glass is documented with the key rotation runbook. | KMS |

The bucket's lifecycle and lock settings enforce off-site retention (35 days) and write-once storage:

* Backup bucket: lifecycle expiry at 35 days, server-side encryption, no delete permission for the backup credentials.
* Export bucket: S3 Object Lock or R2 bucket lock in **compliance** mode. `uc:export` also refuses to overwrite an existing key.

Cron on the database host:

```cron
15 2 * * *  /srv/update-center/scripts/backup-db.sh    >> /var/log/uc-backup.log 2>&1
*/5 * * * * /srv/update-center/scripts/ship-binlogs.sh >> /var/log/uc-binlog.log 2>&1
```

## Recovery (RTO 2 h)

1. Provision a database server and fetch the latest `full/*.age` and its `.sha256`, then verify the checksum.
2. `age --decrypt --identity <key> dump.sql.gz.age | gunzip | mariadb update_center`
3. Read the binlog position from the dump header (`-- CHANGE MASTER TO MASTER_LOG_FILE=…, MASTER_LOG_POS=…`).
   Fetch the later `binlog/*.age` files, decrypt them, and replay them:
   `mysqlbinlog --start-position=<pos> mysql-bin.0000NN … [--stop-datetime="<before incident>"] | mariadb update_center`
4. Point `update-api`, `update-worker` and `update-scheduler` at the database, then run `php spark uc:health`.
5. Spot-check releases: `php spark uc:verify-release <release id>`.

### Monthly restore drill

`scripts/restore-drill.sh` restores the latest backup into a scratch database, verifies the
checksum, and prints row counts and one random published release. Then run
`php spark uc:verify-release <id>`. It checks the release manifest signature and re-hashes
every package object in storage against the revision record and the signed manifest. Record
the drill duration against the 2 h RTO.

## Monitoring (§13.4)

`GET /metrics` serves Prometheus text format. It needs the bearer token from the
`UC_METRICS_TOKEN` secret (`updatecenter.metricsTokenRef`). Without a token configured,
the endpoint returns 404. nginx only allows private networks.

Run a local Prometheus with `docker compose --profile monitoring up prometheus`.
The config lives in `docker/monitoring/prometheus.yml` and the rules in `docker/monitoring/alerts.yml`.

| Signal (§13.4) | Metric | Alert |
|---|---|---|
| API latency / errors | `uc_http_request_duration_seconds`, `uc_http_requests_total` | `ApiLatencyHigh` (p95 > 400 ms, 5 min), `ApiErrorRateHigh` (5xx > 1 %) |
| Update check failures | `uc_http_requests_total{group=~"update_check\|server_update_check"}` | `UpdateCheckFailures` (> 2 %) |
| Manifest failures | `uc_http_requests_total{group="manifest",code="5xx"}`, `uc_events_total{event=~"signing_refused\|signature_verification_failed"}` | `ManifestFailure` |
| Storage / resolver | `uc_resolver_failures_5m`, `uc_component_up{component=~"storage\|cdn"}` | `ResolverFailures` (> 0.5 %), `StorageProbeFailed` |
| Uploads | `uc_upload_rejected_1h`, `uc_upload_processed_1h`, `uc_upload_stuck` | `UploadProcessingFailures` (> 10 %), `UploadJobStuck` (> 15 min) |
| Queue | `uc_queue_backlog`, `uc_queue_oldest_age_seconds` | `QueueBacklog` (> 500 or > 10 min) |
| Database / Redis | mysqld_exporter / redis_exporter (replication lag, memory) | Exporter rules (lag > 30 s, memory > 80 %) |
| Release health | `uc_update_events_1h{type}` | `InstallFailureRate` (> 5 %), `RollbackSpike` |
| Tenant catalog | `uc_webhook_oldest_failing_seconds` | `TenantWebhookFailing` (> 1 h) |

Request counters live in Redis (`uc:metrics` hash), so every API container feeds the same
series. Unhandled exceptions are counted too, through `MetricsExceptionHandler`. You can
optionally forward to WorkSuite Core Monitoring with Prometheus `remote_write`.

## WorkSuite OS updates (§18)
* Migration `2026-10-15-000008_CreateOsReleaseTables` and the catalog seed add the `os` product (amd64/arm64; DEV = canary,
  BETA = pilot, STABLE). Run `php spark migrate && php spark db:seed CatalogSeeder`.
* The signing service signs kind `OS_MANIFEST` with the release key in the OS's flat scheme (`signature: "<kid>:<base64>"`).
  Redeploy `backend/signing` together with the API. Export the release public keys from `GET /api/v1/os/keys` into
  Worksuite-OS `packaging/keyring/ed25519/release/<kid>.pem` before the first OS release (devices fail closed without them).
* Create an API client of kind CI with `os.publish` (Admin › Administration › API clients) and store `<client_id>.<secret>` as
  the `UPDATE_CENTER_TOKEN` secret of Livezen-Technologies/Worksuite-OS. CI publishes to development (canary) / pilot only;
  promotion to stable and pause / resume / pull / emergency stay with release managers (`/api/v1/admin/os/...`).
* The APT repository is signed in the OS CI with its archive GPG key (`tools/repo/publish.sh`); the Update Center only stores and
  serves it. Suites carry a 14-day `Valid-Until`, so a release (or the scheduled re-sign job in the OS repo) must run at least weekly.
