#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

echo "[1/6] Dependencies"
npm ci

echo "[2/6] Prisma generate"
npm run db:generate

echo "[3/6] Migraties"
npm run db:migrate:deploy

echo "[4/6] Build"
npm run build

echo "[5/6] PM2 reload"
if command -v pm2 >/dev/null 2>&1; then
  pm2 reload ecosystem.config.cjs --env production --update-env || pm2 start ecosystem.config.cjs --env production --update-env
  pm2 save
else
  echo "PM2 niet gevonden; fallback start"
  npm run start
fi

echo "[6/6] Health check"
curl -fsS "${APP_BASE_URL:-http://localhost:3000}/api/ready" >/dev/null

echo "Deploy gereed"
