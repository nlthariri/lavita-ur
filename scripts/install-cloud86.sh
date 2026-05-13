#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

echo "[1/7] Controle op .env"
if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "- .env aangemaakt vanuit .env.example"
  echo "- Vul nu eerst productiegegevens in .env in en voer script daarna opnieuw uit."
  exit 1
fi

echo "[2/7] Dependencies installeren"
npm ci

echo "[3/7] Prisma client genereren"
npm run db:generate

echo "[4/7] Database schema toepassen via migraties"
npm run db:migrate:deploy

echo "[5/7] Build"
npm run build

echo "[6/7] Eerste eigenaar bootstrap (indien variabelen aanwezig)"
if [[ -n "${BOOTSTRAP_OWNER_EMAIL:-}" ]] && [[ -n "${BOOTSTRAP_OWNER_PASSWORD:-}" ]] && [[ -n "${BOOTSTRAP_OWNER_NAME:-}" ]] && [[ -n "${BOOTSTRAP_ORGANIZATION_NAME:-}" ]]; then
  npm run bootstrap:owner
else
  echo "- Bootstrap variabelen niet volledig; bootstrap overgeslagen"
fi

echo "[7/7] Process start"
if command -v pm2 >/dev/null 2>&1; then
  pm2 start ecosystem.config.cjs --env production --update-env || pm2 reload ecosystem.config.cjs --env production --update-env
  pm2 save
  echo "- PM2 gestart en opgeslagen"
else
  echo "- PM2 niet gevonden. Start handmatig met: npm run start"
fi

echo "Installatie gereed. Controleer: /api/health en /api/ready"
