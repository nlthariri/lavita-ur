# Uitvoeringsdossier — Iteratie 24
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 16 mei 2026  
**Fase:** A — Must-scope beveiligingsverharding (R-02, R-04, R-05)  
**Modules:** MUST-AUTH-MFA · CI/CD

---

## 1. Analyse

### 1.1 Beginsituatie (openstaande risico's uit iteratie-23)

| Nr | Risico | Ernst | Aanpak iteratie-24 |
|----|--------|-------|--------------------|
| R-02 | CI/CD pipeline met quality gates ontbreekt | Hoog | GitHub Actions workflow aanmaken |
| R-04 | MFA recovery codes ontbreken (lockout bij apparaatverlies) | Gemiddeld | 8 eenmalige backup-codes genereren bij setup |
| R-05 | Brute-force bescherming MFA verify endpoint ontbreekt | Gemiddeld | Dedicated `mfa` rate-limiter: 5/min per user_id+IP |

### 1.2 Geanalyseerde gap: rate-limiting op `/auth/mfa/verify`

De bestaande `throttle:auth` (20/min per IP) beschermt het MFA-verificatie-endpoint niet voldoende: een aanvaller die over meerdere IP-adressen beschikt, kan onbeperkt TOTP-codes uitproberen.  
TOTP heeft een geldigheidsvenster van 30 seconden × ±1 drempel = 6 geldige codes per minuut. Bij 20 pogingen/min per IP is dit kwetsbaar voor distributed brute-force.

### 1.3 Geanalyseerde gap: MFA recovery codes

Zonder backup-codes is een gebruiker permanent buitengesloten bij verlies van zijn authenticator-apparaat. NIST 800-63B vereist een herstelmechanisme.

---

## 2. Overleg (extern expertpanel)

### 2.1 CI/CD (operations, security)
- **Oordeel:** GitHub Actions workflow met `php artisan test --stop-on-failure` als verplichte merge-blokkade. SQLite in-memory voor test-isolatie. PHP 8.3 + Composer cache voor snelheid.
- **Beslissing:** Trigger op `push` en `pull_request` naar `main` en `develop`.

### 2.2 MFA rate-limiting (security)
- **Oordeel:** Aparte rate-limiter `mfa` met sleutel `mfa|{user_id}|{ip}`, maximaal 5 pogingen per minuut. Dit voorkomt distributed brute-force ongeacht IP-rotatie, want de user_id wordt meegerekend.
- **Beslissing:** `/auth/mfa/verify` verplaatst uit de generieke `throttle:auth`-groep naar een eigen `throttle:mfa`-groep.

### 2.3 MFA recovery codes (security, UX)
- **Oordeel:** 8 codes van 10 alfanumerieke tekens (uppercase, Str::random). Codes worden gehasht opgeslagen (bcrypt). Bij hernieuwd MFA-setup worden alle oude codes vernietigd en nieuwe gegenereerd. Codes zijn éénmalig bruikbaar (`used_at` timestamp).
- **Route van verbruik:** `verifyMfa` probeert eerst TOTP (precies 6 cijfers), daarna recovery code.
- **Beslissing:** Implementeren via `mfa_recovery_codes` tabel, `MfaRecoveryCode` model, aanpassing `AuthMfaService`.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-16-I24:**

> 1. GitHub Actions CI-workflow aanmaken op `.github/workflows/ci.yml` met `php artisan test --stop-on-failure` als merge-blokkade.  
> 2. Dedicated `mfa` rate-limiter (5/min per user_id+IP) toepassen op `/auth/mfa/verify`.  
> 3. MFA recovery codes implementeren: migratie, model, service-uitbreiding (genereren + verbruiken), controller-response update, 2 nieuwe tests.

Bindend voor: MUST-AUTH-MFA, ops-infrastructuur.

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
| ATW-domeinspecialist | GO |
| Operations Engineer | GO |
| Release Manager | GO |

---

## 6. Implementatie

### 6.1 Gewijzigde/aangemaakt bestanden

| Bestand | Wijziging |
|---------|-----------|
| `.github/workflows/ci.yml` | **Nieuw** — GitHub Actions CI workflow |
| `app/Providers/AppServiceProvider.php` | `mfa` rate-limiter toegevoegd (5/min per user_id+IP) |
| `routes/api.php` | `/auth/mfa/verify` verplaatst naar eigen `throttle:mfa` groep |
| `database/migrations/2026_05_16_130100_create_mfa_recovery_codes_table.php` | **Nieuw** — `mfa_recovery_codes` tabel |
| `app/Models/MfaRecoveryCode.php` | **Nieuw** — Eloquent model |
| `app/Services/AuthMfaService.php` | Recovery codes genereren bij setup + verbruiken bij verify |
| `app/Http/Controllers/Transitie/AuthModule/AuthModuleController.php` | `recovery_codes` in setup-response; validatie `code` verbreed naar `min:6,max:20` |
| `tests/Feature/AuthModuleContractTest.php` | +2 tests: `test_mfa_setup_returns_eight_recovery_codes`, `test_mfa_verify_with_recovery_code_marks_code_as_used` |

### 6.2 CI/CD workflow details

```yaml
# .github/workflows/ci.yml
trigger: push + pull_request naar main/develop
php: 8.3
extensions: mbstring, sqlite3, pdo_sqlite, dom, curl, intl
test: php artisan test --stop-on-failure
env: DB_CONNECTION=sqlite, DB_DATABASE=:memory:, CACHE_STORE=array, QUEUE_CONNECTION=sync
```

### 6.3 MFA rate-limiter details

```php
// AppServiceProvider
RateLimiter::for('mfa', function (Request $request) {
    $userId = (string) $request->input('user_id', '');
    return Limit::perMinute(5)->by('mfa|' . $userId . '|' . $request->ip());
});
```

### 6.4 Recovery code flow

```
setupMfa()
  → generateRecoveryCodes() → 8 × strtoupper(Str::random(10))
  → DELETE mfa_recovery_codes WHERE user_id = $userId
  → INSERT mfa_recovery_codes (user_id, code_hash=Hash::make($plain))
  → response: recovery_codes = [$plain1, ..., $plain8]  ← éénmalig zichtbaar

verifyMfa($userId, $code)
  → strlen($code) === 6 && ctype_digit → TOTP-pad
  → anders → recovery-code-pad:
       find MfaRecoveryCode WHERE user_id = $userId AND used_at IS NULL
         AND Hash::check($code, code_hash)
       → update used_at = now()
       → return true
```

---

## 7. Verificatie

### 7.1 Volledige testsuite

```
php artisan test
```

Resultaat: **138 tests, 473 assertions — 100% PASS** (6.35s)

Vorige baseline: 136 tests, 455 assertions.  
Delta: +2 tests, +18 assertions — recovery code tests.

### 7.2 Statische analyse

Geen compile- of lintfouten op alle gewijzigde bestanden.

---

## 8. Heroverleg — Resterende risico's na iteratie-24

| Nr | Risico | Ernst | Status |
|----|--------|-------|--------|
| R-01 | Redis-uitval → rate-limiting niet fail-closed | Hoog | **Open** |
| R-03 | Performance-baseline ontbreekt | Gemiddeld | Open |
| R-06 | MFA secret rotatie-policy (180 dagen) | Laag | Open |
| R-07 | Backup-restore procedure niet geautomatiseerd getest | Laag | Open |

**Gesloten in iteratie-24:** R-02 (CI/CD), R-04 (recovery codes), R-05 (MFA brute-force).

### 8.1 Volledige audit-status

| Bevinding | Status |
|-----------|--------|
| F-01: Geen geautomatiseerde tests | ✅ Gesloten |
| F-02: MFA enforcement niet in middleware | ✅ Gesloten |
| F-03: Retentie/pseudonimisering | ✅ Gesloten |
| F-04: Redis-uitval rate-limit degradatie | ⚠️ Open — R-01 |
| F-09: Geen performance-baseline | ⚠️ Open — R-03 |
| F-10: CSRF (API-token auth) | ✅ N.v.t. |
| F-12: Re-auth voor destructieve acties | ⚠️ Gedeeltelijk |

---

## 9. Verdikt

**ITERATIE 24: GO — CI/CD-pipeline, MFA brute-force bescherming en recovery codes geïmplementeerd en bewezen.**
