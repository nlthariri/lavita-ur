# La Vita Urenregistratie

Enterprise webapplicatie voor urenregistratie, bezwaarafhandeling en automatische ATW-bewaking conform opdrachtspecificatie v1.1.

## Productiestack

- Next.js 16 + TypeScript
- MySQL + Prisma
- SMTP mailverzending met template-engine
- Cloud86 hosting met Plesk (Node.js + MySQL/phpMyAdmin)

## Belangrijk voor Cloud86/Plesk

Deze applicatie is server-side en kan niet als statische FTP-site draaien.

- Subdomein: bijvoorbeeld `uren.jouwdomein.nl`
- Database: MySQL via Plesk/phpMyAdmin
- Runtime: Node.js 20+
- Uptime: 24/7 via PM2 + health/readiness checks
- Schaling: stel `WEB_CONCURRENCY` in voor PM2 cluster-instances

## Eenmalige installatie (aanbevolen)

1. Maak `.env` op basis van `.env.example` en vul productiegegevens in.
2. Voer daarna uit in de projectmap:

```bash
npm run install:cloud86
```

Dit script doet:
- dependencies installeren
- Prisma client genereren
- schema toepassen via migraties (`db:migrate:deploy`)
- build maken
- optionele bootstrap van eerste eigenaar
- PM2 start/reload (als PM2 beschikbaar is)

## Deploy bij nieuwe release

```bash
npm run deploy:cloud86
```

## 24/7 operatie-commando's

```bash
npm run start:pm2
npm run reload:pm2
npm run stop:pm2
```

Health endpoints:
- Liveness: `/api/health`
- Readiness: `/api/ready`

## Ops taken

- Dagelijkse backup:

```bash
npm run backup:db
```

- Wekelijkse retentie/pseudonimisering:

```bash
npm run retention:pseudonymize
```

- Maandrapportage:

```bash
npm run reports:monthly
```

## Documentatie

- Cloud86/Plesk installatie: `docs/cloud86-plesk-installatie.md`
- 24/7 operations runbook: `docs/ops-24-7.md`
- Architectuuroverzicht: `docs/architectuur.md`

## Lokale ontwikkeling

```bash
npm install
cp .env.example .env
npm run db:generate
npm run db:migrate:deploy
npm run dev
```
