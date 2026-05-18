#!/usr/bin/env bash
# verify-backup.sh — Backup-integriteitscheck voor LaVita Urenregistratie
#
# Alternatieve runner voor cron buiten Laravel om (Requirement 13.4).
# Kan worden ingepland via crontab: 0 3 * * * /pad/naar/verify-backup.sh
#
# Wat dit script doet:
#   1. Zoekt de meest recente backup (ZIP-archief van spatie/laravel-backup).
#   2. Voert een decrypt-test uit met BACKUP_ARCHIVE_PASSWORD.
#   3. Controleert SHA-256 manifest-integriteit.
#   4. Bij mislukking: stuurt alert-mail en logt naar syslog.
#   5. Rapporteert PASS of FAIL met exitcode 0 of 1.
#
# Vereisten: unzip, sha256sum, mail (of sendmail), php (voor .env parsing)
#
# Configuratie via environment of .env:
#   BACKUP_ARCHIVE_PASSWORD  — Wachtwoord voor het versleutelde ZIP-archief
#   BACKUP_ALERT_EMAIL       — E-mailadres voor alerts (default: admin@lavita.nl)
#   BACKUP_STORAGE_PATH      — Pad naar backup-opslag (default: storage/app/private)

set -euo pipefail

# ── Configuratie ────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_ROOT="${SCRIPT_DIR}/laravel-rebuild"
ENV_FILE="${LARAVEL_ROOT}/.env"

# Laad .env als die bestaat
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck source=/dev/null
  source "$ENV_FILE"
  set +a
fi

BACKUP_PASSWORD="${BACKUP_ARCHIVE_PASSWORD:-}"
ALERT_EMAIL="${BACKUP_ALERT_EMAIL:-admin@lavita.nl}"
APP_NAME="${APP_NAME:-lavita-urenregistratie}"

# Backup-directory: spatie/laravel-backup slaat op in storage/app/{backup-name}/
BACKUP_BASE="${LARAVEL_ROOT}/storage/app/${APP_NAME}"

# Alternatief: als de backup op een andere locatie staat
if [[ -n "${BACKUP_STORAGE_PATH:-}" ]]; then
  BACKUP_BASE="$BACKUP_STORAGE_PATH"
fi

TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S')"
HOSTNAME="$(hostname)"
FAIL_REASONS=()

# ── Functies ────────────────────────────────────────────────────────────────

log_info() {
  echo "[INFO] $1"
  logger -t "lavita-backup-verify" "$1" 2>/dev/null || true
}

log_error() {
  echo "[ERROR] $1" >&2
  logger -t "lavita-backup-verify" -p user.err "$1" 2>/dev/null || true
}

send_alert() {
  local reason="$1"
  local subject="[ALERT] LaVita Backup-integriteitscheck mislukt"
  local body="⚠️ Backup-integriteitscheck MISLUKT

Reden: ${reason}
Tijdstip: ${TIMESTAMP}
Server: ${HOSTNAME}

Actie vereist: controleer de backup-configuratie en voer handmatig een backup:run uit.

---
Dit bericht is automatisch gegenereerd door verify-backup.sh"

  if command -v mail &>/dev/null; then
    echo "$body" | mail -s "$subject" "$ALERT_EMAIL"
    log_info "Alert-mail verstuurd naar ${ALERT_EMAIL}"
  elif command -v sendmail &>/dev/null; then
    {
      echo "To: ${ALERT_EMAIL}"
      echo "Subject: ${subject}"
      echo "Content-Type: text/plain; charset=utf-8"
      echo ""
      echo "$body"
    } | sendmail -t
    log_info "Alert-mail verstuurd naar ${ALERT_EMAIL} (sendmail)"
  else
    log_error "Kan geen alert-mail versturen: mail/sendmail niet beschikbaar"
  fi

  # Schrijf ook een audit-event via Laravel artisan (als beschikbaar)
  if [[ -f "${LARAVEL_ROOT}/artisan" ]]; then
    php "${LARAVEL_ROOT}/artisan" tinker --execute="
      \App\Models\AuditEvent::create([
        'organization_id' => null,
        'actor_id' => null,
        'action' => 'BACKUP_INTEGRITY_FAILED',
        'target_type' => 'backup',
        'target_id' => 'latest',
        'after_data' => json_encode(['reason' => '${reason}', 'timestamp' => '${TIMESTAMP}', 'source' => 'verify-backup.sh']),
      ]);
    " 2>/dev/null || log_error "Kon audit-event niet schrijven via artisan"
  fi
}

# ── Stap 1: Zoek meest recente backup ───────────────────────────────────────

log_info "Backup-integriteitscheck gestart"
log_info "Zoeken in: ${BACKUP_BASE}"

if [[ ! -d "$BACKUP_BASE" ]]; then
  FAIL_REASONS+=("Backup-directory niet gevonden: ${BACKUP_BASE}")
  log_error "${FAIL_REASONS[-1]}"
  send_alert "${FAIL_REASONS[-1]}"
  echo "RESULTAAT: FAIL"
  exit 1
fi

LATEST_BACKUP="$(find "$BACKUP_BASE" -maxdepth 2 -name "*.zip" -type f | sort | tail -1)"

if [[ -z "$LATEST_BACKUP" ]]; then
  FAIL_REASONS+=("Geen backup ZIP-bestanden gevonden in ${BACKUP_BASE}")
  log_error "${FAIL_REASONS[-1]}"
  send_alert "${FAIL_REASONS[-1]}"
  echo "RESULTAAT: FAIL"
  exit 1
fi

log_info "Laatste backup: ${LATEST_BACKUP}"

# Controleer bestandsgrootte
FILE_SIZE=$(stat -c%s "$LATEST_BACKUP" 2>/dev/null || stat -f%z "$LATEST_BACKUP" 2>/dev/null || echo "0")

if [[ "$FILE_SIZE" -eq 0 ]]; then
  FAIL_REASONS+=("Backup-bestand is leeg (0 bytes): ${LATEST_BACKUP}")
  log_error "${FAIL_REASONS[-1]}"
  send_alert "${FAIL_REASONS[-1]}"
  echo "RESULTAAT: FAIL"
  exit 1
fi

log_info "Bestandsgrootte: $(echo "scale=2; ${FILE_SIZE}/1048576" | bc 2>/dev/null || echo "${FILE_SIZE} bytes") MB"

# Controleer ouderdom (mag niet ouder zijn dan 26 uur)
FILE_AGE_HOURS=$(( ($(date +%s) - $(stat -c%Y "$LATEST_BACKUP" 2>/dev/null || stat -f%m "$LATEST_BACKUP" 2>/dev/null || echo "0")) / 3600 ))

if [[ "$FILE_AGE_HOURS" -gt 26 ]]; then
  FAIL_REASONS+=("Backup is ${FILE_AGE_HOURS} uur oud (max 26 uur): ${LATEST_BACKUP}")
  log_error "${FAIL_REASONS[-1]}"
fi

# ── Stap 2: Decrypt-test ────────────────────────────────────────────────────

log_info "Decrypt-test uitvoeren..."

if [[ -n "$BACKUP_PASSWORD" ]]; then
  # Test of het archief geopend kan worden met het wachtwoord
  if ! unzip -t -P "$BACKUP_PASSWORD" "$LATEST_BACKUP" >/dev/null 2>&1; then
    FAIL_REASONS+=("Decrypt-test mislukt: kan archief niet openen met BACKUP_ARCHIVE_PASSWORD")
    log_error "${FAIL_REASONS[-1]}"
  else
    log_info "✓ Decrypt-test geslaagd"
  fi
else
  # Geen wachtwoord: test of het archief geldig is
  if ! unzip -t "$LATEST_BACKUP" >/dev/null 2>&1; then
    FAIL_REASONS+=("ZIP-integriteitstest mislukt: archief is corrupt")
    log_error "${FAIL_REASONS[-1]}"
  else
    log_info "✓ ZIP-integriteitstest geslaagd (geen encryptie geconfigureerd)"
  fi
fi

# ── Stap 3: SHA-256 manifest check ─────────────────────────────────────────

log_info "SHA-256 manifest check uitvoeren..."

MANIFEST_FILE="${LATEST_BACKUP}.sha256"
CURRENT_HASH="$(sha256sum "$LATEST_BACKUP" 2>/dev/null | awk '{print $1}' || shasum -a 256 "$LATEST_BACKUP" 2>/dev/null | awk '{print $1}')"

if [[ -z "$CURRENT_HASH" ]]; then
  FAIL_REASONS+=("Kan SHA-256 hash niet berekenen")
  log_error "${FAIL_REASONS[-1]}"
elif [[ -f "$MANIFEST_FILE" ]]; then
  STORED_HASH="$(cat "$MANIFEST_FILE" | awk '{print $1}')"
  if [[ "$CURRENT_HASH" != "$STORED_HASH" ]]; then
    FAIL_REASONS+=("SHA-256 mismatch! Verwacht: ${STORED_HASH}, Berekend: ${CURRENT_HASH}")
    log_error "${FAIL_REASONS[-1]}"
  else
    log_info "✓ SHA-256 manifest check geslaagd"
  fi
else
  # Maak manifest aan voor toekomstige verificaties
  echo "$CURRENT_HASH  $LATEST_BACKUP" > "$MANIFEST_FILE"
  log_info "SHA-256 manifest aangemaakt: ${MANIFEST_FILE}"
fi

log_info "SHA-256: ${CURRENT_HASH}"

# ── Resultaat ───────────────────────────────────────────────────────────────

if [[ ${#FAIL_REASONS[@]} -gt 0 ]]; then
  echo ""
  echo "RESULTAAT: FAIL — ${#FAIL_REASONS[@]} probleem(en) gevonden:"
  for reason in "${FAIL_REASONS[@]}"; do
    echo "  • ${reason}"
  done
  send_alert "$(printf '%s; ' "${FAIL_REASONS[@]}")"
  exit 1
fi

echo ""
echo "RESULTAAT: PASS — backup succesvol geverifieerd"
echo "  Bestand: ${LATEST_BACKUP}"
echo "  Grootte: $(echo "scale=2; ${FILE_SIZE}/1048576" | bc 2>/dev/null || echo "${FILE_SIZE} bytes") MB"
echo "  SHA-256: ${CURRENT_HASH}"
exit 0
