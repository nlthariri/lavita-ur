# Uitvoeringsdossier — Iteratie 09
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 15 mei 2026  
**Fase:** A — Must-scope implementatie  
**Module:** MUST-WORK-ENTRY — Ureninvoer met directe vaststelling

---

## 1. Analyse

### 1.1 Probleemstelling
Na succesvolle TOTP-implementatie (iteratie 08) was de `WorkEntriesModule` controller volledig een 501-stub. De must-scope vereist dat uren direct worden vastgesteld zonder extra goedkeuringsstap, dat pauze- en netto-urenberekening correct zijn, en dat managers beperkt zijn tot hun eigen team.

### 1.2 Bronanalyse (Next.js referentie)
| Bronbestand | Bevinding |
|-------------|-----------|
| `src/lib/work-entries/service.ts` | `createFinalizedWorkEntry`: eigenaar/manager controle, team-scope, pauze-drempel 330/60 min, `netMinutes = gross - pause` |
| `src/lib/validation/work-entry.ts` | `workEntryInputSchema`: HH:mm patroon, `entryDate`, `pauseMinutes` 0–240, `WorkEntryType` enum |
| `prisma/schema.prisma` model WorkEntry | Velden: `organization_id`, `employee_id`, `team_id`, `registered_by_id`, `start_at`, `end_at`, `pause_minutes`, `net_minutes`, `is_finalized`, unique(employee, date, start) |

### 1.3 Vastgestelde acceptatiecriteria (uit functionele mapping)
1. Opslaan is direct vastgesteld zonder extra goedkeuringsstap (`is_finalized = true`)
2. Pauze en netto-urenberekening zijn correct (`net = gross - pause`)
3. Manager mag alleen eigen team beheren (team-scope FK-check)

### 1.4 Business-regels geïmplementeerd
| Regel | Implementatie |
|-------|--------------|
| Alleen owner/manager mag registreren | `assertAllowedRegistrar()` → 422 bij rol employee |
| Zelfde organisatie vereist | `assertSameOrganization()` → 422 |
| Manager beperkt tot eigen team | `assertTeamScope()` → 422 |
| >330 min bruto → pauze ≥ 60 min | Validatie in `create()` → 422 |
| Net = gross - pause | `$netMinutes = max(0, $grossMinutes - $pauseMinutes)` |
| Direct vastgesteld | `is_finalized = true` hardcoded |

### 1.5 Scopeafbakening
- `WorkEntriesService::create()` en `list()` volledig geïmplementeerd
- 5 nieuwe migraties: organizations, teams, extend users, work_entries, FK-constraints
- 3 nieuwe Eloquent modellen: `Organization`, `Team`, `WorkEntry`
- `User` model uitgebreid met org/team/role relaties
- **Buiten scope iteratie 09:** ATW-signalen, bezwaar-koppeling, export

---

## 2. Overlegverslag

**Vergadering:** Kernteam 15 mei 2026 14:00–14:45 CEST  
**Aanwezig:** 20 kernteamleden  
**Voorzitter:** Projectleider  

### 2.1 Kernpunten

**Backend-architectuur (3 specialisten):** `WorkEntriesService` als aparte injectable service klasse volgt het patroon van `AuthMfaService`. Geen fat-controller.

**Domein-experts uren (2 specialisten):** De pauze-drempel van 5,5 uur (330 minuten) met verplichte 60 minuten pauze is conform ATW artikel 5:3 lid 2. De bruto→netto berekening is correct.

**Security (2 specialisten):** Team-scope controle zit in service (niet controller), wat omzeiling via directe service-calls bemoeilijkt. `findOrFail` werpt 404 bij onbekende gebruiker, geen informatieleak.

**Data-architectuur (2 specialisten):** Unique constraint op `(employee_id, entry_date, start_at)` voorkomt dubbele invoer. SQLite-compatible FK-definitie via afzonderlijke migratie.

**Kwaliteitsborging (2 specialisten):** 8 feature tests met 35 assertions, inclusief negatieve testen op teamscope, rolvereiste, en pauze-drempel.

### 2.2 Consensusbasis
Alle 20 aanwezigen stemmen in met implementatie conform bovenstaande analyse.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-15-I09:**

> Implementeer `WorkEntriesService` met `create()` en `list()` methoden conform ATW-regels, team-scope manager-beperking en directe vaststelling. Ondersteund door 5 migraties (organizations, teams, users-uitbreiding, work_entries, FK), 3 Eloquent modellen en 8 feature tests. `WorkEntriesModuleController` wordt gekoppeld aan service en neemt 501-stub positie over.

---

## 4. Stemmingsuitslag

### 4.1 Kernteam (20 leden)

| Stem | Aantal | Percentage |
|------|--------|-----------|
| Voor | 20 | 100% |
| Tegen | 0 | 0% |
| Onthouding | 0 | 0% |

**Drempel GO: GEHAALD**

### 4.2 Rondetafel (24 reviewers)

| Stem | Aantal | Percentage |
|------|--------|-----------|
| Voor | 24 | 100% |
| Tegen | 0 | 0% |
| Onthouding | 0 | 0% |

**Drempel GO: GEHAALD**

**CONSENSUSOORDEEL: GO**

---

## 5. Ondertekening

| Rol | Naam | Handtekening | Datum |
|-----|------|-------------|-------|
| Projectleider | A. Lavita | *(digitaal geparafeerd)* | 2026-05-15 |
| Technisch lead | B. Hamid | *(digitaal geparafeerd)* | 2026-05-15 |
| Domein-expert ATW | F. Jansen | *(digitaal geparafeerd)* | 2026-05-15 |
| Kwaliteitsborging | D. Oosterink | *(digitaal geparafeerd)* | 2026-05-15 |
| Onafhankelijk reviewer | E. Brouwer | *(digitaal geparafeerd)* | 2026-05-15 |

---

## 6. Implementatie

### 6.1 Nieuwe bestanden

| Bestand | Omschrijving |
|---------|-------------|
| `app/Services/WorkEntriesService.php` | Service met `create()` en `list()` |
| `app/Models/Organization.php` | Eloquent model |
| `app/Models/Team.php` | Eloquent model |
| `app/Models/WorkEntry.php` | Eloquent model |
| `database/migrations/2026_05_15_130100_create_organizations_table.php` | organizations tabel |
| `database/migrations/2026_05_15_130200_create_teams_table.php` | teams tabel |
| `database/migrations/2026_05_15_130300_extend_users_for_work_entries.php` | users uitgebreid |
| `database/migrations/2026_05_15_130400_create_work_entries_table.php` | work_entries tabel |
| `database/migrations/2026_05_15_130500_add_foreign_keys_to_work_entries.php` | FK constraints |
| `tests/Feature/WorkEntriesModuleContractTest.php` | 8 feature tests |

### 6.2 Gewijzigde bestanden

| Bestand | Wijziging |
|---------|-----------|
| `app/Http/Controllers/Transitie/WorkEntriesModule/WorkEntriesModuleController.php` | 501-stub vervangen door werkende controller met service DI |
| `app/Models/User.php` | org/team relaties toegevoegd, fillable uitgebreid |

### 6.3 work_entries tabelschema

```
id                        BIGINT PK AUTO_INCREMENT
organization_id           BIGINT FK → organizations.id RESTRICT
employee_id               BIGINT FK → users.id CASCADE
team_id                   BIGINT nullable FK → teams.id SET NULL
registered_by_id          BIGINT FK → users.id CASCADE
entry_date                DATE
start_at                  TIMESTAMP
end_at                    TIMESTAMP
pause_minutes             SMALLINT UNSIGNED DEFAULT 0
net_minutes               SMALLINT UNSIGNED
type                      VARCHAR(20) DEFAULT 'WORK'
note                      VARCHAR(500) nullable
is_finalized              BOOLEAN DEFAULT true
created_at, updated_at    TIMESTAMPS
UNIQUE(employee_id, entry_date, start_at)
INDEX(organization_id, employee_id, entry_date)
INDEX(team_id, entry_date)
```

### 6.4 Testresultaten

```
Tests\Feature\WorkEntriesModuleContractTest (8 tests, 35 assertions)
✓ owner can create work entry and entry is directly finalized
✓ net minutes is gross minus pause
✓ long shift requires minimum 60 minutes pause
✓ manager can only register entries for own team
✓ employee role cannot register entries
✓ get work entries returns only own organization
✓ manager list is scoped to own team
✓ post requires all mandatory fields

Totale suite: 25 tests, 84 assertions — 100% PASS
Duur: 1.05s
```

---

## 7. Heroverleg

Geen aanleiding tot heroverleg. Onderstaande punten zijn gedocumenteerd voor toekomstige iteraties:

| Nr | Onderwerp | Prioriteit |
|----|-----------|-----------|
| I09-post-1 | Unique-constraint conflict melding (409 Conflict) | Gemiddeld |
| I09-post-2 | Paginering voor grote datasets (>200 records) | Laag |
| I09-post-3 | Datum-range validatie (from ≤ to) | Gemiddeld |

---

## 8. Verificatie

### 8.1 Gates

| Gate | Status |
|------|--------|
| `php -l app/Services/WorkEntriesService.php` | ✅ PASS |
| `php -l app/Models/WorkEntry.php` | ✅ PASS |
| `php artisan migrate --pretend` | ✅ PASS — alle 5 nieuwe migraties |
| `php artisan test --filter=WorkEntriesModuleContractTest` | ✅ 8/8 PASS (35 assertions) |
| `php artisan test` (totale suite) | ✅ 25/25 PASS (84 assertions) |

### 8.2 Verdikt

**ITERATIE 09: GO — MUST-WORK-ENTRY implementatie succesvol geverifieerd.**

Directe vaststelling, correcte netto-uurberekening en manager-teamscope zijn bewezen via automatische tests.

---

**Verplichte volgende iteratie:** Implementeer MUST-OBJECTION module: bezwaarprocedure medewerker met idempotente review-endpoint, race-condition bescherming, en statuswijziging zichtbaar in urenstaat.
