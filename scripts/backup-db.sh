#!/usr/bin/env bash
# Daily full MariaDB backup for the Update Center (SRS §13.3, FR-UC-OPS-04).
#
#   RPO 15 min: binary logging must be on (log_bin, binlog_format=ROW,
#   expire_logs_days >= 2). This script rotates the binlog, and
#   ship-binlogs.sh (cron */5) copies closed binlogs off-site.
#   RTO 2 h:    restore = latest full dump + binlog replay (docs/operations.md).
#
# The dump is encrypted with `age` for an offline recipient key and uploaded to
# the off-site bucket. Off-site retention (35 days) and Object Lock are bucket
# lifecycle settings, not this script's job.
#
# Environment:
#   DB_HOST DB_USER DB_NAME     connection (password via ~/.my.cnf or MYSQL_PWD)
#   BACKUP_AGE_RECIPIENT        age public key (age1…)
#   BACKUP_S3_URI               e.g. s3://worksuite-uc-backups/db
#   AWS_* / --endpoint-url      credentials for the off-site provider
set -euo pipefail

: "${DB_HOST:=mariadb}" "${DB_USER:=backup}" "${DB_NAME:=update_center}"
: "${BACKUP_AGE_RECIPIENT:?set BACKUP_AGE_RECIPIENT}" "${BACKUP_S3_URI:?set BACKUP_S3_URI}"
S3_ENDPOINT_ARGS=()
[[ -n "${BACKUP_S3_ENDPOINT:-}" ]] && S3_ENDPOINT_ARGS=(--endpoint-url "$BACKUP_S3_ENDPOINT")

stamp=$(date -u +%Y%m%dT%H%M%SZ)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
file="$work/update_center-$stamp.sql.gz.age"

# Consistent snapshot without locking InnoDB tables; --master-data=2 records the
# binlog position as a comment for point-in-time recovery.
mariadb-dump --host="$DB_HOST" --user="$DB_USER" \
  --single-transaction --quick --routines --triggers --events \
  --flush-logs --master-data=2 --hex-blob --default-character-set=utf8mb4 \
  "$DB_NAME" \
  | gzip -9 \
  | age --encrypt --recipient "$BACKUP_AGE_RECIPIENT" --output "$file"

sha=$(sha256sum "$file" | cut -d' ' -f1)
echo "$sha  $(basename "$file")" > "$file.sha256"

aws s3 cp "${S3_ENDPOINT_ARGS[@]}" "$file" "$BACKUP_S3_URI/full/$(basename "$file")" --only-show-errors
aws s3 cp "${S3_ENDPOINT_ARGS[@]}" "$file.sha256" "$BACKUP_S3_URI/full/$(basename "$file").sha256" --only-show-errors

echo "backup ok: $(basename "$file") sha256=$sha size=$(stat -c%s "$file")"
