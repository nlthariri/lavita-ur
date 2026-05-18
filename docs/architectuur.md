# Architectuur La Vita Urenregistratie

## Scopebasis

Bron van waarheid: opdrachtspecificatie v1.1 + Laravel-herbouw (mei 2026).

## Gemaakte aannames

- Hostingdoel is Cloud86 met PHP 8.3-runtime (VPS met Plesk).
- Productiedomein draait op subdomein van het bedrijfsdomein, bijvoorbeeld `uren.jouwdomein.nl`.
- Schaaldoel is klein tot middelgroot: 3 naar 10 medewerkers in 3 jaar. Daarom is single-region EER met verticale schaal en eenvoudige horizontale optie passend.
- Geen publieke REST API in v1; interne API-routes worden gebruikt voor frontend-backendverkeer via bearer-token authenticatie.

## Laag 1: Architectuur

- Applicatie: Laravel 13 met PHP 8.3, Livewire 3 (full-page components).
- Frontend: Tailwind CSS 3.4, Vite 8, Inter/Geist fonts.
- Datalaag: MySQL 8 (productie), SQLite (tests).
- Domeinmodules:
  - Ureninvoer en netto-minutenberekening
  - ATW-signalen (dag/week/16-weken/rust/pauze)
  - Bezwaarproces (OPEN → ACCEPTED/REJECTED)
  - E-mailtemplates en outbox-pattern
  - Projecten en kostenplaatsen
  - Verlof/ziekte/feestdagen (SICK/LEAVE/HOLIDAY)
  - Rapportages (PDF/Excel/jaaroverzicht)
  - AVG-retentie en pseudonimisering

## Laag 2: Backend

Aanwezig:
- Service-laag met strikte scheiding (WorkEntriesService, AtwService, AuthMfaService, etc.)
- Custom bearer-token authenticatie met SHA-256 hashing
- TOTP MFA (RFC 6238) met recovery codes
- Email outbox pattern met idempotency, exponential backoff, event chain hashing
- ATW-engine met alle wettelijke controles (dag/week/16-weken/rust/pauze)
- Audit trail voor alle mutaties
- Retentie-service met pseudonimisering (AVG)
- Account provisioning met welkomstmail

## Laag 3: Frontend

Aanwezig:
- Inlog + MFA flow (LoginForm, MfaVerifyForm, MfaSetupQr)
- Wachtwoord vergeten/reset flow
- Dashboard (ManagerHome)
- Weekoverzicht en mijn-week
- Verlofregistratie
- Bezwaarbeoordeling
- ATW-statusdashboard
- Rapportages met filters en jaaroverzicht
- Accountbeheer
- E-mailtemplate-instellingen

## Laag 4: Security en compliance

Aanwezig:
- Fail-closed rate limiting (FailClosedThrottle)
- HSTS met preload, includeSubDomains
- Nonce-based CSP (Content Security Policy)
- Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy)
- HTTPS redirect in productie (308 Permanent Redirect)
- Encrypted PII (email, full_name, phone) met email_index_hash voor lookups
- TOTP replay-bescherming via cache
- Timing-safe wachtwoord-reset (geen user enumeration)
- Session hijacking detectie (/8 hard block, /16 soft warning)
- MFA rotatie-policy (180 dagen)
- Append-only DB triggers voor evidence-tabellen
- Bookkeeper read-only enforcement
- Wachtwoordbeleid conform NCSC (12+ tekens, mix hoofd/klein/cijfer/symbool)
- Web-routes beveiligd met auth.session middleware
- Custom exception handler (geen model-namen lekken)
- TrustedProxies configuratie

## Laag 5: Ops

Aanwezig:
- CI/CD pipeline (GitHub Actions): PHPUnit, Pint linting, composer audit, npm audit, frontend build, migration check
- Spatie Laravel Backup met verplichte encryptie
- Productie-configuratie validatie bij startup
- Health/ready endpoints

Nog te bouwen:
- Monitoring en uptime/SLA-dashboard
- Backup/restore runbook met jaarlijkse hersteltestregistratie
- Deployment-automatisering (Plesk/Cloud86)
