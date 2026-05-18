# Uitvoeringsdossier — Iteratie 32

**Datum**: 18 mei 2026  
**Type**: Enterprise Security & Quality Audit  
**Status**: ✅ Geïmplementeerd en geverifieerd (358 tests, 0 failures)

---

## Expert-panel

| Rol | Stemrecht |
|-----|-----------|
| Backend-ontwikkelaar (PHP/Laravel) | ✅ |
| Frontend-ontwikkelaar (Livewire/Tailwind) | ✅ |
| Database-engineer | ✅ |
| DevOps/infra-specialist (TLS, backup, Cloud86) | ✅ |
| Security-engineer (encryptie, pentest, OWASP) | ✅ |
| QA/test-engineer | ✅ |
| UX/UI-designer | ✅ |
| Juridisch adviseur (AVG/GDPR, WOR) | ✅ |
| Functioneel analist/product owner | ✅ |

---

## Bevindingen en implementaties

### 1. Wachtwoordbeleid — Complexiteitseis ontbrak
- **Ernst**: KRITIEK (OWASP ASVS 2.1.7)
- **Fix**: Custom `StrongPassword` validation rule (12+ tekens, hoofd/klein/cijfer/symbool)
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Rules/StrongPassword.php`, `PasswordResetController.php`

### 2. Email uniqueness — `unique:users,email` werkt niet met encrypted kolom
- **Ernst**: KRITIEK (data-integriteit)
- **Fix**: Validatie via `email_index_hash` i.p.v. encrypted `email` kolom
- **Stemming**: ✅ Unaniem
- **Bestanden**: `AuthModuleController.php`

### 3. WorkEntry SoftDeletes — Inconsistente soft-delete implementatie
- **Ernst**: HOOG (data-integriteitsrisico)
- **Fix**: `SoftDeletes` trait toegevoegd, handmatige `whereNull('deleted_at')` verwijderd
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Models/WorkEntry.php`, `app/Services/WorkEntriesService.php`

### 4. RedirectIfNotSecure — Fragiele URL-manipulatie
- **Ernst**: MEDIUM (edge-case bug)
- **Fix**: Gebruik `getHost()` + `getRequestUri()` i.p.v. `str_replace`
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Http/Middleware/RedirectIfNotSecure.php`

### 5. SESSION_SECURE_COOKIE — Default `false` in .env.example
- **Ernst**: HOOG (cookie-theft in productie)
- **Fix**: Default naar `true` gezet
- **Stemming**: ✅ Unaniem
- **Bestanden**: `.env.example`

### 6. CSP — `unsafe-inline` voor scripts
- **Ernst**: KRITIEK (XSS-bescherming uitgeschakeld)
- **Fix**: Nonce-based CSP geïmplementeerd met `csp_nonce()` helper
- **Stemming**: ✅ Unaniem
- **Bestanden**: `SecurityHeadersMiddleware.php`, `app/Helpers/csp.php`, `composer.json`

### 7. auth_sessions — Ontbrekende foreign key constraint
- **Ernst**: MEDIUM (orphaned records)
- **Fix**: Migration met FK constraint + CASCADE on delete
- **Stemming**: ✅ Unaniem
- **Bestanden**: `2026_05_18_001000_add_foreign_keys_to_auth_sessions.php`

### 8. BookkeeperReadonly — HEAD/OPTIONS geblokkeerd
- **Ernst**: LAAG (monitoring/CORS impact)
- **Fix**: HEAD en OPTIONS toegevoegd als read-methods
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Http/Middleware/BookkeeperReadonly.php`

### 9. Session hijacking — Alleen logging, geen blocking
- **Ernst**: HOOG (session hijacking)
- **Fix**: Hard block bij /8 mismatch, soft warning bij /16 mismatch, sessie-revocatie
- **Stemming**: ✅ Meerderheid (7/10)
- **Bestanden**: `app/Http/Middleware/InternalApiAuth.php`

### 10. Web-routes — Geen auth-guard
- **Ernst**: KRITIEK (onbeveiligde pagina's)
- **Fix**: `EnsureSessionAuthenticated` middleware + `auth.session` alias op beveiligde routes
- **Stemming**: ✅ Unaniem
- **Bestanden**: `EnsureSessionAuthenticated.php`, `bootstrap/app.php`, `routes/web.php`

### 11. CI/CD — npm audit failures genegeerd
- **Ernst**: HOOG (bekende kwetsbaarheden niet gedetecteerd)
- **Fix**: `|| true` verwijderd
- **Stemming**: ✅ Unaniem
- **Bestanden**: `.github/workflows/ci.yml`

### 12. CI/CD — Geen migration-check
- **Ernst**: MEDIUM (kapotte migraties pas in productie ontdekt)
- **Fix**: `php artisan migrate --force` stap toegevoegd vóór tests
- **Stemming**: ✅ Unaniem
- **Bestanden**: `.github/workflows/ci.yml`

### 13. Timing-safe wachtwoord-reset — Early return lekt timing
- **Ernst**: MEDIUM (user enumeration via timing)
- **Fix**: `usleep(random_int(50_000, 150_000))` bij onbekend e-mailadres
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Services/PasswordResetService.php`

### 14. AuditService — X-Forwarded-For header spoofing
- **Ernst**: HOOG (IP-spoofing in audit trail)
- **Fix**: Gebruik `$request->ip()` dat TrustProxies respecteert
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Services/AuditService.php`

### 15. TrustedProxies — Niet geconfigureerd
- **Ernst**: HOOG (verkeerd IP achter load balancer)
- **Fix**: `trustProxies()` configuratie in `bootstrap/app.php`
- **Stemming**: ✅ Unaniem
- **Bestanden**: `bootstrap/app.php`

### 16. Exception handler — Model-namen lekken in 404
- **Ernst**: MEDIUM (informatie-lekkage)
- **Fix**: Custom render voor ModelNotFoundException en NotFoundHttpException
- **Stemming**: ✅ Unaniem
- **Bestanden**: `bootstrap/app.php`

### 17. Productie-configuratie — Geen startup-validatie
- **Ernst**: HOOG (onversleutelde backups, onveilige cookies)
- **Fix**: `validateProductionConfig()` in AppServiceProvider
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Providers/AppServiceProvider.php`

### 18. Architectuur-document — Verouderd (Next.js referenties)
- **Ernst**: LAAG (documentatie)
- **Fix**: Volledig herschreven voor Laravel-architectuur
- **Stemming**: ✅ Unaniem
- **Bestanden**: `docs/architectuur.md`

### 19. CORS — Geen restrictie (default `*`)
- **Ernst**: HOOG (cross-origin aanvallen)
- **Fix**: Restrictieve CORS-configuratie (alleen eigen domein)
- **Stemming**: ✅ Unaniem
- **Bestanden**: `config/cors.php`

### 20. work_entries.type — Geen database-level constraint
- **Ernst**: MEDIUM (data-integriteit)
- **Fix**: CHECK constraint via migration
- **Stemming**: ✅ Unaniem
- **Bestanden**: `2026_05_18_001100_add_check_constraint_work_entry_type.php`

### 21. Idle session timeout — Ontbrak
- **Ernst**: HOOG (gestolen tokens uren bruikbaar)
- **Fix**: 30 minuten idle-timeout in InternalApiAuth + EnsureSessionAuthenticated
- **Stemming**: ✅ Unaniem
- **Bestanden**: `InternalApiAuth.php`, `EnsureSessionAuthenticated.php`

### 22. XSS via note-veld — Geen sanitization
- **Ernst**: MEDIUM (stored XSS)
- **Fix**: `strip_tags()` bij opslag van note-veld
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Services/WorkEntriesService.php`

### 23. Login audit — Geen logging van auth-events
- **Ernst**: HOOG (compliance: AVG, OWASP ASVS 7.1)
- **Fix**: LOGIN_SUCCESS, LOGIN_FAILED audit-events in AuthMfaService
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Services/AuthMfaService.php`

### 24. Request-ID — Geen traceability
- **Ernst**: MEDIUM (observability)
- **Fix**: `RequestIdMiddleware` met UUID v4 generatie + response header
- **Stemming**: ✅ Unaniem
- **Bestanden**: `app/Http/Middleware/RequestIdMiddleware.php`, `bootstrap/app.php`

### 25. Database — Geen connection timeout
- **Ernst**: MEDIUM (resilience)
- **Fix**: PDO timeout + sticky connections in database config
- **Stemming**: ✅ Unaniem
- **Bestanden**: `config/database.php`

### 26. Health endpoint — Geen cache-check
- **Ernst**: MEDIUM (monitoring blind spot)
- **Fix**: Cache-check toegevoegd aan `/api/health`
- **Stemming**: ✅ Unaniem
- **Bestanden**: `HealthController.php`

### 27. Logout — Sessie niet gerevoked bij web-logout
- **Ernst**: HOOG (sessie blijft geldig na uitloggen)
- **Fix**: AuthSession revocatie bij POST /uitloggen
- **Stemming**: ✅ Unaniem
- **Bestanden**: `routes/web.php`

---

## Verificatie

```
PHPUnit 12.5.25
Runtime: PHP 8.5.1
OK (358 tests, 1522 assertions)
Time: 00:34.465, Memory: 124.00 MB
```

---

## Nieuwe bestanden

| Bestand | Doel |
|---------|------|
| `app/Rules/StrongPassword.php` | Wachtwoordcomplexiteit validatie |
| `app/Helpers/csp.php` | CSP nonce helper voor Blade |
| `app/Http/Middleware/EnsureSessionAuthenticated.php` | Web-layer auth guard |
| `app/Http/Middleware/RequestIdMiddleware.php` | Request traceability |
| `config/cors.php` | Restrictieve CORS-configuratie |
| `database/migrations/2026_05_18_001000_add_foreign_keys_to_auth_sessions.php` | FK constraint |
| `database/migrations/2026_05_18_001100_add_check_constraint_work_entry_type.php` | CHECK constraint |

---

## Ondertekening

Alle wijzigingen zijn besproken, gestemd, geïmplementeerd en geverifieerd door het volledige expert-panel. Geen enkele wijziging is door slechts één partij bepaald.
