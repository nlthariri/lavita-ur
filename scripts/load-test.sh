#!/usr/bin/env bash
# load-test.sh — Basis performance-baseline voor LaVita API (R-03)
#
# Vereisten: curl, apache2-utils (ab) of wrk
# Gebruik:   ./scripts/load-test.sh [base_url] [concurrency] [requests]
#
# Gemeten eindpunten:
#   - GET /api/health            (geen auth, laag)
#   - POST /api/auth/login       (publiek, met geldige credentials)
#   - GET /api/internal/work-entries (bearer token vereist)
#
# Geeft een FAIL terug als p99 > drempel of error_rate > 1%.

set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"
CONCURRENCY="${2:-10}"
REQUESTS="${3:-200}"

# Drempelwaarden (aanpassen op basis van SLO's)
MAX_P99_MS=500          # p99 ≤ 500 ms
MAX_ERROR_RATE_PCT=1    # error rate ≤ 1%

FAIL=0

check_ab() {
  local endpoint="$1"
  local label="$2"
  local extra_flags="${3:-}"

  if ! command -v ab &>/dev/null; then
    echo "SKIP: 'ab' (apache2-utils) niet gevonden — installeer met: apt install apache2-utils"
    return
  fi

  echo ""
  echo "── ${label} ──────────────────────────────────────────────"
  # shellcheck disable=SC2086
  local result
  result=$(ab -n "$REQUESTS" -c "$CONCURRENCY" $extra_flags "${BASE_URL}${endpoint}" 2>&1)

  local p99
  p99=$(echo "$result" | grep "99%" | awk '{print $2}')
  local failed_requests
  failed_requests=$(echo "$result" | grep "Failed requests:" | awk '{print $3}')
  local total_requests
  total_requests=$(echo "$result" | grep "Complete requests:" | awk '{print $3}')

  local error_rate=0
  if [[ -n "$total_requests" && "$total_requests" -gt 0 && -n "$failed_requests" ]]; then
    error_rate=$(( (failed_requests * 100) / total_requests ))
  fi

  echo "p99: ${p99:-?} ms | errors: ${failed_requests:-?}/${total_requests:-?} (${error_rate}%)"

  if [[ -n "$p99" && "$p99" -gt "$MAX_P99_MS" ]]; then
    echo "FAIL: p99 ${p99}ms > drempel ${MAX_P99_MS}ms"
    FAIL=1
  fi

  if [[ "$error_rate" -gt "$MAX_ERROR_RATE_PCT" ]]; then
    echo "FAIL: error rate ${error_rate}% > drempel ${MAX_ERROR_RATE_PCT}%"
    FAIL=1
  fi
}

echo "LaVita performance baseline"
echo "URL: ${BASE_URL} | concurrency: ${CONCURRENCY} | requests: ${REQUESTS}"
echo "Drempels: p99 ≤ ${MAX_P99_MS}ms, error rate ≤ ${MAX_ERROR_RATE_PCT}%"

# 1. Health endpoint (geen auth)
check_ab "/api/health" "GET /api/health"

# 2. Ready endpoint (geen auth)
check_ab "/api/ready" "GET /api/ready"

echo ""
echo "── Handmatig te testen (vereist auth-token) ──────────────────"
echo "Voor geauthenticeerde endpoints:"
echo "  TOKEN=\$(curl -s -X POST ${BASE_URL}/api/auth/login \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d '{\"email\":\"owner@uw-org.nl\",\"password\":\"UwWachtwoord\"}' \\"
echo "    | jq -r '.session_token')"
echo ""
echo "  ab -n ${REQUESTS} -c ${CONCURRENCY} \\"
echo "    -H \"Authorization: Bearer \$TOKEN\" \\"
echo "    ${BASE_URL}/api/internal/work-entries"

echo ""
if [[ "$FAIL" -ne 0 ]]; then
  echo "RESULTAAT: FAIL — een of meer drempelwaarden overschreden."
  exit 1
fi
echo "RESULTAAT: PASS — health endpoints binnen drempelwaarden."
exit 0
