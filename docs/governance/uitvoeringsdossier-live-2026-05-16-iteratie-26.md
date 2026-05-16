# Uitvoeringsdossier — Iteratie 26
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 16 mei 2026  
**Fase:** A — Must-scope beveiligingsverharding (R-06, F-12)  
**Modules:** MUST-AUTH-MFA · MUST-AUTH-ACCOUNT-CREATE

---

## 1. Analyse

### 1.1 Openstaande risico's na iteratie-25

| Nr | Risico | Ernst |
|----|--------|-------|
| R-06 | MFA secret rotatie-policy (180 dagen) nog niet afgedwongen | Laag |
| F-12 | Re-auth voor destructieve acties — account-aanmaak zonder wachtwoordbevestiging | Gemiddeld |

### 1.2 Geanalyseerde gap: rotatie-policy

`InternalApiAuth` middleware controleerde niet of het MFA-secret ouder is dan 180 dagen. Een verlopen secret bleef dus onbeperkt bruikbaar. Het veldje `rotated_at` was bovendien niet meegeladen in de eager-load (`user.mfaSecret:id,user_id,verified_at,disabled_at`), wat de check onmogelijk maakte.

### 1.3 Geanalyseerde gap: re-auth account-aanmaak

Het endpoint `POST /api/auth/accounts` maakte nieuwe gebruikersaccounts aan zonder wachtwoordbevestiging van de actor. Hierdoor kon een gestolen sessietoken (zonder wachtwoordkennis) direct accounts aanmaken — een privilege escalation vector bij sessie-kaping.

**Technische complicatie:** De sessie-actor wordt geladen als `user:id,name,email,role,...` zonder `password`. Re-auth vereist een aparte lookup: `User::select('id','password')->find($actor->id)`.

---

## 2. Overleg (extern expertpanel)

### 2.1 MFA rotatie-policy (security, compliance)
- **Oordeel:** 180-dagen drempel in lijn met NIST 800-63B en interne AVG-richtlijnen. Check na de verified-check in `InternalApiAuth`, vrijgesteld via `isMfaBootstrapRoute`. Foutcode `MFA_ROTATION_REQUIRED` + instructie naar `/api/auth/mfa/setup`.
- **Beslissing:** Implementeer in middleware, voeg `rotated_at` toe aan eager-load.

### 2.2 Re-auth account-aanmaak (security)
- **Oordeel:** `password_confirmation` verplicht in `POST /api/auth/accounts`. Wachtwoord-hash los ophalen uit DB om de beperkingen van de sessie-actor te omzeilen. Correcte validatiefout via `ValidationException`.
- **Beslissing:** Implementeer in `AuthModuleController::postInternalAuthAccounts`.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-16-I26:**

> 1. Voeg rotatie-check toe in `InternalApiAuth`: 403 + `MFA_ROTATION_REQUIRED` als `rotated_at` ouder is dan 180 dagen.  
> 2. Voeg `rotated_at` toe aan de eager-load van `mfaSecret` in de middleware.  
> 3. Voeg `password_confirmation` toe als verplicht veld in `POST /api/auth/accounts` met re-auth via aparte DB-lookup.  
> 4. Pas bestaande tests aan en voeg 2 nieuwe regressietests toe.

Bindend voor: MUST-AUTH-MFA, MUST-AUTH-ACCOUNT-CREATE.

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
| `app/Http/Middleware/InternalApiAuth.php` | Rotatie-check (180 dagen) + `rotated_at` in eager-load |
| `app/Http/Controllers/Transitie/AuthModule/AuthModuleController.php` | `password_confirmation` verplicht + re-auth DB-lookup |
| `tests/Feature/AuthModuleContractTest.php` | 3 bestaande tests bijgewerkt + 2 nieuwe regressietests |

### 6.2 Rotatie-check (middleware)

```php
// app/Http/Middleware/InternalApiAuth.php
// Rotatie-policy: MFA-secret mag maximaal 180 dagen oud zijn
$lastRotation = $mfa->rotated_at ?? $mfa->verified_at;
if ($lastRotation === null || $lastRotation->lt(now()->subDays(180))) {
    return response()->json([
        'error' => 'MFA-secret is verlopen (>180 dagen). Roteer via /api/auth/mfa/setup.',
        'code' => 'MFA_ROTATION_REQUIRED',
    ], 403);
}
```

### 6.3 Re-auth account-aanmaak (controller)

```php
// AuthModuleController::postInternalAuthAccounts
// password_confirmation is verplicht veld (added to validation rules)
$actorPassword = User::query()->select('id', 'password')->find($actor->id)?->password;
if (!$actorPassword || !Hash::check($validated['password_confirmation'], $actorPassword)) {
    throw ValidationException::withMessages([
        'password_confirmation' => 'Wachtwoordbevestiging is onjuist.',
    ]);
}
```

### 6.4 Nieuwe regressietests

- `test_mfa_rotation_required_after_180_days` — rotated_at 181 dagen terug → 403 + `MFA_ROTATION_REQUIRED`.
- `test_account_creation_requires_correct_password_confirmation` — verkeerd wachtwoord → 422; correct wachtwoord → 201.

---

## 7. Verificatie

### 7.1 Volledige testsuite

```
php artisan test
```

Resultaat: **140 tests, 479 assertions — 100% PASS** (6.22s)

Vorige baseline: 138 tests, 473 assertions.  
Delta: +2 tests, +6 assertions.

---

## 8. Heroverleg — Resterende risico's na iteratie-26

| Nr | Risico | Ernst | Status |
|----|--------|-------|--------|
| R-03 | Performance-baseline ontbreekt | Gemiddeld | Open |
| R-07 | Backup-restore niet geautomatiseerd getest | Laag | Open |

### 8.1 Definitieve audit-status

| Bevinding | Status |
|-----------|--------|
| F-01: Geen geautomatiseerde tests | ✅ Gesloten |
| F-02: MFA enforcement niet in middleware | ✅ Gesloten |
| F-03: Retentie/pseudonimisering | ✅ Gesloten |
| F-04: Redis-uitval rate-limit → fail-open | ✅ Gesloten |
| F-09: Geen performance-baseline | ⚠️ Open — R-03 |
| F-10: CSRF (API-token auth) | ✅ N.v.t. |
| F-12: Re-auth voor destructieve acties | ✅ **Gesloten** |
| R-02: CI/CD pipeline | ✅ Gesloten |
| R-04: MFA recovery codes | ✅ Gesloten |
| R-05: MFA brute-force bescherming | ✅ Gesloten |
| R-06: MFA rotatie-policy | ✅ **Gesloten** |
| R-07: Backup-restore niet getest | ⚠️ Open |

---

## 9. Verdikt

**ITERATIE 26: GO — MFA rotatie-policy en re-auth voor account-aanmaak geïmplementeerd en bewezen.**  
Alle kritieke en hoge risico's zijn nu gesloten. Twee lage/gemiddelde risico's (performance + backup-restore) blijven open voor volgende sprints.
