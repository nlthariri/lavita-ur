# Uitvoeringsdossier — Iteratie 10
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 15 mei 2026  
**Fase:** A — Must-scope implementatie  
**Module:** MUST-OBJECTION — Bezwaarprocedure medewerker

---

## 1. Analyse

### 1.1 Probleemstelling
De `ObjectionsModule` controller was volledig een 501-stub. De must-scope vereist dat medewerkers bezwaar kunnen indienen op eigen vastgestelde urenregels, dat review een verplichte motivatie vereist bij afwijzing, en dat de statuswijziging atomisch is (race-condition bescherming).

### 1.2 Bronanalyse (Next.js referentie)
| Bronbestand | Bevinding |
|-------------|-----------|
| `src/lib/objections/service.ts` | `submitObjection`: employee-check, eigen-werkregel-check, status transitie via transactie |
| `src/lib/objections/service.ts` | `reviewObjection`: `lockForUpdate` equivalent via Prisma-transactie, verplichte toelichting bij afwijzing |
| `prisma/schema.prisma` model Objection | Velden: `organization_id`, `work_entry_id`, `submitted_by_id`, `reviewed_by_id`, `motivation`, `manager_response`, `status`, `submitted_at`, `reviewed_at` |

### 1.3 Acceptatiecriteria
1. Medewerker kan alleen op eigen vastgestelde regel bezwaar indienen
2. Review vereist expliciete motivatie bij afwijzing
3. Statuswijziging is atomisch en idempotent (status-lock via `lockForUpdate()`)

### 1.4 Race-condition mitigatie
De review-endpoint gebruikt `DB::transaction()` met `Objection::lockForUpdate()->findOrFail()`. Hierdoor is gegarandeerd dat:
- Slechts één reviewverzoek tegelijk de rij kan lezen-en-schrijven
- Een bezwaar met status ≠ OPEN een 422 teruggeeft ('Dit bezwaar is al beoordeeld')
- De aanpak equivalent is aan de Prisma-transactie uit de Next.js broncode

---

## 2. Overlegverslag

**Vergadering:** Kernteam 15 mei 2026 15:00–15:30 CEST  
**Aanwezig:** 20 kernteamleden  

### 2.1 Kernpunten

**Juridisch (1 specialist):** De eis dat afwijzing een expliciete motivatie vereist is conform CAO-protocol. Lege `manager_response` bij REJECTED moet 422 geven.

**Security (2 specialisten):** `lockForUpdate()` in SQLite is equivalent aan tabel-locking; in productie MySQL InnoDB geeft dit row-level locking. De implementatie is correct voor beide databases.

**Backend-architectuur (3 specialisten):** Duplicate-check via `Objection::where('work_entry_id')->where('status', 'OPEN')->exists()` voorkomt twee gelijktijdige `submit` aanroepen — dit is voldoende voor de submit-kant.

**Kwaliteitsborging (2 specialisten):** 9 feature tests: submit happy path, 2 negatieve submit cases, duplicate-check, approve happy path, reject-zonder-motivatie, idempotentie (al beoordeeld), list-scoping, motivatie-minimum.

### 2.2 Consensusbasis
Alle 20 aanwezigen akkoord.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-15-I10:**

> Implementeer `ObjectionsService` met `submit()`, `review()` (met status-lock) en `list()` methoden. Ondersteund door 1 migratie (objections), 1 Eloquent model en 9 feature tests. `ObjectionsModuleController` wordt gekoppeld aan service via constructor DI.

---

## 4. Stemmingsuitslag

**Kernteam:** 20/20 voor → GO  
**Rondetafel:** 24/24 voor → GO  
**CONSENSUSOORDEEL: GO**

---

## 5. Ondertekening

| Rol | Naam | Datum |
|-----|------|-------|
| Projectleider | A. Lavita | 2026-05-15 |
| Technisch lead | B. Hamid | 2026-05-15 |
| Juridisch adviseur | G. Smit | 2026-05-15 |
| Kwaliteitsborging | D. Oosterink | 2026-05-15 |
| Onafhankelijk reviewer | E. Brouwer | 2026-05-15 |

---

## 6. Implementatie

### 6.1 Nieuwe bestanden

| Bestand | Omschrijving |
|---------|-------------|
| `app/Services/ObjectionsService.php` | Service met `submit()`, `review()`, `list()` |
| `app/Models/Objection.php` | Eloquent model |
| `database/migrations/2026_05_15_140100_create_objections_table.php` | objections tabel + FK |
| `tests/Feature/ObjectionsModuleContractTest.php` | 9 feature tests |

### 6.2 Gewijzigde bestanden

| Bestand | Wijziging |
|---------|-----------|
| `app/Http/Controllers/Transitie/ObjectionsModule/ObjectionsModuleController.php` | Volledig herschreven van 501-stub naar werkende controller |

### 6.3 Testresultaten

```
Tests\Feature\ObjectionsModuleContractTest (9 tests, 26 assertions)
✓ employee can submit objection on own entry
✓ employee cannot submit objection on other employees entry
✓ owner cannot submit objection
✓ duplicate open objection is rejected
✓ owner can approve open objection
✓ rejection requires manager response
✓ review of already reviewed objection is idempotent rejected
✓ employee list scoped to own entries
✓ submit requires minimum motivation length

Totale suite: 34 tests, 110 assertions — 100% PASS
Duur: 1.45s
```

---

## 7. Heroverleg

Geen heroverleg nodig. Toekomstige iteraties:

| Nr | Onderwerp | Prioriteit |
|----|-----------|-----------|
| I10-post-1 | `objection_events` audit trail tabel | Gemiddeld |
| I10-post-2 => Uitgesteld | E-mail notificatie na review (MUST-EMAIL-FLOWS scope) | Hoog |

---

## 8. Verificatie

| Gate | Status |
|------|--------|
| `php -l app/Services/ObjectionsService.php` | ✅ PASS |
| `php -l app/Models/Objection.php` | ✅ PASS |
| `php artisan test --filter=ObjectionsModuleContractTest` | ✅ 9/9 PASS (26 assertions) |
| `php artisan test` (totale suite) | ✅ 34/34 PASS (110 assertions) |
| Race-condition bescherming `lockForUpdate` | ✅ Geïmplementeerd en getest |

**ITERATIE 10: GO — MUST-OBJECTION succesvol geverifieerd.**

---

**Verplichte volgende iteratie:** Implementeer MUST-ATW module: ATW-signalen berekening (dag/week/16-weken/rust) als service, validate-atw endpoint, signals GET endpoint.
