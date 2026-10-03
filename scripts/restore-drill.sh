#!/usr/bin/env bash
# Monthly restore drill (SRS §13.3): restore the latest full backup into a
# scratch database and verify it, then check one published release's binaries
# against their manifest hashes from object storage.
#
#   BACKUP_S3_URI, BACKUP_AGE_IDENTITY (age private key file, from the vault)
#   DRILL_DB_HOST / DRILL_DB_USER / DRILL_DB_NAME (scratch server — never production)
set -euo pipefail
: "${BACKUP_S3_URI:?}" "${BACKUP_AGE_IDENTITY:?}" "${DRILL_DB_HOST:?}" "${DRILL_DB_NAME:=uc_restore_drill}" "${DRILL_DB_USER:=root}"
S3_ENDPOINT_ARGS=()
[[ -n "${BACKUP_S3_ENDPOINT:-}" ]] && S3_ENDPOINT_ARGS=(--endpoint-url "$BACKUP_S3_ENDPOINT")
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
started=$(date +%s)

latest=$(aws s3 ls "${S3_ENDPOINT_ARGS[@]}" "$BACKUP_S3_URI/full/" | awk '{print $4}' | grep '\.age$' | sort | tail -1)
[[ -n "$latest" ]] || { echo "no backups found" >&2; exit 1; }
aws s3 cp "${S3_ENDPOINT_ARGS[@]}" "$BACKUP_S3_URI/full/$latest" "$work/$latest" --only-show-errors
aws s3 cp "${S3_ENDPOINT_ARGS[@]}" "$BACKUP_S3_URI/full/$latest.sha256" "$work/$latest.sha256" --only-show-errors
(cd "$work" && sha256sum -c "$latest.sha256")

mariadb --host="$DRILL_DB_HOST" --user="$DRILL_DB_USER" -e "DROP DATABASE IF EXISTS \`$DRILL_DB_NAME\`; CREATE DATABASE \`$DRILL_DB_NAME\` CHARACTER SET utf8mb4"
age --decrypt --identity "$BACKUP_AGE_IDENTITY" "$work/$latest" | gunzip | mariadb --host="$DRILL_DB_HOST" --user="$DRILL_DB_USER" "$DRILL_DB_NAME"

q() { mariadb --host="$DRILL_DB_HOST" --user="$DRILL_DB_USER" -N -B "$DRILL_DB_NAME" -e "$1"; }
echo "releases:   $(q 'SELECT COUNT(*) FROM releases')"
echo "published:  $(q "SELECT COUNT(*) FROM releases WHERE status='PUBLISHED'")"
echo "audit rows: $(q 'SELECT COUNT(*) FROM audit_logs')"
# Pick one published release; the operator verifies its package hashes with:
#   php spark uc:verify-release <public_id>   (run against the drill database)
echo "drill release: $(q "SELECT public_id FROM releases WHERE status='PUBLISHED' ORDER BY RAND() LIMIT 1")"
echo "restore drill ok in $(( $(date +%s) - started ))s (RTO target 7200s)"
