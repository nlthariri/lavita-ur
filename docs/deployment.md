# Deployment — LaVita Laravel Backend op Cloud86/Plesk

Stap-voor-stap gids voor het uitrollen van de Laravel backend op Cloud86 shared hosting met Plesk.

> **Let op:** Dit document is specifiek voor de Laravel-herbouw in `laravel-rebuild/`.  
> Voor de Node.js-versie, zie [cloud86-plesk-installatie.md](cloud86-plesk-installatie.md).

---

## Inhoudsopgave

1. [Vereisten hosting](#1-vereisten-hosting)
2. [Database aanmaken in Plesk](#2-database-aanmaken-in-plesk)
3. [Bestanden uploaden](#3-bestanden-uploaden)
4. [Omgeving configureren (.env)](#4-omgeving-configureren-env)
5. [Afhankelijkheden installeren](#5-afhankelijkheden-installeren)
6. [Database migraties uitvoeren](#6-database-migraties-uitvoeren)
7. [Cache opwarmen](#7-cache-opwarmen)
8. [Document root instellen in Plesk](#8-document-root-instellen-in-plesk)
9. [Bestandsrechten](#9-bestandsrechten)
10. [Scheduler (crontab) instellen](#10-scheduler-crontab-instellen)
11. [Queue worker instellen](#11-queue-worker-instellen)
12. [E-mail (SMTP) configureren](#12-e-mail-smtp-configureren)
13. [SSL/HTTPS](#13-sslhttps)
14. [Validatie na deployment](#14-validatie-na-deployment)
15. [Herdeployment (updates)](#15-herdeployment-updates)
16. [Rollback](#16-rollback)
17. [Beveiliging checklist](#17-beveiliging-checklist)

---

## 1. Vereisten hosting

| Vereiste | Minimaal |
|---------|----------|
| PHP | 8.3 |
| MySQL | 8.0 |
| PHP-extensies | `pdo_mysql`, `mbstring`, `dom`, `curl`, `intl`, `gd`, `zip`, `opcache` |
| Composer | 2.x (beschikbaar via SSH) |
| SSH-toegang | Vereist voor `composer install` en `php artisan` |
| Schrijfrechten | `storage/` en `bootstrap/cache/` |
| Crontab | Vereist voor scheduler |

> Cloud86 Plesk-pakketten met PHP 8.3 zijn beschikbaar. Stel de PHP-versie in via **Plesk → Domeinen → PHP-instellingen**.

---

## 2. Database aanmaken in Plesk

1. Ga naar **Plesk → Databases → Database toevoegen**.
2. Naam: `lavita_ur` (of naar keuze).
3. Maak een databasegebruiker aan: `lavita_user` met een sterk wachtwoord.
4. Noteer: host (meestal `127.0.0.1` of `localhost`), poort (`3306`), databasenaam, gebruiker, wachtwoord.
5. Controleer toegang via phpMyAdmin.

---

## 3. Bestanden uploaden

### Optie A — Git (aanbevolen)

```bash
# SSH naar de server
ssh gebruiker@uw-server.nl

# Kloon de repository in de home-map
git clone https://github.com/uw-org/lavita-ur.git /home/gebruiker/lavita-ur

# Of, als de repo al aanwezig is:
cd /home/gebruiker/lavita-ur
git pull origin main
```

### Optie B — SFTP/FTP

Upload de volledige inhoud van `laravel-rebuild/` naar de gewenste map op de server (bijv. `/home/gebruiker/lavita-laravel/`).

> **Belangrijk:** Upload de `vendor/`-map **niet** via FTP. Installeer afhankelijkheden via `composer install` op de server (zie stap 5).

---

## 4. Omgeving configureren (.env)

```bash
cd /home/gebruiker/lavita-ur/laravel-rebuild
cp .env.example .env
nano .env
```

Vul minimaal de volgende waarden in:

```dotenv
APP_NAME="LaVita Urenregistratie"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://uren.uw-domein.nl

APP_LOCALE=nl
APP_FALLBACK_LOCALE=nl

LOG_CHANNEL=stack
LOG_LEVEL=warning

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lavita_ur
DB_USERNAME=lavita_user
DB_PASSWORD=uw-sterk-wachtwoord

# Sessies / cache / queue (database-driver, geen Redis nodig)
SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database

# E-mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.cloud86.nl
MAIL_PORT=587
MAIL_USERNAME=noreply@uw-domein.nl
MAIL_PASSWORD=uw-smtp-wachtwoord
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@uw-domein.nl
MAIL_FROM_NAME="LaVita Urenregistratie"

BCRYPT_ROUNDS=12
```

Genereer de app-sleutel:

```bash
php artisan key:generate
```

---

## 5. Afhankelijkheden installeren

```bash
cd /home/gebruiker/lavita-ur/laravel-rebuild

# PHP-afhankelijkheden (zonder dev-packages, geoptimaliseerd voor productie)
composer install --no-dev --optimize-autoloader --no-interaction

# Controleer of er geen fouten zijn
php artisan --version
# Verwacht: Laravel Framework 13.x
```

> Als `composer` niet beschikbaar is via SSH, download het via:
> `curl -sS https://getcomposer.org/installer | php`
> Gebruik dan `php composer.phar install ...`

---

## 6. Database migraties uitvoeren

```bash
# Eerste keer (fresh install)
php artisan migrate --force

# Verificatie: controleer of alle tabellen aanwezig zijn
php artisan migrate:status
```

Verwachte tabellen na migratie (27+):

```
users, auth_sessions, mfa_secrets, mfa_recovery_codes,
organizations, teams, work_entries, atw_violations,
objections, email_outbox, email_outbox_events, audit_events,
email_templates, monthly_report_runs, system_job_runs,
cache, jobs, ...
```

---

## 7. Cache opwarmen

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

> Na elke code-wijziging: voer `php artisan config:clear && php artisan config:cache` opnieuw uit.

---

## 8. Document root instellen in Plesk

De Laravel `public/`-map moet de document root zijn van het domein/subdomein.

1. Ga naar **Plesk → Domeinen → uw-domein → Hosting-instellingen**.
2. Stel de **Document root** in op: `/home/gebruiker/lavita-ur/laravel-rebuild/public`
3. Sla op en controleer of `https://uren.uw-domein.nl/api/health` bereikbaar is.

### .htaccess (Apache)

De `public/.htaccess` is al aanwezig in Laravel en regelt URL-rewriting. Zorg dat `mod_rewrite` is ingeschakeld in Plesk.

Als mod_rewrite niet werkt, voeg toe aan Plesk **Apache & nginx-instellingen**:

```apache
<Directory /home/gebruiker/lavita-ur/laravel-rebuild/public>
    AllowOverride All
    Require all granted
</Directory>
```

---

## 9. Bestandsrechten

```bash
cd /home/gebruiker/lavita-ur/laravel-rebuild

# storage en bootstrap/cache schrijfbaar maken
chmod -R 775 storage bootstrap/cache

# eigenaar instellen (vervang www-data door de webserver-gebruiker van Cloud86)
chown -R $USER:www-data storage bootstrap/cache
```

> Op Cloud86 is de webserver-gebruiker vaak `www-data` of de gebruikersnaam van het hosting-account. Vraag dit na bij Cloud86-support als je het niet weet.

---

## 10. Scheduler (crontab) instellen

Voeg toe via **Plesk → Geplande taken** of via SSH:

```bash
crontab -e
```

Voeg toe:

```
* * * * * cd /home/gebruiker/lavita-ur/laravel-rebuild && php artisan schedule:run >> /dev/null 2>&1
```

Controleer of de scheduler draait:

```bash
php artisan schedule:list
```

---

## 11. Queue worker instellen

De queue verwerkt e-mail dispatches en achtergrondtaken.

### Optie A — Plesk geplande taak (elke minuut, eenvoudigste optie)

Voeg een tweede geplande taak toe in Plesk:

```
* * * * * cd /home/gebruiker/lavita-ur/laravel-rebuild && php artisan queue:work --stop-when-empty --tries=3 --timeout=90 >> /dev/null 2>&1
```

> `--stop-when-empty` zorgt dat het process stopt als de queue leeg is, zodat elke minuut een nieuw process start.

### Optie B — Supervisor (als beschikbaar)

```ini
[program:lavita-queue]
command=php /home/gebruiker/lavita-ur/laravel-rebuild/artisan queue:work --tries=3 --backoff=60 --timeout=90
directory=/home/gebruiker/lavita-ur/laravel-rebuild
autostart=true
autorestart=true
user=gebruiker
redirect_stderr=true
stdout_logfile=/home/gebruiker/lavita-ur/laravel-rebuild/storage/logs/queue.log
```

---

## 12. E-mail (SMTP) configureren

Gebruik de SMTP-instellingen van uw e-mailprovider. Voorbeelden:

### Cloud86 / Yourhosting SMTP

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=mail.uw-domein.nl
MAIL_PORT=587
MAIL_USERNAME=noreply@uw-domein.nl
MAIL_PASSWORD=uw-e-mailwachtwoord
MAIL_ENCRYPTION=tls
```

### Mailgun / Postmark / SendGrid

```dotenv
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=uw-domein.nl
MAILGUN_SECRET=uw-mailgun-key
MAILGUN_ENDPOINT=api.eu.mailgun.net
```

Test de e-mailconfiguratie:

```bash
php artisan tinker
# In tinker:
Mail::raw('Test e-mail LaVita', fn($m) => $m->to('uw-email@domein.nl')->subject('Test'));
```

---

## 13. SSL/HTTPS

1. In **Plesk → SSL/TLS-certificaten**: activeer Let's Encrypt voor het subdomein.
2. Stel in `.env`: `APP_URL=https://uren.uw-domein.nl`
3. Herstart de cache: `php artisan config:cache`

Controleer HTTPS-redirect:

```bash
curl -I http://uren.uw-domein.nl/api/health
# Verwacht: 301 Redirect naar https://
```

---

## 14. Validatie na deployment

Voer de volgende checks uit na elke deployment:

```bash
# 1. App-versie
php artisan --version

# 2. Migratiestatus
php artisan migrate:status

# 3. Health endpoint (vanuit server)
curl -s http://localhost/api/health | python3 -m json.tool

# 4. Scheduler
php artisan schedule:list

# 5. Queue status
php artisan queue:monitor

# 6. Back-up testen (optioneel)
../scripts/verify-backup.sh
```

Verwachte response van `/api/health`:

```json
{
  "status": "ok",
  "service": "lavita-ur-laravel-rebuild",
  "checks": {
    "app": "ok",
    "database": "ok"
  }
}
```

---

## 15. Herdeployment (updates)

Bij elke update voer je het volgende uit:

```bash
cd /home/gebruiker/lavita-ur/laravel-rebuild

# 1. Maintenance mode (weigert tijdelijk requests)
php artisan down --retry=60

# 2. Code ophalen
git pull origin main

# 3. Afhankelijkheden bijwerken
composer install --no-dev --optimize-autoloader --no-interaction

# 4. Migraties uitvoeren
php artisan migrate --force

# 5. Caches wissen en herbouwen
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Maintenance mode uitzetten
php artisan up
```

---

## 16. Rollback

Als een deployment problemen veroorzaakt:

```bash
# 1. Maintenance mode aan
php artisan down

# 2. Terug naar vorige versie
git revert HEAD  # of: git checkout <vorige-commit-hash>

# 3. Eventueel migraties terugdraaien (voorzichtig!)
php artisan migrate:rollback --step=1

# 4. Caches herbouwen
php artisan config:cache && php artisan route:cache

# 5. Maintenance mode uit
php artisan up
```

---

## 17. Beveiliging checklist

Controleer voor live-gang:

- [ ] `APP_DEBUG=false` in `.env`
- [ ] `APP_ENV=production` in `.env`
- [ ] `SESSION_ENCRYPT=true` in `.env`
- [ ] `APP_KEY` is gegenereerd en uniek
- [ ] `MAIL_MAILER=smtp` (niet `log`)
- [ ] `MAIL_FROM_ADDRESS` is een geldig e-mailadres van uw domein
- [ ] HTTPS actief en HTTP wordt doorgestuurd naar HTTPS
- [ ] `storage/` en `bootstrap/cache/` zijn schrijfbaar maar niet publiek toegankelijk
- [ ] `vendor/`, `.env` en `database/` zijn **niet** bereikbaar via de browser
- [ ] Crontab is ingesteld voor de scheduler
- [ ] Queue worker draait
- [ ] Nachtelijke back-up (backup-mysql.sh) is ingesteld
- [ ] Minimaal één owner-account aangemaakt met MFA ingesteld
- [ ] `php artisan test` slaagt lokaal voor deployment

---

*Versie: 16 mei 2026*
