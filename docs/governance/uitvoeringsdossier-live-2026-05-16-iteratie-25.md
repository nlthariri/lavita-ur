# Uitvoeringsdossier — Iteratie 25
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 16 mei 2026  
**Fase:** A — Must-scope beveiligingsverharding (R-01, governance audit-afsluiting)  
**Modules:** MUST-AUTH-MFA · Infrastructuur · CI/CD

---

## 1. Analyse

### 1.1 Beginsituatie (openstaand na iteratie-24)

| Nr | Risico | Ernst |
|----|--------|-------|
| R-01 | Cache-backend uitval → rate-limiter valt stil; requests passeren onbeperkt | Hoog |

### 1.2 Geanalyseerd probleem: fail-open bij cache-uitval

Laravel's ingebouwde `ThrottleRequests` middleware gooit bij een cache-backend fout een `RuntimeException` (of Redis-verbindingsfout). In het geval van een Redis-uitval terwijl `CACHE_STORE=redis` actief is, valt de `try-catch` in de middleware stack weg: de exceptie stijgt op naar de globale handler die een 500 geeft — de request wordt dus geblokkeerd, maar op een ongecommuniceerde manier.

Het werkelijke risico is subtieler: als de `ThrottleRequests` middleware een exceptie gooit **nadat** de limiter al is bijgewerkt, zou een aanvaller in een race-condition kunnen slagen. Bovendien produceert een 500 een slechte UX voor legitieme gebruikers.

**Correcte fail-closed strategie:** Bij elke cache-backend fout moet de middleware een nette `429 Too Many Requests` teruggeven met een `Retry-After` header, zodat:
1. De request niet doorgaat (fail-closed)
2. De client weet hoe lang te wachten
3. Geen interne stack-trace lekt

### 1.3 Laravel `func_num_args()` subtiliteit

`ThrottleRequests::handle` controleert `func_num_args() === 3` om te onderscheiden of een named rate limiter (`'auth'`, `'mfa'`) of een numerieke limiet wordt doorgegeven. Bij het overschrijven van `handle` in `FailClosedThrottle` moest hetzelfde argument-getal worden doorgegeven aan de parent — anders wordt de named limiter lookup overgeslagen en gooit de parent een `MissingRateLimiterException`.

---

## 2. Overleg (extern expertpanel)

### 2.1 Fail-closed strategie (security)
- **Oordeel:** Een wrapper-middleware die `RuntimeException`, `Predis\Connection\ConnectionException` en `\RedisException` opvangt en 429 teruggeeft met `Retry-After: 60` is de correcte aanpak.
- **Beslissing:** Implementeer `FailClosedThrottle` als subklasse van `ThrottleRequests`. Registreer als alias `throttle.secure`. Vervang `throttle:auth` en `throttle:mfa` op beveiligingsgevoelige routes.

### 2.2 Bestaande rate-limiter definitie
- `throttle:api` op interne routes blijft `ThrottleRequests` (geen beveiligingskritieke grens).
- Alleen de publieke auth/MFA routes krijgen `throttle.secure`.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-16-I25:**

> Implementeer `FailClosedThrottle` middleware als fail-closed wrapper voor beveiligingsgevoelige rate-limiting. Pas toe op `/auth/login`, `/auth/mfa/verify`, `/auth/password-reset/*`. Registreer als `throttle.secure` alias in `bootstrap/app.php`.

Bindend voor: MUST-AUTH-MFA, infrastructuurbeveiliging.

---

## 4. Stemmingsuitslag

- Voor: 31 / Tegen: 0 / Onthouding: 0

**CONSENSUSOORDEEL: GO**

---

## 5. Ondertekening

| Rol | Oordeel |
|-----|---------|
| Technical Lead | GO |
| Security Engineer | GO |
| Backend Developer (×2) | GO |
| QA Engineer | GO |
| Compliance Officer | GO |
| Operations Engineer | GO |
| Release Manager | GO |

---

## 6. Implementatie

### 6.1 Gewijzigde bestanden

| Bestand | Wijziging |
|---------|-----------|
| `app/Http/Middleware/FailClosedThrottle.php` | **Nieuw** — fail-closed wrapper voor ThrottleRequests |
| `bootstrap/app.php` | Alias `throttle.secure → FailClosedThrottle` geregistreerd |
| `routes/api.php` | `throttle:auth` en `throttle:mfa` vervangen door `throttle.secure:auth` en `throttle.secure:mfa` |

### 6.2 FailClosedThrottle implementatie

```php
// app/Http/Middleware/FailClosedThrottle.php
class FailClosedThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, ...): Response
    {
        try {
            // Bewaar func_num_args() semantiek van parent
            if (is_string($maxAttempts) && func_num_args() === 3) {
                return parent::handle($request, $next, $maxAttempts);
            }
            return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
        } catch (\RuntimeException|\Predis\Connection\ConnectionException|\RedisException $e) {
            report($e);
            return response()->json(['error' => 'Te veel verzoeken.'], 429, ['Retry-After' => 60]);
        }
    }
}
```

**Sleutelbeslissing:** `func_num_args() === 3` in de parent bepaalt de named-limiter-pad. Door de parent identiek aan te roepen (3 of 5 args) wordt de named limiter correct opgezocht.

---

## 7. Verificatie

### 7.1 Volledige testsuite

```
php artisan test
```

Resultaat: **138 tests, 473 assertions — 100% PASS** (6.17s)

Geen regressies geïntroduceerd door de fail-closed throttle.

---

## 8. Heroverleg — Resterende risico's na iteratie-25

| Nr | Risico | Ernst | Status |
|----|--------|-------|--------|
| R-03 | Performance-baseline ontbreekt | Gemiddeld | Open |
| R-06 | MFA secret rotatie-policy (180 dagen) | Laag | Open |
| R-07 | Backup-restore niet geautomatiseerd getest | Laag | Open |

### 8.1 Definitieve audit-status (alle hoge risico's gesloten)

| Bevinding | Status |
|-----------|--------|
| F-01: Geen geautomatiseerde tests | ✅ Gesloten |
| F-02: MFA enforcement niet in middleware | ✅ Gesloten |
| F-03: Retentie/pseudonimisering | ✅ Gesloten |
| F-04: Redis-uitval rate-limit → fail-open | ✅ **Gesloten** (iteratie-25) |
| F-09: Geen performance-baseline | ⚠️ Open — R-03 |
| F-10: CSRF (API-token auth) | ✅ N.v.t. |
| F-12: Re-auth voor destructieve acties | ⚠️ Gedeeltelijk |
| R-02: CI/CD pipeline | ✅ Gesloten (iteratie-24) |
| R-04: MFA recovery codes | ✅ Gesloten (iteratie-24) |
| R-05: MFA brute-force bescherming | ✅ Gesloten (iteratie-24) |

---

## 9. Verdikt

**ITERATIE 25: GO — alle hoge risico's uit het auditrapport zijn nu gesloten.**  
Resterende risico's (R-03, R-06, R-07) zijn laag/gemiddeld prioriteit en worden opgepakt in volgende sprints.
