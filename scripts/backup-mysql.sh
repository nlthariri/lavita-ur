#!/usr/bin/env bash
set -euo pipefail

if [[ -f .env ]]; then
  set -a
  source .env
  set +a
fi

: "${DATABASE_URL:?DATABASE_URL ontbreekt}"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/lavita}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

DB_USER=""
DB_PASS=""
DB_HOST=""
DB_PORT=""
DB_NAME=""

while IFS='=' read -r key value; do
  case "$key" in
    DB_USER) DB_USER="$value" ;;
    DB_PASS) DB_PASS="$value" ;;
    DB_HOST) DB_HOST="$value" ;;
    DB_PORT) DB_PORT="$value" ;;
    DB_NAME) DB_NAME="$value" ;;
  esac
done < <(
  node -e '
    const url = new URL(process.env.DATABASE_URL);
    if (url.protocol !== "mysql:") {
      throw new Error("DATABASE_URL moet met mysql:// beginnen");
    }
    const clean = (v) => (v || "").replace(/\n/g, "");
    const dbName = (url.pathname || "").replace(/^\//, "");
    console.log(`DB_USER=${clean(decodeURIComponent(url.username || ""))}`);
    console.log(`DB_PASS=${clean(decodeURIComponent(url.password || ""))}`);
    console.log(`DB_HOST=${clean(url.hostname || "localhost")}`);
    console.log(`DB_PORT=${clean(url.port || "3306")}`);
    console.log(`DB_NAME=${clean(dbName)}`);
  '
)

if [[ -z "$DB_USER" || -z "$DB_HOST" || -z "$DB_PORT" || -z "$DB_NAME" ]]; then
  echo "DATABASE_URL kon niet correct worden geparsed"
  exit 1
fi

TMP_CNF="$(mktemp)"
cleanup() {
  rm -f "$TMP_CNF"
}
trap cleanup EXIT

chmod 600 "$TMP_CNF"
cat > "$TMP_CNF" <<EOF
[client]
user=$DB_USER
password=$DB_PASS
host=$DB_HOST
port=$DB_PORT
EOF

OUTFILE="$BACKUP_DIR/lavita-${DB_NAME}-${TIMESTAMP}.sql.gz"

mysqldump --defaults-extra-file="$TMP_CNF" "$DB_NAME" | gzip -9 > "$OUTFILE"

if [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]]; then
  gpg --batch --yes --encrypt --recipient "$BACKUP_GPG_RECIPIENT" "$OUTFILE"
  rm -f "$OUTFILE"
fi

find "$BACKUP_DIR" -type f -name "lavita-*.sql.gz*" -mtime "+$RETENTION_DAYS" -delete

echo "Backup voltooid: $BACKUP_DIR"
