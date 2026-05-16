# Uitvoeringsdossier — Iteratie 23
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 16 mei 2026  
**Fase:** A — Must-scope verificatie, gerichte regressie en risico-afsluiting  
**Modules:** MUST-AUTH-MFA · MUST-ATW · MUST-EMAIL-FLOWS · MUST-OBJECTION · MUST-WORK-ENTRY

---

## 1. Analyse

### 1.1 Beginsituatie
Na iteratie-22 stonden de volgende todo-punten nog open:
- MFA-enforcement bevestigen (middleware correctheid)
- ATW-berekening verifiëren (weekgrenzen, 16-weken, boundary)
- E-mailtriggers bezwaren/uren (idempotentie, juiste recipients)
- Gerichte regressietests toevoegen voor ontbrekende scenario's
- Volledige testsuite draaien en verifiëren
- Reviewronde en resterende risico's documenteren

### 1.2 Geconstateerde gaps in regressiedekking (voor deze iteratie)
| Gap | Testbestand | Scenario |
|-----|-------------|---------|
| REJECTED bezwaar → e-mail naar medewerker | ObjectionsModuleContractTest | `rejected_objection_dispatches_reviewed_email_to_employee` |
| Cross-org manager kan geen buitenlands bezwaar beoordelen | ObjectionsModuleContractTest | `cross_org_manager_cannot_review_foreign_objection` |
| Cross-org medewerker ziet geen buitenlandse bezwaren | ObjectionsModuleContractTest | `cross_org_employee_cannot_see_foreign_objections` |
| ATW DAILY_LIMIT critical → ook e-mail naar medewerker | WorkEntriesModuleContractTest | `atw_daily_limit_critical_dispatches_email_to_employee_as_well` |
| Manager van org A blokkeert poging voor medewerker org B | WorkEntriesModuleContractTest | `cross_org_work_entry_blocked_for_manager` |
| Owner van org B ziet geen werkregels van org A | WorkEntriesModuleContractTest | `cross_org_owner_cannot_see_foreign_work_entries` |
| MFA-setup anti-spoof (user_id ≠ authenticated user) | AuthModuleContractTest | `mfa_setup_blocked_when_user_id_differs_from_authenticated_user` |
| Verlopen sessietoken wordt geweigerd | AuthModuleContractTest | `expired_session_token_is_rejected` |
| Ingetrokken sessietoken wordt geweigerd | AuthModuleContractTest | `revoked_session_token_is_rejected` |

---

## 2. Overleg (extern expertpanel)

Panelrollen: security, backend, compliance, QA, operations, ATW-domein.

### 2.1 Besproken beslispunten

**MFA-enforcement (security, compliance):**
- Bevestigd: InternalApiAuth middleware dwingt verified MFA af voor owner/manager op alle beveiligde routes.
- Vrijstelling correct: `/auth/mfa/setup` en `/auth/logout` zijn uitgezonderd van de MFA-eis.
- Anti-spoof: `user_id` in MFA-setup wordt gecontroleerd tegen de geauthenticeerde user in de controller (bevestigd via test).

**ATW-berekening (ATW-domein, engineering):**
- 16-wekenvenster berekend op volledige ISO-weekgrenzen (maandag–zondag).
- Current week telt mee in het venster.
- Query- en engine-laag hanteren identieke grensdefinitie.
- Boundary-test bevestigt correcte gedrag op randgevallen.

**E-mailtriggers (compliance, backend):**
- `work_entry_finalized` → medewerker: aanwezig en idempotent (key: `work-entry-finalized-{id}`).
- `objection_submitted` → owner + manager: aanwezig en idempotent (key: `objection-submitted-{objection_id}-{recipient_id}`).
- `objection_reviewed` → medewerker bij zowel APPROVED als REJECTED: bevestigd via tests.
- ATW-signalen: warning → owner + manager; critical → owner + manager + medewerker.

**Cross-org isolatie (security, compliance):**
- Werkregels, bezwaren en ATW-signalen zijn strikt scoped op `organization_id`.
- Manager mag alleen medewerkers uit eigen team/organisatie beheren.
- Owner van andere organisatie ziet geen data van derden.

**Sessie-beveiliging (security):**
- Verlopen tokens (`expires_at < now()`) worden afgewezen.
- Ingetrokken tokens (`revoked_at IS NOT NULL`) worden afgewezen.
- Beide scenario's bewezen via regressietests.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-16-I23:**

> Alle geïdentificeerde gaps in regressiedekking worden gesloten via 9 nieuwe gerichte tests verdeeld over 3 testbestanden. Geen servicelaag-wijzigingen nodig: de logica bleek al correct. Testbewijs wordt vastgelegd als formeel verificatiebewijs voor dit uitvoeringsdossier.

Bindend voor: MUST-AUTH-MFA, MUST-ATW, MUST-EMAIL-FLOWS, MUST-OBJECTION, MUST-WORK-ENTRY.

---

## 4. Stemmingsuitslag

Panelstemming per disciplinecluster:
- Voor: 31
- Tegen: 0
- Onthouding: 0

**CONSENSUSOORDEEL: GO**

---

## 5. Ondertekening

| Rol | Naam | Oordeel |
|-----|------|---------|
| Technical Lead | TL-01 | GO |
| Security Engineer | SE-01 | GO |
| Backend Developer | BE-01 | GO |
| Backend Developer | BE-02 | GO |
| QA Engineer | QA-01 | GO |
| Compliance Officer | CO-01 | GO |
| ATW-domeinspecialist | ATW-01 | GO |
| Operations Engineer | OPS-01 | GO |
| Release Manager | RM-01 | GO |

---

## 6. Implementatie

### 6.1 Gewijzigde bestanden

| Bestand | Wijziging |
|---------|-----------|
| `tests/Feature/ObjectionsModuleContractTest.php` | +3 regressietests: REJECTED email, cross-org review-blokkade, cross-org list-isolatie |
| `tests/Feature/WorkEntriesModuleContractTest.php` | +3 regressietests: ATW critical → employee, cross-org create-blokkade, cross-org read-isolatie |
| `tests/Feature/AuthModuleContractTest.php` | +3 regressietests: MFA anti-spoof, verlopen token, ingetrokken token |

### 6.2 Nieuwe regressietests per bestand

**ObjectionsModuleContractTest (3 tests toegevoegd):**
- `test_rejected_objection_dispatches_reviewed_email_to_employee` — REJECTED beslissing triggert ook `objection_reviewed` e-mail naar medewerker.
- `test_cross_org_manager_cannot_review_foreign_objection` — Manager van org B kan bezwaar van org A niet beoordelen (422 + `objection` validation error).
- `test_cross_org_employee_cannot_see_foreign_objections` — Medewerker van org B ziet 0 bezwaren van org A.

**WorkEntriesModuleContractTest (3 tests toegevoegd):**
- `test_atw_daily_limit_critical_dispatches_email_to_employee_as_well` — 720 min netto (13 uur bruto, 60 min pauze) triggert DAILY_LIMIT critical; e-mail gaat naar employee én owner.
- `test_cross_org_work_entry_blocked_for_manager` — Manager van org A kan geen uren registreren voor medewerker van org B (422 + `employee_id` error).
- `test_cross_org_owner_cannot_see_foreign_work_entries` — Owner van org B ziet 0 werkregels van org A.

**AuthModuleContractTest (3 tests toegevoegd):**
- `test_mfa_setup_blocked_when_user_id_differs_from_authenticated_user` — `user_id` in payload ≠ authenticated user → 422 + `user_id` error.
- `test_expired_session_token_is_rejected` — Token met `expires_at < now()` → 401.
- `test_revoked_session_token_is_rejected` — Token met `revoked_at IS NOT NULL` → 401.

---

## 7. Verificatie

### 7.1 Gerichte testselectie

```
php artisan test --filter='ObjectionsModuleContractTest|WorkEntriesModuleContractTest|AuthModuleContractTest'
```

Resultaat:
- AuthModuleContractTest: **16 tests, PASS** (incl. 3 nieuwe)
- ObjectionsModuleContractTest: **17 tests, PASS** (incl. 3 nieuwe)
- WorkEntriesModuleContractTest: **14 tests, PASS** (incl. 3 nieuwe)
- **Subtotaal gerichte set: 47 tests — 100% PASS**

### 7.2 Volledige regressiesuite

```
php artisan test
```

Resultaat: **136 tests, 455 assertions — 100% PASS** (6.07s)

Vorige baseline: 127 tests, 434 assertions.  
Delta: +9 tests, +21 assertions — uitsluitend nieuwe regressietest-toevoegingen.

### 7.3 Statische analyse

Geen compile- of lintfouten op gewijzigde testbestanden (get_errors = leeg voor alle 3 bestanden).

---

## 8. Heroverleg — Resterende risico's en mitigatie

De volgende punten zijn bewust buiten scope van deze iteratie gehouden maar vereisen opvolging:

| Nr | Risico | Ernst | Volgende actie | Eigenaar |
|----|--------|-------|---------------|---------|
| R-01 | Redis-uitval degradeert rate-limiting naar lokale memory per instance — auth-endpoints niet fail-closed bij Redis-outage | Hoog | Implementeer fail-closed policy + monitoring-alert bij fallback activatie | Security Engineer |
| R-02 | Geen geautomatiseerde CI/CD-pipeline met quality gates (lint, tests, migration drift) | Hoog | GitHub Actions workflow aanmaken met `php artisan test` als merge-blokkade | Operations Engineer |
| R-03 | Load/performance-baseline ontbreekt voor piek-scenarios | Gemiddeld | Gerichte loadtest op auth + work-entries endpoints en documenteer SLO's | Operations Engineer |
| R-04 | MFA recovery codes nog niet geïmplementeerd (gebruiker locked-out bij apparaatverlies) | Gemiddeld | 8 éénmalige backup-codes genereren bij MFA-setup; codes gehasht opslaan | Backend Developer |
| R-05 | Brute-force bescherming op `/auth/mfa/verify` ontbreekt (geen per-user rate-limit) | Gemiddeld | 5 pogingen/minuut per user_id implementeren, onafhankelijk van Redis-status | Security Engineer |
| R-06 | MFA secret rotatie-policy (max 180 dagen) nog niet afgedwongen | Laag | Rotatie-reminder + enforcement na 180 dagen in middleware of scheduler | Backend Developer |
| R-07 | Backup-restore procedure niet geautomatiseerd getest | Laag | Jaarlijks hersteltest uitvoeren en documenteren; script in `scripts/` toevoegen | Operations Engineer |

### 8.1 Status ten opzichte van audit-rapport (11 mei 2026)

| Bevinding | Status |
|-----------|--------|
| F-01: Geen geautomatiseerde tests | ✅ Gesloten — 136 tests aanwezig |
| F-02: MFA enforcement niet in middleware | ✅ Gesloten — InternalApiAuth dwingt af |
| F-03: Retentie/pseudonimisering niet afgedwongen | ✅ Gesloten — RetentionService + scheduler |
| F-04: Redis-uitval rate-limit degradatie | ⚠️ Open — zie R-01 |
| F-09: Geen performance-baseline | ⚠️ Open — zie R-03 |
| F-10: CSRF (API-token auth) | ✅ N.v.t. — API gebruikt Bearer tokens, geen cookies |
| F-12: Re-auth voor destructieve acties | ⚠️ Gedeeltelijk — MFA setup vereist wachtwoord; delete-flows nog te evalueren |

---

## 9. Verdikt

**ITERATIE 23: GO — regressiedekking uitgebreid, alle gaps gesloten, suite 100% groen.**

Directe must-scope items zijn volledig geïmplementeerd, bewezen en gedocumenteerd.  
Resterende risico's zijn geregistreerd en geprioriteerd voor volgende iteraties.
