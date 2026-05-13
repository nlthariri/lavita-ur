# Cloud86 + Plesk Installatie (Productie)

Deze handleiding is bedoeld voor een eenmalige initiële installatie en daarna herhaalbare deploys.

## Voorwaarden

- Cloud86 hosting met Plesk en Node.js support
- MySQL-database beschikbaar via Plesk/phpMyAdmin
- Subdomein, bijvoorbeeld `uren.jouwdomein.nl`
- SSL-certificaat actief op het subdomein

## Stap 1 - Database in Plesk

1. Maak een MySQL-database en gebruiker aan in Plesk.
2. Controleer toegang in phpMyAdmin.
3. Noteer host, poort, databasenaam, gebruiker en wachtwoord.

## Stap 2 - Project op server plaatsen

1. Plaats projectbestanden in de applicatiemap.
2. Zorg dat Node.js versie 20+ actief is in Plesk.
3. Open shell in Plesk of SSH naar de host.

## Stap 3 - Omgeving instellen

1. Kopieer `.env.example` naar `.env`.
2. Vul productievariabelen in:

```bash
DATABASE_URL=mysql://USER:PASSWORD@HOST:3306/DB_NAAM
NODE_ENV=production
PORT=3000
WEB_CONCURRENCY=2
APP_BASE_URL=https://uren.jouwdomein.nl
AUTH_SESSION_SECRET=<lange geheime sleutel>
SMTP_HOST=...
SMTP_PORT=587
SMTP_USER=...
SMTP_PASSWORD=...
SMTP_FROM=La Vita <noreply@...>
REDIS_URL=redis://127.0.0.1:6379
BOOTSTRAP_ORGANIZATION_NAME=La Vita
BOOTSTRAP_OWNER_NAME=...
BOOTSTRAP_OWNER_EMAIL=...
BOOTSTRAP_OWNER_PASSWORD=...
```

## Stap 4 - Eenmalige installatie

Voer uit in de projectmap:

```bash
npm run install:cloud86
```

Dit script doet:
- dependencies installeren
- Prisma client genereren
- schema toepassen via migraties (`db:migrate:deploy`)
- build maken
- optioneel eigenaar bootstrap
- PM2 starten (als aanwezig)

## Stap 5 - Node.js in Plesk koppelen

- Document root: projectmap
- Startup command: `npm run start` (of via PM2 beheerd)
- Reverse proxy: actief
- Health endpoint: `/api/health`
- Readiness endpoint: `/api/ready`

## Herhaalbare deploy

Na nieuwe release:

```bash
npm run deploy:cloud86
```

## 24/7 run-aanbeveling

Gebruik PM2:

```bash
npm run start:pm2
npm run reload:pm2
npm run stop:pm2
```

## Backups

Dagelijkse databasebackup:

```bash
npm run backup:db
```

Plan deze via cron (bijvoorbeeld 02:00 dagelijks).

## Controlelijst na livegang

- `https://uren.jouwdomein.nl/api/health` geeft `ok`
- `https://uren.jouwdomein.nl/api/ready` geeft `ready`
- Inloggen en MFA werken
- Exports (CSV/XLSX/PDF) werken
- Maandrapportage-script werkt
- Retentie-script draait volgens planning
