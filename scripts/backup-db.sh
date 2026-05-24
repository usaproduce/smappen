#!/usr/bin/env bash
# Nightly MySQL backup. Compresses with gzip, keeps the last 30 dailies +
# the first-of-month for the last 12 months locally; optionally uploads to
# DigitalOcean Spaces if rclone is configured.
#
# Cron:
#   0 3 * * * /var/www/smappen/scripts/backup-db.sh >> /var/www/smappen/storage/logs/backup.log 2>&1

set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE="$(pwd)/.env"
[ -f "$ENV_FILE" ] || { echo "missing .env"; exit 1; }

# Source DB credentials (kept out of the shell environment otherwise).
DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d= -f2 | tr -d '"' | tr -d "\r")
DB_NAME=$(grep -E '^DB_NAME=' "$ENV_FILE" | cut -d= -f2 | tr -d '"' | tr -d "\r")
DB_USER=$(grep -E '^DB_USER=' "$ENV_FILE" | cut -d= -f2 | tr -d '"' | tr -d "\r")
DB_PASS=$(grep -E '^DB_PASS=' "$ENV_FILE" | cut -d= -f2 | tr -d '"' | tr -d "\r")

BACKUP_DIR="$(pwd)/storage/backups"
mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/monthly"

STAMP=$(date +%Y-%m-%d)
DAY_OF_MONTH=$(date +%d)
DAILY_FILE="$BACKUP_DIR/daily/smappen-$STAMP.sql.gz"

echo "[$(date -Is)] Dumping $DB_NAME → $DAILY_FILE"
mysqldump --single-transaction --quick --lock-tables=false \
  --routines --triggers --events \
  -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  | gzip -9 > "$DAILY_FILE"

# First of the month: also copy to monthly retention.
if [ "$DAY_OF_MONTH" = "01" ]; then
  cp "$DAILY_FILE" "$BACKUP_DIR/monthly/smappen-$STAMP.sql.gz"
fi

# Prune.
find "$BACKUP_DIR/daily" -name '*.sql.gz' -mtime +30 -delete
find "$BACKUP_DIR/monthly" -name '*.sql.gz' -mtime +400 -delete

# Optional: upload to Spaces if rclone remote `spaces:` is configured.
# Configure with: rclone config (provide DO Spaces key/secret)
if command -v rclone >/dev/null && rclone listremotes | grep -q '^spaces:'; then
  REMOTE_BUCKET="${SPACES_BUCKET:-smappen-backups}"
  echo "[$(date -Is)] Uploading to spaces:$REMOTE_BUCKET/daily/"
  rclone copy "$DAILY_FILE" "spaces:$REMOTE_BUCKET/daily/" \
    --no-traverse --transfers=2 --retries=3 || \
    echo "[$(date -Is)] rclone upload failed (non-fatal)"
fi

echo "[$(date -Is)] Backup done: $(du -h "$DAILY_FILE" | cut -f1)"
