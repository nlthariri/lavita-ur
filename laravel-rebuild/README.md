# LaVita Urenregistratie — Laravel Backend

API-backend voor het LaVita urenregistratieplatform. Gebouwd op **Laravel 13 / PHP 8.3**.

Functionaliteiten: authenticatie + MFA (TOTP), ATW-validatie, bezwaarbeheer, e-mail outbox met audit-keten, PDF/Excel-rapporten, 7-jaar bewaarplicht en governance-evidence.

---

## Inhoudsopgave

1. [Vereisten](#1-vereisten)
2. [Lokale installatie](#2-lokale-installatie)
3. [Omgevingsvariabelen (.env)](#3-omgevingsvariabelen-env)
4. [Testen](#4-testen)
5. [API-overzicht](#5-api-overzicht)
6. [Rollen en rechten](#6-rollen-en-rechten)
7. [MFA-flow](#7-mfa-flow)
8. [ATW-signalen](#8-atw-signalen)
9. [Artisan-commando\'s en scheduler](#9-artisan-commandos-en-scheduler)
10. [Deployment (productie)](#10-deployment-productie)
11. [Scripts (ops)](#11-scripts-ops)
12. [Governance en documentatie](#12-governance-en-documentatie)

---

## 1. Vereisten

| Tool | Minimale versie |
|------|----------------|
| PHP | 8.3 |
| Composer | 2.x |
| Node.js | 20.x |
| MySQL | 8.0 (productie) |
| SQLite | 3.x (testen) |

PHP-extensies (productie): `pdo_mysql`, `mbstring`, `dom`, `curl`, `intl`, `gd`, `zip`, `opcache`

---

## 2. Lokale installatie

```bash
cd laravel-rebuild

composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

Start de development server:

```bash
php artisan serve
# Beschikbaar op http://localhost:8000
```

---

## 3. Omgevingsvariabelen (.env)

### Minimale productie-configuratie

```dotenv
APP_NAME="LaVita Urenregistratie"
APP_ENV=production
APP_KEY=base64:...          # php artisan key:generate
APP_DEBUG=false             # NOOIT true in productie
APP_URL=https://uw-domein.nl

APP_LOCALE=nl
APP_FALLBACK_LOCALE=nl

LOG_CHANNEL=stack
LOG_LEVEL=warning

# Database (MySQL)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lavita_ur
DB_USERNAME=lavita_user
DB_PASSWORD=sterk-wachtwoord

# Sessies / cache / queue via database (shared hosting — geen Redis vereist)
SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database

# E-mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.uw-provider.nl
MAIL_PORT=587
MAIL_USERNAME=noreply@uw-domein.nl
MAIL_PASSWORD=smtp-wachtwoord
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@uw-domein.nl
MAIL_FROM_NAME="LaVita Urenregistratie"

BCRYPT_ROUNDS=12
```

### Kritieke verschillen lokaal vs. productie

| Variabele | Lokaal | Productie |
|-----------|--------|-----------|
| `APP_DEBUG` | `true` | **`false`** |
| `APP_ENV` | `local` | **`production`** |
| `DB_CONNECTION` | `sqlite` | **`mysql`** |
| `MAIL_MAILER` | `log` | **`smtp`** |
| `SESSION_ENCRYPT` | `false` | **`true`** |
| `BCRYPT_ROUNDS` | `4` | `12` |

> **Waarschuwing:** `APP_DEBUG=true` in productie lekt stack traces naar API-responses.

---

## 4. Testen

Alle tests draaien op SQLite in-memory — geen externe services nodig.

```bash
# Alle tests
php artisan test

# Stop bij eerste fout
php artisan test --stop-on-failure

# Per suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Per testbestand
php artisan test --filter=AuthModuleContractTest
php artisan test --filter=AtwModuleContractTest
php artisan test --filter=EmailFlowsModuleContractTest
php artisan test --filter=WorkEntriesModuleContractTest
php artisan test --filter=ObjectionsModuleContractTest
php artisan test --filter=ReportsModuleContractTest
php artisan test --filter=SystemHealthEndpointsTest
php artisan test --filter=PasswordResetAuditModuleContractTest
```

**Baseline (16 mei 2026):** 140 tests, 479 assertions, 100% PASS

### Test-omgeving (phpunit.xml)

| Variabele | Waarde |
|-----------|--------|
| `DB_CONNECTION` | `sqlite` |
| `DB_DATABASE` | `:memory:` |
| `CACHE_STORE` | `array` |
| `QUEUE_CONNECTION` | `sync` |
| `MAIL_MAILER` | `array` |
| `BCRYPT_ROUNDS` | `4` |

---

## 5. API-overzicht

Alle endpoints beginnen met `/api/`. Zie [docs/api-referentie.md](../docs/api-referentie.md) voor volledige request/response-voorbeelden.

### Publieke routes (geen Bearer token)

| Method | Endpoint | Rate limit | Omschrijving |
|--------|----------|-----------|--------------|
| `GET` | `/api/health` | — | Liveness check (DB-ping) |
| `GET` | `/api/ready` | — | Readiness check |
| `POST` | `/api/auth/login` | 20/min per IP | Inloggen → `session_token` |
| `POST` | `/api/auth/mfa/verify` | 5/min per user+IP | TOTP of recovery-code verifiëren |
| `POST` | `/api/auth/password-reset/request` | 20/min per IP | Wachtwoord-reset aanvragen |
| `POST` | `/api/auth/password-reset/confirm` | 20/min per IP | Nieuw wachtwoord instellen |

### Beveiligde routes (Bearer token vereist)

Header: `Authorization: Bearer <session_token>`

| Method | Endpoint | Rol | Omschrijving |
|--------|----------|-----|--------------|
| `POST` | `/api/auth/logout` | alle | Sessie intrekken |
| `POST` | `/api/auth/mfa/setup` | alle | MFA instellen (TOTP + 8 recovery codes) |
| `POST` | `/api/auth/accounts` | owner/manager | Account aanmaken (vereist re-auth) |
| `POST` | `/api/internal/work-entries` | owner/manager | Uurregistratie aanmaken |
| `GET` | `/api/internal/work-entries` | owner/manager | Registraties ophalen |
| `POST` | `/api/internal/objections` | employee | Bezwaar indienen |
| `POST` | `/api/internal/objections/{id}/review` | owner/manager | Bezwaar beoordelen |
| `GET` | `/api/internal/objections` | owner/manager | Bezwaren ophalen |
| `POST` | `/api/internal/work-entries/validate-atw` | owner/manager | ATW-check uitvoeren |
| `GET` | `/api/internal/atw/signals` | owner/manager | ATW-signalen ophalen |
| `GET` | `/api/internal/reports/work-entries/pdf` | owner/manager | PDF-rapport |
| `GET` | `/api/internal/reports/work-entries/excel` | owner/manager | Excel-rapport |
| `POST` | `/api/internal/email/dispatch` | owner | E-mail handmatig dispatchen |
| `PUT` | `/api/internal/email/templates/{type}` | owner | E-mailtemplate bijwerken |
| `GET` | `/api/internal/email/templates/{type}` | owner/manager | E-mailtemplate ophalen |
| `POST` | `/api/internal/jobs/monthly-report` | owner | Maandrapport-job triggeren |
| `GET` | `/api/internal/audit/export` | owner | Auditlog exporteren |

---

## 6. Rollen en rechten

| Rol | MFA verplicht | Accounts aanmaken | Uren registreren | Bezwaar indienen |
|-----|:---:|:---:|:---:|:---:|
| `owner` | Ja | Ja | Ja | — |
| `manager` | Ja | Ja (geen owner) | Ja | — |
| `employee` | Nee | — | — | Ja |
| `boekhouder` | Nee | — | — | — |

**MFA rotatie-policy:** Het MFA-secret verloopt na 180 dagen. Roteer via `POST /api/auth/mfa/setup`.
**Re-auth:** Account-aanmaak vereist altijd `password_confirmation` van de handelende gebruiker.
**Multi-tenant:** Gebruikers zien nooit data van andere organisaties.

---

## 7. MFA-flow

```
1. POST /api/auth/login
   → { session_token, mfa_required: true }

2. POST /api/auth/mfa/verify  { user_id, code }
   code = 6-cijferige TOTP  of  10-teken recovery-code
   → sessie wordt geactiveerd voor interne routes

3. POST /api/auth/mfa/setup  (eerste keer of rotatie)
   → { provisioning_secret, recovery_codes: [ 8 codes ] }
   Sla de recovery codes éénmalig op — ze worden niet opnieuw getoond.
```

---

## 8. ATW-signalen

| Signaaltype | Drempel | Ernst |
|-------------|---------|-------|
| `DAILY_LIMIT` | Netto ≥ 720 min (12 uur/dag) | critical |
| `WEEKLY_WARNING` | Weektotaal ≥ 2880 min (48 uur) | warning |
| `WEEKLY_LIMIT` | Weektotaal ≥ 3600 min (60 uur) | critical |
| `SIXTEEN_WEEK_AVERAGE` | 16-weken gemiddelde > drempel | warning/critical |
| `REST_PERIOD` | Rustperiode < 660 min (11 uur) | critical |

**Pauze-verplichting:** Dienst > 5,5 uur (330 min) bruto → minimaal 60 minuten pauze verplicht.

---

## 9. Artisan-commando's en scheduler

### Handmatige commando's

```bash
php artisan retention:run
php artisan reminder:pending-input --days=1
php artisan integrity:email-evidence --fail-on-corruption
php artisan integrity:evidence-privileges:verify --fail-on-violation
php artisan integrity:email-evidence:escalations:report --fail-on-open
php artisan integrity:email-evidence:acknowledge --incident-id=<id>
php artisan integrity:email-evidence:resolve --incident-id=<id>
```

### Scheduler instellen (productie-crontab)

```bash
* * * * * cd /pad/naar/laravel-rebuild && php artisan schedule:run >> /dev/null 2>&1
```

| Tijd | Commando |
|------|----------|
| 02:10 | `retention:run` |
| 02:20 | `reminder:pending-input` |
| 02:40 | `integrity:email-evidence` |
| 02:50 | `integrity:evidence-privileges:verify` |
| 03:00 | `integrity:email-evidence:escalations:report` |

### Queue worker

```bash
php artisan queue:work --queue=default --tries=3 --backoff=60 --timeout=90
```

---

## 10. Deployment (productie)

Zie [docs/deployment.md](../docs/deployment.md) voor de volledige stap-voor-stap-gids.

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

**Controleer altijd:**
- `APP_DEBUG=false`
- `SESSION_ENCRYPT=true`
- `MAIL_MAILER=smtp` (geen `log`)
- `storage/` en `bootstrap/cache/` schrijfbaar voor webserver
- Crontab ingesteld voor scheduler
- `queue:work` draait als achtergrondproces

---

## 11. Scripts (ops)

| Script | Gebruik |
|--------|---------|
| `../scripts/backup-mysql.sh` | Nachtelijke MySQL-dump + gzip + optioneel GPG-encryptie |
| `../scripts/verify-backup.sh [bestand.sql.gz]` | Hersteltest: importeer in testDB + sanity-checks |
| `../scripts/load-test.sh [url] [concurrency] [requests]` | Performance baseline (p99 ≤ 500ms) |

---

## 12. Governance en documentatie

| Document | Inhoud |
|----------|--------|
| [docs/architectuur.md](../docs/architectuur.md) | Systeemarchitectuur en ontwerpbeslissingen |
| [docs/api-referentie.md](../docs/api-referentie.md) | Volledige API-referentie met voorbeelden |
| [docs/deployment.md](../docs/deployment.md) | Deployment-gids Cloud86/Plesk |
| [docs/lokale-ontwikkeling.md](../docs/lokale-ontwikkeling.md) | Uitgebreide lokale ontwikkelgids |
| [docs/ops-24-7.md](../docs/ops-24-7.md) | Operationeel runbook (24/7) |
| [docs/audit-rapport-11mei2026.md](../docs/audit-rapport-11mei2026.md) | Auditrapport bevindingen |
| [docs/governance/](../docs/governance/) | Uitvoeringsdossiers per iteratie |

---

*Laatste update: 16 mei 2026 — iteratie 27*
