#!/usr/bin/env bash
# verify-backup.sh — Hersteltest voor LaVita MySQL-back-ups (R-07)
#
# Gebruik: ./scripts/verify-backup.sh [pad/naar/backup.sql.gz]
#
# Wat dit script doet:
#   1. Neemt de meest recente back-up (of een opgegeven bestand).
#   2. Maakt een tijdelijke testdatabase aan.
#   3. Importeert de back-up in de testdatabase.
#   4. Voert sanity-checks uit (minimale tabelaantallen, kritieke tabellen aanwezig).
#   5. Verwijdert de testdatabase ongeacht de uitkomst.
#   6. Rapporteert PASS of FAIL met exitcode 0 of 1.
#
# Vereisten: mysql, mysqldump, gzip, php (voor DATABASE_URL-parsing)

set -euo pipefail

# ── Configuratie ────────────────────────────────────────────────────────────

BACKUP_DIR="${BACKUP_DIR:-/var/backups/lavita}"
VERIFY_DB_PREFIX="lavita_verify_"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
VERIFY_DB="${VERIFY_DB_PREFIX}${TIMESTAMP}"
REQUIRED_TABLES=("users" "auth_sessions" "work_entries" "objections" "email_outbox" "audit_events" "mfa_secrets")
MIN_TABLE_COUNT=15

# ── .env laden ──────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/../laravel-rebuild/.env"

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck source=/dev/null
  source "$ENV_FILE"
  set +a
fi

: "${DATABASE_URL:?DATABASE_URL ontbreekt. Stel DATABASE_URL in of laad .env}"

# ── DATABASE_URL parsen ─────────────────────────────────────────────────────

DB_USER=""
DB_PASS=""
DB_HOST=""
DB_PORT=""

while IFS='=' read -r key value; do
  case "$key" in
    DB_USER) DB_USER="$value" ;;
    DB_PASS) DB_PASS="$value" ;;
    DB_HOST) DB_HOST="$value" ;;
    DB_PORT) DB_PORT="$value" ;;
  esac
done < <(
  php -r '
    $raw = getenv("DATABASE_URL");
    $parts = parse_url($raw);
    echo "DB_USER=".rawurldecode($parts["user"] ?? "").PHP_EOL;
    echo "DB_PASS=".rawurldecode($parts["pass"] ?? "").PHP_EOL;
    echo "DB_HOST=".($parts["host"] ?? "localhost").PHP_EOL;
    echo "DB_PORT=".($parts["port"] ?? 3306).PHP_EOL;
  '
)

# ── Tijdelijk credentials-bestand ───────────────────────────────────────────

TMP_CNF="$(mktemp)"
chmod 600 "$TMP_CNF"
cat > "$TMP_CNF" <<EOF
[client]
user=${DB_USER}
password=${DB_PASS}
host=${DB_HOST}
port=${DB_PORT}
EOF

cleanup() {
  mysql --defaults-extra-file="$TMP_CNF" -e "DROP DATABASE IF EXISTS \`${VERIFY_DB}\`;" 2>/dev/null || true
  rm -f "$TMP_CNF"
}
trap cleanup EXIT

# ── Back-up selecteren ───────────────────────────────────────────────────────

if [[ $# -ge 1 ]]; then
  BACKUP_FILE="$1"
else
  BACKUP_FILE="$(find "$BACKUP_DIR" -maxdepth 1 -name "lavita-*.sql.gz" -not -name "*.gpg" | sort | tail -1)"
fi

if [[ -z "$BACKUP_FILE" || ! -f "$BACKUP_FILE" ]]; then
  echo "FAIL: Geen back-up bestand gevonden (BACKUP_DIR=${BACKUP_DIR})"
  exit 1
fi

echo "Verifiëren: ${BACKUP_FILE}"
echo "Test-database: ${VERIFY_DB}"

# ── Test-database aanmaken ───────────────────────────────────────────────────

mysql --defaults-extra-file="$TMP_CNF" -e \
  "CREATE DATABASE \`${VERIFY_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ── Back-up importeren ───────────────────────────────────────────────────────

echo "Importeren..."
zcat "$BACKUP_FILE" | mysql --defaults-extra-file="$TMP_CNF" "$VERIFY_DB"

# ── Sanity-checks ────────────────────────────────────────────────────────────

echo "Sanity-checks uitvoeren..."
FAIL=0

# 1. Minimale tabelaantallen
ACTUAL_COUNT=$(mysql --defaults-extra-file="$TMP_CNF" "$VERIFY_DB" \
  --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${VERIFY_DB}';")

if [[ "$ACTUAL_COUNT" -lt "$MIN_TABLE_COUNT" ]]; then
  echo "FAIL: Verwacht minimaal ${MIN_TABLE_COUNT} tabellen, gevonden ${ACTUAL_COUNT}."
  FAIL=1
else
  echo "OK: ${ACTUAL_COUNT} tabellen aanwezig (min ${MIN_TABLE_COUNT})."
fi

# 2. Kritieke tabellen aanwezig
for TABLE in "${REQUIRED_TABLES[@]}"; do
  EXISTS=$(mysql --defaults-extra-file="$TMP_CNF" "$VERIFY_DB" \
    --skip-column-names -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${VERIFY_DB}' AND table_name='${TABLE}';")
  if [[ "$EXISTS" -eq 0 ]]; then
    echo "FAIL: Verplichte tabel '${TABLE}' ontbreekt."
    FAIL=1
  else
    echo "OK: Tabel '${TABLE}' aanwezig."
  fi
done

# 3. Minimaal 1 rij in users (anders is de back-up leeg)
USER_COUNT=$(mysql --defaults-extra-file="$TMP_CNF" "$VERIFY_DB" \
  --skip-column-names -e "SELECT COUNT(*) FROM users;" 2>/dev/null || echo 0)

if [[ "$USER_COUNT" -eq 0 ]]; then
  echo "WARN: Geen gebruikers in back-up — is dit een lege omgeving?"
else
  echo "OK: ${USER_COUNT} gebruiker(s) in back-up."
fi

# ── Uitkomst ─────────────────────────────────────────────────────────────────

if [[ "$FAIL" -ne 0 ]]; then
  echo ""
  echo "RESULTAAT: FAIL — back-up is onvolledig of corrupt."
  exit 1
fi

echo ""
echo "RESULTAAT: PASS — back-up succesvol geverifieerd (${BACKUP_FILE})."
exit 0
