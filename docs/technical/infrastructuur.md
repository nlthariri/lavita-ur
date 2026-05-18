# Infrastructuur — LaVita Urenregistratie

## Overzicht

LaVita Urenregistratie draait op een Cloud86/Plesk-omgeving met de volgende componenten:

- **Webserver**: Nginx (via Plesk) met PHP 8.3 FPM
- **Database**: MySQL 8.0+ met full-disk encryption (LUKS)
- **Queue**: Laravel scheduler + database queue driver
- **Mail**: SMTP-relay via TLS 1.3
- **Applicatie**: Laravel 13, PHP 8.3, Livewire 3, Tailwind CSS 3

---

## Deployment Topology

```
┌─────────────────────────────────────────────────────────────────┐
│                     Cloud86 / Plesk Host                         │
│                                                                 │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────────┐  │
│  │   Nginx      │    │  PHP 8.3 FPM │    │   MySQL 8.0+     │  │
│  │  (reverse    │───▶│  (Laravel 13) │───▶│  (LUKS-volume)   │  │
│  │   proxy)     │    │              │    │                  │  │
│  │  TLS 1.3     │    │  Livewire 3  │    │  AES-256 at-rest │  │
│  └──────────────┘    └──────────────┘    └──────────────────┘  │
│         │                    │                                   │
│         │                    ▼                                   │
│         │            ┌──────────────┐                           │
│         │            │  Scheduler   │                           │
│         │            │  (cron)      │                           │
│         │            │  - backup    │                           │
│         │            │  - retentie  │                           │
│         │            │  - reminders │                           │
│         │            └──────────────┘                           │
│         │                    │                                   │
│         ▼                    ▼                                   │
│  ┌──────────────────────────────────────┐                      │
│  │         SMTP-relay (TLS 1.3)         │                      │
│  └──────────────────────────────────────┘                      │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────┐
│   Eindgebruiker │
│   (Browser)     │
│   HTTPS only    │
└─────────────────┘
```

### Componenten

| Component | Versie | Rol |
|-----------|--------|-----|
| Nginx | via Plesk | Reverse proxy, TLS-terminatie, statische assets |
| PHP-FPM | 8.3 | Applicatieruntime |
| Laravel | 13 | Applicatieframework |
| MySQL | 8.0+ | Relationele database |
| Plesk | Laatste versie | Serverbeheer, certificaten, cron |
| SMTP-relay | Extern | E-mailverzending (TLS 1.3) |

### Omgevingen

| Omgeving | Doel | URL |
|----------|------|-----|
| Productie | Live applicatie | `https://uren.{domein}.nl` |
| Staging | Acceptatietests | `https://staging.uren.{domein}.nl` |
| Lokaal | Ontwikkeling | `http://localhost:8000` |

---

## TLS 1.3 Configuratie

### Cipher Suite (Plesk/Nginx)

Configureer in Plesk onder **Websites & Domains → SSL/TLS-certificaten → Geavanceerde instellingen** of via `/etc/nginx/conf.d/ssl.conf`:

```nginx
ssl_protocols TLSv1.3;
ssl_ciphers TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256;
ssl_prefer_server_ciphers off;
ssl_session_timeout 1d;
ssl_session_cache shared:SSL:10m;
ssl_session_tickets off;
```

### OCSP Stapling

Activeer OCSP stapling voor snellere TLS-handshake en privacy:

```nginx
ssl_stapling on;
ssl_stapling_verify on;
resolver 1.1.1.1 8.8.8.8 valid=300s;
resolver_timeout 5s;
```

Verificatie:

```bash
openssl s_client -connect lavita.nl:443 -status 2>/dev/null | grep -A 5 "OCSP Response"
```

### HSTS Header

Wordt automatisch gezet door de `HstsMiddleware` in Laravel:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
```

Na minimaal 6 maanden foutloos draaien kan het domein worden aangemeld bij [hstspreload.org](https://hstspreload.org).

---

## HTTP → HTTPS Redirect

### Applicatieniveau (Laravel)

De `RedirectIfNotSecure`-middleware stuurt in productie alle HTTP-requests door naar HTTPS met een **308 Permanent Redirect** (behoudt HTTP-methode).

### Plesk/Nginx-niveau (aanvullend)

Configureer in Plesk onder **Websites & Domains → Hosting-instellingen**:
- Vink aan: "Permanente SEO-veilige 301-redirect van HTTP naar HTTPS"

Of voeg handmatig toe aan de Nginx-configuratie:

```nginx
server {
    listen 80;
    server_name lavita.nl www.lavita.nl;
    return 308 https://$host$request_uri;
}
```

---

## MySQL Data-directory op LUKS

### Doel

Alle persoonsgegevens (inclusief versleutelde kolommen) worden beschermd tegen fysieke toegang tot de schijf via full-disk encryption.

### Stappen Cloud86/Plesk

1. **Volume aanmaken** (indien niet standaard versleuteld):
   ```bash
   # Maak een LUKS-versleuteld volume aan
   cryptsetup luksFormat /dev/sdX
   cryptsetup luksOpen /dev/sdX mysql_data
   mkfs.ext4 /dev/mapper/mysql_data
   ```

2. **Mount op MySQL data-directory**:
   ```bash
   mount /dev/mapper/mysql_data /var/lib/mysql
   chown mysql:mysql /var/lib/mysql
   chmod 750 /var/lib/mysql
   ```

3. **Automatisch unlocken bij boot** (via `/etc/crypttab` met keyfile):
   ```
   mysql_data /dev/sdX /root/.luks-keyfile luks
   ```

4. **fstab-entry**:
   ```
   /dev/mapper/mysql_data /var/lib/mysql ext4 defaults 0 2
   ```

5. **MySQL herstarten**:
   ```bash
   systemctl restart mysql
   ```

### Verificatie

```bash
# Controleer dat het volume versleuteld is
cryptsetup status mysql_data

# Controleer dat MySQL op het juiste pad draait
mysql -e "SHOW VARIABLES LIKE 'datadir';"
```

---

## Applicatie-encryptie (at-rest)

### Laravel Encrypted Casts

De volgende kolommen in `users` zijn versleuteld via Laravel's `encrypted` cast (AES-256-CBC):

| Kolom | Doel |
|-------|------|
| `full_name` | Volledige naam medewerker |
| `email` | E-mailadres (lookup via `email_index_hash`) |
| `phone` | Telefoonnummer (optioneel) |

### Email Lookup via SHA-256 Index Hash

Omdat de `email`-kolom versleuteld is, wordt een deterministische SHA-256 hash bewaard in `email_index_hash` voor login- en account-lookups:

```php
// Automatisch berekend bij opslaan (User model saving event)
$user->email_index_hash = hash('sha256', strtolower($user->email));
```

### Sleutelrotatie

- `APP_KEY`: Primaire encryptiesleutel (AES-256-CBC)
- `APP_PREVIOUS_KEYS`: Komma-gescheiden lijst van vorige sleutels voor decryptie van bestaande data
- Rotatie: elke 12 maanden via `php artisan key:rotate`
- Vorige sleutels 90 dagen bewaren in `APP_PREVIOUS_KEYS`

---

## Backup-configuratie

Zie ook: `config/backup.php` (spatie/laravel-backup)

- **Schema**: dagelijks om 02:00 (Europe/Amsterdam)
- **Inhoud**: alle MySQL-tabellen + `storage/app/private`
- **Encryptie**: AES-256-CBC met `BACKUP_ARCHIVE_PASSWORD` (los van `APP_KEY`)
- **Retentie**: 30 dagen
- **Integriteitscheck**: dagelijks om 03:00 via `php artisan backup:verify`
- **Alerting**: bij falen → audit-event + alert-mail naar owner

---

## Monitoring

- **Uptime**: Plesk Health Monitor + externe ping (optioneel UptimeRobot)
- **Logs**: `storage/logs/laravel.log` (dagelijks geroteerd)
- **Queue**: database driver, monitored via `php artisan queue:monitor`
- **Scheduler**: `php artisan schedule:list` voor overzicht van alle geplande taken

---

## Restore-procedure (RTO 4 uur)

### Overzicht

Deze procedure beschrijft het volledig herstellen van de LaVita Urenregistratie-applicatie vanuit een versleutelde backup naar een nieuw of bestaand Plesk-environment. De Recovery Time Objective (RTO) is **4 uur**.

| Stap | Actie | Geschatte duur |
|------|-------|----------------|
| 1 | Omgeving voorbereiden | 30 min |
| 2 | Backup ophalen en decrypten | 15 min |
| 3 | Database herstellen | 30 min |
| 4 | Bestanden herstellen | 15 min |
| 5 | Applicatie configureren | 30 min |
| 6 | Verificatie en smoke-tests | 30 min |
| 7 | DNS/traffic omschakelen | 30 min |
| 8 | Post-restore controles | 30 min |
| **Totaal** | | **~3,5 uur** (buffer 30 min) |

### Stap 1: Omgeving voorbereiden (30 min)

1. **Nieuw Plesk-domein aanmaken** (of bestaand domein gebruiken):
   ```bash
   # Controleer PHP 8.3, MySQL 8.0+, Nginx beschikbaar
   php -v    # Moet 8.3.x tonen
   mysql --version  # Moet 8.0+ tonen
   ```

2. **Vereiste PHP-extensies controleren**:
   ```bash
   php -m | grep -E "(pdo_mysql|mbstring|openssl|zip|gd|bcmath|sodium)"
   ```

3. **LUKS-volume activeren** (indien van toepassing):
   ```bash
   cryptsetup luksOpen /dev/sdX mysql_data
   mount /dev/mapper/mysql_data /var/lib/mysql
   ```

4. **Lege database aanmaken**:
   ```bash
   mysql -u root -p -e "CREATE DATABASE lavita_ur CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p -e "CREATE USER 'lavita'@'localhost' IDENTIFIED BY '<STERK_WACHTWOORD>';"
   mysql -u root -p -e "GRANT ALL PRIVILEGES ON lavita_ur.* TO 'lavita'@'localhost';"
   mysql -u root -p -e "FLUSH PRIVILEGES;"
   ```

### Stap 2: Backup ophalen en decrypten (15 min)

1. **Meest recente backup lokaliseren**:
   ```bash
   # Backups staan op de backup-disk (standaard: storage/app/{app-name}/)
   ls -la /pad/naar/backup-opslag/lavita-urenregistratie/
   # Selecteer het meest recente .zip bestand
   BACKUP_FILE="/pad/naar/backup-opslag/lavita-urenregistratie/backup-2026-05-16-02-00-00.zip"
   ```

2. **Backup decrypten en uitpakken**:
   ```bash
   # Het archief is versleuteld met BACKUP_ARCHIVE_PASSWORD
   mkdir -p /tmp/lavita-restore
   unzip -P "${BACKUP_ARCHIVE_PASSWORD}" "$BACKUP_FILE" -d /tmp/lavita-restore/
   ```

3. **Integriteit verifiëren**:
   ```bash
   # Controleer SHA-256 hash (indien manifest beschikbaar)
   sha256sum "$BACKUP_FILE"
   cat "${BACKUP_FILE}.sha256"
   ```

### Stap 3: Database herstellen (30 min)

1. **Database-dump importeren**:
   ```bash
   # De dump staat in de uitgepakte backup als db-dumps/mysql-lavita_ur.sql.gz
   cd /tmp/lavita-restore
   gunzip -c db-dumps/mysql-lavita_ur.sql.gz | mysql -u lavita -p lavita_ur
   ```

2. **Controleer tabelintegriteit**:
   ```bash
   mysql -u lavita -p lavita_ur -e "
     SELECT COUNT(*) AS users FROM users;
     SELECT COUNT(*) AS work_entries FROM work_entries;
     SELECT COUNT(*) AS audit_events FROM audit_events;
   "
   ```

3. **Controleer dat encryptie-kolommen leesbaar zijn** (vereist correcte `APP_KEY`):
   ```bash
   # Dit wordt getest in stap 6 via de applicatie
   ```

### Stap 4: Bestanden herstellen (15 min)

1. **Applicatiecode deployen**:
   ```bash
   cd /var/www/vhosts/lavita.nl/httpdocs
   git clone <repository-url> .
   # Of kopieer vanuit bestaande deployment
   ```

2. **Storage-bestanden herstellen**:
   ```bash
   # Kopieer storage/app/private uit de backup
   cp -r /tmp/lavita-restore/storage/app/private/ storage/app/private/
   chown -R www-data:www-data storage/
   chmod -R 775 storage/
   ```

3. **Dependencies installeren**:
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   ```

### Stap 5: Applicatie configureren (30 min)

1. **Environment-bestand instellen**:
   ```bash
   cp .env.example .env
   # Vul de volgende waarden in:
   # - APP_KEY (KRITIEK: moet identiek zijn aan de originele sleutel!)
   # - APP_PREVIOUS_KEYS (indien key rotation actief was)
   # - DB_* credentials
   # - MAIL_* configuratie
   # - BACKUP_ARCHIVE_PASSWORD
   ```

   > ⚠️ **KRITIEK**: De `APP_KEY` MOET identiek zijn aan de originele sleutel.
   > Zonder de juiste sleutel zijn versleutelde kolommen (email, full_name, phone) onleesbaar.
   > Bewaar de APP_KEY altijd op een veilige, externe locatie (password manager, HSM).

2. **Cache en configuratie opbouwen**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```

3. **Migraties controleren** (niet uitvoeren als DB al hersteld is):
   ```bash
   php artisan migrate:status
   # Alle migraties moeten "Ran" tonen
   ```

4. **Queue-worker starten**:
   ```bash
   php artisan queue:restart
   # Configureer supervisor of systemd voor permanente queue-worker
   ```

5. **TLS-certificaat configureren** in Plesk:
   - Let's Encrypt certificaat aanvragen via Plesk SSL/TLS
   - Controleer dat TLS 1.3 actief is (zie sectie TLS 1.3 Configuratie)

### Stap 6: Verificatie en smoke-tests (30 min)

1. **Applicatie-health-check**:
   ```bash
   curl -s https://lavita.nl/up
   # Moet HTTP 200 retourneren
   ```

2. **Database-connectiviteit en encryptie**:
   ```bash
   php artisan tinker --execute="
     \$user = \App\Models\User::first();
     echo 'Email leesbaar: ' . (!empty(\$user->email) ? 'JA' : 'NEE') . PHP_EOL;
     echo 'Full name leesbaar: ' . (!empty(\$user->full_name) ? 'JA' : 'NEE') . PHP_EOL;
   "
   ```

3. **API-endpoint testen**:
   ```bash
   # Test een beschermd endpoint (verwacht 401 zonder token)
   curl -s -o /dev/null -w "%{http_code}" https://lavita.nl/api/internal/work-entries
   # Moet 401 retourneren
   ```

4. **Scheduler controleren**:
   ```bash
   php artisan schedule:list
   # Controleer dat alle jobs zichtbaar zijn
   ```

5. **Backup-integriteitscheck draaien**:
   ```bash
   php artisan backup:verify
   ```

6. **Email-verzending testen**:
   ```bash
   php artisan tinker --execute="
     \Illuminate\Support\Facades\Mail::raw('Restore test', function(\$m) {
       \$m->to('admin@lavita.nl')->subject('Restore verificatie');
     });
   "
   ```

### Stap 7: DNS/traffic omschakelen (30 min)

1. **DNS bijwerken** (indien nieuw IP-adres):
   - A-record wijzigen naar nieuw server-IP
   - TTL vooraf verlagen naar 300s (5 min) indien mogelijk

2. **HSTS-preload controleren**:
   ```bash
   curl -sI https://lavita.nl | grep -i strict-transport
   ```

3. **Oude omgeving uitschakelen** (indien van toepassing):
   - Maintenance mode op oude server
   - Wacht tot DNS-propagatie voltooid is (max 5 min bij lage TTL)

### Stap 8: Post-restore controles (30 min)

1. **Audit-event schrijven**:
   ```bash
   php artisan tinker --execute="
     \App\Models\AuditEvent::create([
       'organization_id' => null,
       'actor_id' => null,
       'action' => 'SYSTEM_RESTORED',
       'target_type' => 'system',
       'target_id' => 'full-restore',
       'after_data' => json_encode([
         'timestamp' => now()->toIso8601String(),
         'backup_file' => '${BACKUP_FILE}',
         'server' => gethostname(),
       ]),
     ]);
   "
   ```

2. **Monitoring activeren**:
   - UptimeRobot/Plesk Health Monitor configureren op nieuw IP
   - Log-monitoring controleren

3. **Eerste backup plannen**:
   ```bash
   php artisan backup:run
   # Controleer dat de backup succesvol is
   php artisan backup:verify
   ```

4. **Cleanup**:
   ```bash
   rm -rf /tmp/lavita-restore
   ```

### Noodcontacten

| Rol | Contact | Bereikbaarheid |
|-----|---------|----------------|
| Technisch beheerder | Zie interne contactlijst | 24/7 bij P1 |
| Cloud86 support | support@cloud86.nl | Kantooruren + noodlijn |
| Database-specialist | Zie interne contactlijst | Op afroep |

### Veelvoorkomende problemen

| Probleem | Oorzaak | Oplossing |
|----------|---------|-----------|
| Versleutelde kolommen onleesbaar | Verkeerde APP_KEY | Herstel originele APP_KEY uit password manager |
| Migratie-status inconsistent | Backup van oudere versie | Voer `php artisan migrate` uit |
| Queue-jobs falen | Verkeerde MAIL_* config | Controleer SMTP-credentials |
| Backup decrypt mislukt | Verkeerd BACKUP_ARCHIVE_PASSWORD | Controleer wachtwoord in veilige opslag |


---

## Scheduler-overzicht

Alle geplande taken draaien via Laravel's scheduler (`bootstrap/app.php`), getriggerd door een enkele cron-entry:

```cron
* * * * * cd /var/www/lavita-ur && php artisan schedule:run >> /dev/null 2>&1
```

| Job | Schema | Command | Beschrijving |
|-----|--------|---------|--------------|
| Backup | dagelijks 02:00 | `backup:run` | Volledige database + storage backup |
| Backup-integriteit | dagelijks 03:00 | `backup:verify` | Decrypt-test + SHA-256 manifest check |
| Jubileumnotificaties | dagelijks 06:00 | `notifications:anniversary` | Dienstjubilea detectie + mail |
| Openstaande-invoer herinnering | dagelijks 08:00 | `reminders:pending-input` | Herinnering bij ontbrekende uren |
| E-mail evidence integriteit | dagelijks 04:00 | `email-evidence:integrity` | Hash-chain verificatie outbox |
| Retentie + pseudonimisering | maandelijks 1e 03:00 | `retention:run` | 7-jaar pseudonimisering |
| Maandrapportage | maandelijks 1e 04:00 | via `MonthlyReportRun` | Maandoverzicht per team |
| Backup opschoning | dagelijks 02:30 | `backup:clean` | Verwijder backups ouder dan 30 dagen |

Alle tijden zijn in tijdzone **Europe/Amsterdam**.

---

## Netwerk en beveiliging

### Firewall-regels (Cloud86/Plesk)

| Poort | Protocol | Richting | Doel |
|-------|----------|----------|------|
| 443 | TCP | Inbound | HTTPS (applicatie) |
| 80 | TCP | Inbound | HTTP → 308 redirect naar HTTPS |
| 3306 | TCP | Alleen localhost | MySQL (geen externe toegang) |
| 22 | TCP | Inbound (beperkt) | SSH-beheer (IP-whitelist) |
| 25/587 | TCP | Outbound | SMTP-relay |

### Cookie-instellingen

```
Secure: true
SameSite: Strict
HttpOnly: true (sessie-cookies)
```

### Content Security Policy (aanbevolen)

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{random}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';
```

---

*Versie: mei 2026*
