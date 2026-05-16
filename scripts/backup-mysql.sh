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
  php -r '
    $raw = getenv("DATABASE_URL");
    if ($raw === false || $raw === "") {
        fwrite(STDERR, "DATABASE_URL ontbreekt\n");
        exit(1);
    }
    $parts = parse_url($raw);
    if ($parts === false || ($parts["scheme"] ?? "") !== "mysql") {
        fwrite(STDERR, "DATABASE_URL moet met mysql:// beginnen\n");
        exit(1);
    }
    $user = rawurldecode($parts["user"] ?? "");
    $pass = rawurldecode($parts["pass"] ?? "");
    $host = $parts["host"] ?? "localhost";
    $port = (string) ($parts["port"] ?? 3306);
    $path = $parts["path"] ?? "";
    $dbName = ltrim($path, "/");

    echo "DB_USER=".str_replace("\n", "", $user).PHP_EOL;
    echo "DB_PASS=".str_replace("\n", "", $pass).PHP_EOL;
    echo "DB_HOST=".str_replace("\n", "", $host).PHP_EOL;
    echo "DB_PORT=".str_replace("\n", "", $port).PHP_EOL;
    echo "DB_NAME=".str_replace("\n", "", $dbName).PHP_EOL;
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
