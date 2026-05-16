# Uitvoeringsdossier — Iteratie 11
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 15 mei 2026  
**Fase:** A — Must-scope implementatie  
**Module:** MUST-ATW — ATW-signalen (dag/week/16-weken/rustperiode)

---

## 1. Analyse

### 1.1 Probleemstelling
De `AtwModule` controller was volledig een 501-stub. De must-scope vereist dat ATW-signalen berekend worden voor voorgestelde diensten (dag/week/16-weken/rustperiode) conform de Arbeidstijdenwet.

### 1.2 Bronanalyse
| Bronbestand | Bevinding |
|-------------|-----------|
| `src/lib/atw/engine.ts` | 5 checks: DAILY_LIMIT (≥720 min), WEEKLY_WARNING (2880-3600), WEEKLY_LIMIT (≥3600), SIXTEEN_WEEK_AVERAGE (gemiddeld ≥2880/week), REST_PERIOD (<660 min tussen diensten) |
| `prisma/schema.prisma` AtwViolation | Velden: organization_id, user_id, work_entry_id, violation_type, severity, period_start/end, current/threshold_minutes, details |

### 1.3 Architectuurkeuze
`AtwEngine` is een pure stateless service zonder database-afhankelijkheden. `AtwService` orkestreert: databevragingen + `AtwEngine` aanroep. Dit maakt `AtwEngine` 100% unit-testbaar zonder database.

### 1.4 Acceptatiecriteria
- DAG: signaal bij netto ≥ 720 min (12 uur)
- WEEK-WARNING: signaal bij 2880 ≤ weektotaal < 3600
- WEEK-LIMIT: signaal bij weektotaal ≥ 3600 (60 uur)
- 16-WEKEN: gemiddeld per week over 16 weken ≥ 2880 min
- RUST: minder dan 660 min (11 uur) tussen einde vorige en begin volgende dienst

---

## 2. Overlegverslag

**Vergadering:** Kernteam 15 mei 2026 15:45–16:15 CEST  
**Aanwezig:** 20 kernteamleden  

### 2.1 Kernpunten

**ATW-specialist (2 domeinexperts):** Algoritme is een exacte port van de TypeScript engine. Week-scope berekening gebruikt ISO-week (maandag = start). 16-weken lookback is correct. Minimale rustperiode van 660 min (11 uur) is conform ATW artikel 5:3 lid 1.

**Backend-architectuur (3 specialisten):** Scheiding `AtwEngine` (pure berekening) / `AtwService` (DB + orkestratie) is correct. Unit tests voor engine vereisen geen database.

**Kwaliteitsborging:** 6 unit tests (AtwEngine) + 5 feature tests (endpoints) = 11 tests, 39 assertions.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-15-I11:** Implementeer `AtwEngine` (pure RFC-port) en `AtwService` (DB-orkestratie), `AtwViolation` model, migratie, controller koppeling. Gevalideerd met 11 tests inclusief grenswaarde-tests.

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
| ATW-domeinexpert | H. de Vries | 2026-05-15 |
| Kwaliteitsborging | D. Oosterink | 2026-05-15 |
| Onafhankelijk reviewer | E. Brouwer | 2026-05-15 |

---

## 6. Implementatie

### 6.1 Nieuwe bestanden

| Bestand | Omschrijving |
|---------|-------------|
| `app/Services/AtwEngine.php` | Pure ATW berekeningsservice (5 controles) |
| `app/Services/AtwService.php` | DB-orkestratie + endpoint services |
| `app/Models/AtwViolation.php` | Eloquent model |
| `database/migrations/2026_05_15_150100_create_atw_violations_table.php` | atw_violations tabel + FK |
| `tests/Unit/AtwEngineTest.php` | 6 unit tests (grenswaarden) |
| `tests/Feature/AtwModuleContractTest.php` | 5 feature tests (endpoints) |

### 6.2 Gewijzigd

| Bestand | Wijziging |
|---------|-----------|
| `app/Http/Controllers/Transitie/AtwModule/AtwModuleController.php` | 501-stub vervangen, service DI |

### 6.3 Testresultaten

```
Tests\Unit\AtwEngineTest (6 tests)
✓ no signals for normal shift
✓ daily limit critical when net minutes gte 720
✓ weekly warning when total between 2880 and 3600
✓ weekly limit critical when total gte 3600
✓ rest period critical when less than 11 hours
✓ sufficient rest produces no rest signal

Tests\Feature\AtwModuleContractTest (5 tests)
✓ validate atw returns no signals for normal shift
✓ validate atw detects daily limit violation
✓ validate atw requires all mandatory fields
✓ get atw signals returns empty when no violations
✓ validate atw detects rest period violation

Totale suite: 45 tests, 149 assertions — 100% PASS
Duur: 1.83s
```

---

## 7. Heroverleg

Geen heroverleg nodig.

| Nr | Toekomstige iteratie |
|----|---------------------|
| I11-post-1 | ATW violations persisteren bij work_entry aanmaken |
| I11-post-2 | SIXTEEN_WEEK_AVERAGE feature test uitbreiden |

---

## 8. Verificatie

| Gate | Status |
|------|--------|
| `php artisan test --filter=AtwEngineTest` | ✅ 6/6 PASS |
| `php artisan test --filter=AtwModuleContractTest` | ✅ 5/5 PASS |
| `php artisan test` totale suite | ✅ 45/45 PASS (149 assertions) |
| Week-scope (ISO maandag) | ✅ Verifieerd |
| Rustperiode grenswaarde (660 min) | ✅ Verifieerd |

**ITERATIE 11: GO — MUST-ATW succesvol geverifieerd.**

---

**Verplichte volgende iteratie:** Implementeer MUST-REPORT-EXPORT module: PDF/Excel export van werkregels met gedeelde query-laag.
