#!/usr/bin/env bash
# Copies closed MariaDB binary logs off-site every 5 minutes (RPO 15 min, §13.3).
# Run from cron on the database host: */5 * * * * scripts/ship-binlogs.sh
set -euo pipefail
: "${BINLOG_DIR:=/var/lib/mysql}" "${BINLOG_BASENAME:=mysql-bin}"
: "${BACKUP_AGE_RECIPIENT:?set BACKUP_AGE_RECIPIENT}" "${BACKUP_S3_URI:?set BACKUP_S3_URI}"
S3_ENDPOINT_ARGS=()
[[ -n "${BACKUP_S3_ENDPOINT:-}" ]] && S3_ENDPOINT_ARGS=(--endpoint-url "$BACKUP_S3_ENDPOINT")
state="${BINLOG_STATE:-/var/lib/uc-backup/shipped}"
mkdir -p "$(dirname "$state")"; touch "$state"

# Force a rotation so the newest events land in a closed file.
mariadb --host="${DB_HOST:-localhost}" --user="${DB_USER:-backup}" -e 'FLUSH BINARY LOGS'

# Every binlog except the one currently being written.
mapfile -t logs < <(ls -1 "$BINLOG_DIR/$BINLOG_BASENAME".[0-9]* | sort | head -n -1)
for log in "${logs[@]}"; do
  name=$(basename "$log")
  grep -qx "$name" "$state" && continue
  age --encrypt --recipient "$BACKUP_AGE_RECIPIENT" "$log" \
    | aws s3 cp "${S3_ENDPOINT_ARGS[@]}" - "$BACKUP_S3_URI/binlog/$name.age" --only-show-errors
  echo "$name" >> "$state"
done
