# Implementation Plan — LaVita Urenregistratie

## Overzicht

Dit plan zet het ontwerp van `design.md` om in een serie code-genererende taken voor een spec-task-execution subagent. Elke taak bouwt voort op de vorige en eindigt met integratie in eerder gemaakte componenten. Geen losse code: alle wiring is onderdeel van een taak. Volgorde volgt MoSCoW-prioriteit: 1A → 1F (MUST), 2A → 2E (SHOULD/COULD), 3A/3B (infrastructuur), 4A → 4D (documenten).

Taken gemarkeerd met `*` zijn optioneel (test-subtaken). Top-level taken zijn nooit optioneel. Iedere taak verwijst naar specifieke acceptance criteria met `_Requirements: X.Y_`.

## Tasks

- [x] 1. Voorbereiding migraties en testharnas
  - [x] 1.1 Voeg migratie toe `add_project_costcenter_softdelete_to_work_entries` met kolommen `project_id`, `cost_center_id`, `deleted_at` plus indexen en FKs (placeholder FKs commented out totdat 2.1 draait).
    - _Requirements: 1.7, 2.3_
  - [x] 1.2 Configureer Pest v3 + `pestphp/pest-plugin-properties` in `composer.json` require-dev en publiceer Pest config.
    - Voeg PHP-CS-Fixer/Pint pre-commit toe en `tests/Pest.php` test-bootstrap met `RefreshDatabase`-trait alias.
    - _Requirements: NFR-1 .. NFR-10_
  - [ ]* 1.3 Schrijf migratie-smoke-test die `migrate:fresh` en `migrate:rollback` cycle doorloopt zonder fouten.
    - _Requirements: 2.1, 2.2, 7.4_

- [x] 2. Project- en kostenplaatsmodule (1B — MUST)
  - [x] 2.1 Maak migraties `create_projects_table` en `create_cost_centers_table` met velden uit Data Models en activeer FKs op `work_entries.project_id` / `cost_center_id`.
    - _Requirements: 2.1, 2.2, 2.3_
  - [x] 2.2 Maak modellen `app/Models/Project.php` en `app/Models/CostCenter.php` met `belongsTo(Organization)` en `hasMany(WorkEntry)` relaties + `Fillable` attributes.
    - Voeg `softDeletes` cast op `archived_at` via custom scope (geen Laravel `SoftDeletes` trait omdat kolomnaam afwijkt).
    - _Requirements: 2.1, 2.2_
  - [x] 2.3 Maak `app/Services/ProjectsService.php` met methodes `create, update, archive, list, find` inclusief organisatie-scope-checks en uniek-code-validatie binnen org.
    - _Requirements: 2.4, 2.5, 2.7, 2.9, 2.10_
  - [x] 2.4 Maak `app/Services/CostCentersService.php` analoog aan ProjectsService.
    - _Requirements: 2.6, 2.7, 2.9, 2.10_
  - [x] 2.5 Maak `app/Http/Controllers/Transitie/ProjectsModule/ProjectsModuleController.php` met handlers voor `GET/POST/PATCH/DELETE /projects(/{id})` en gebruik `$request->validate(...)` voor `code (uniek), name, description?, hourly_rate?`.
    - _Requirements: 2.4, 2.5, 2.7_
  - [x] 2.6 Maak `app/Http/Controllers/Transitie/CostCentersModule/CostCentersModuleController.php` analoog aan ProjectsModuleController.
    - _Requirements: 2.6, 2.7_
  - [x] 2.7 Registreer routes in `routes/api.php` onder de bestaande `internal.auth + throttle:api` middleware-groep.
    - _Requirements: 2.4, 2.5, 2.6_
  - [x] 2.8 Breid `WorkEntriesService::create` en `update` uit met `project_id` en `cost_center_id` validatie (org-mismatch + inactive checks) en update SQL.
    - _Requirements: 2.9, 2.10, 1.4_
  - [ ]* 2.9 Schrijf feature-tests voor projects-CRUD en cost-centers-CRUD: happy path + 403 voor employee/manager/boekhouder + 422 PROJECT_ORG_MISMATCH/PROJECT_INACTIVE.
    - _Requirements: 2.4, 2.5, 2.7, 2.9, 2.10_

- [x] 3. Boekhouder-rol read-only middleware (1C — MUST)
  - [x] 3.1 Maak middleware `app/Http/Middleware/BookkeeperReadonly.php` die HTTP-methode check doet en `READ_ONLY_ROLE` 403 retourneert voor niet-GET requests met rol `boekhouder`.
    - _Requirements: 3.3, 3.4, 3.5, 3.6_
  - [x] 3.2 Registreer middleware-alias `bookkeeper.readonly` in `bootstrap/app.php` (Laravel 13 stijl).
    - _Requirements: 3.3_
  - [x] 3.3 Voeg `bookkeeper.readonly` toe aan de internal-auth middleware-groep in `routes/api.php` zodat alle write-routes worden afgedekt.
    - _Requirements: 3.3, 3.4, 3.5, 3.6_
  - [x] 3.4 Verwijder de inline check op rol `boekhouder` uit `WorkEntriesModuleController::postInternalWorkEntries` (verplaatst naar middleware).
    - _Requirements: 3.4_
  - [ ]* 3.5 Schrijf property-test (Property 3): voor elke methode `m ∈ {POST,PUT,PATCH,DELETE}` op een willekeurige internal-route met rol boekhouder ⇒ 403 READ_ONLY_ROLE.
    - **Property 3: Boekhouder is read-only over alle non-GET methodes**
    - **Validates: Requirements 3.3, 3.4, 3.5, 3.6**

- [x] 4. CRUD volledig op werkregels (1A — MUST)
  - [x] 4.1 Breid `WorkEntriesService` uit met `update(int $id, array $input, int $registrarId): array` en `delete(int $id, int $registrarId): void`.
    - Implementeer team-/owner-scope-checks identiek aan `create`.
    - Implementeer `OBJECTION_OPEN` 409 wanneer een actief bezwaar bestaat.
    - Recompute `net_minutes` na update; soft-delete via `deleted_at`.
    - Schrijf audit-events `WORK_ENTRY_UPDATED` / `WORK_ENTRY_DELETED` via `AuditService`.
    - _Requirements: 1.4, 1.7, 1.8, 1.9_
  - [x] 4.2 Voeg `find(int $id, int $requesterId)` toe met team-/owner-scope-checks die respectievelijk `FORBIDDEN_TEAM_SCOPE` en `FORBIDDEN_OWNER_SCOPE` 403 retourneren.
    - _Requirements: 1.1, 1.2, 1.3_
  - [x] 4.3 Breid `WorkEntriesModuleController` uit met `getInternalWorkEntryById`, `patchInternalWorkEntryById`, `deleteInternalWorkEntryById` en valideer body identiek aan POST plus `project_id?, cost_center_id?` velden.
    - _Requirements: 1.1, 1.4, 1.5, 1.6, 1.7_
  - [x] 4.4 Registreer routes `GET/PATCH/DELETE /internal/work-entries/{id}` in `routes/api.php`.
    - _Requirements: 1.1, 1.4, 1.7_
  - [x] 4.5 Voeg dispatch toe van `work_entry_updated` en `work_entry_deleted` mail via `EmailOutboxService` na succesvolle update/delete.
    - _Requirements: 1.9_
  - [x] 4.6 Markeer gerelateerde `atw_violations` als `superseded` (nieuwe kolom `superseded_at`) bij DELETE.
    - Migratie: `ALTER TABLE atw_violations ADD COLUMN superseded_at TIMESTAMP NULL`.
    - _Requirements: 1.7_
  - [ ]* 4.7 Schrijf property-test (Property 1): netto-minuten = max(0, (end-start)-pause) over willekeurige geldige inputs.
    - **Property 1: Netto-minuten-berekening klopt**
    - **Validates: Requirements 1.4**
  - [ ]* 4.8 Schrijf feature-tests voor GET/PATCH/DELETE happy path, 403-cases, 409 OBJECTION_OPEN.
    - _Requirements: 1.1, 1.2, 1.3, 1.5, 1.6, 1.7, 1.8_

- [x] 5. ATW ontbrekende validaties (1D — MUST)
  - [x] 5.1 Wijzig `WorkEntriesService::MIN_PAUSE_FOR_LONG_SHIFT_MINUTES` naar 30 (i.p.v. 60) en threshold blijft 330 min.
    - Werp `ValidationException` met `code = 'ATW_PAUSE_REQUIRED'` (gebruik `withMessages` + meta).
    - _Requirements: 4.1_
  - [x] 5.2 Breid `AtwEngine::evaluate` uit met `PAUSE_REQUIRED` signal-type bij `gross > 330 && pause < 30`.
    - _Requirements: 4.1, 4.9_
  - [x] 5.3 Maak `AtwService::throwOnCriticalSignals(array $signals)` helper die HTTP 422 met juiste code (`ATW_DAILY_MAX_EXCEEDED`, `ATW_WEEKLY_MAX_EXCEEDED`, `ATW_REST_PERIOD_VIOLATED`, `ATW_PAUSE_REQUIRED`) gooit; warnings (`WEEKLY_WARNING`) en `SIXTEEN_WEEK_AVERAGE` blijven non-blocking.
    - _Requirements: 4.2, 4.3, 4.4, 4.5, 4.6, 4.9_
  - [x] 5.4 Roep `throwOnCriticalSignals` aan in `WorkEntriesService::create` en `update` na bestaande pauze-check; pas error-response-formaat toe `{ error, code, errors }`.
    - _Requirements: 4.1, 4.3, 4.4, 4.5_
  - [x] 5.5 Schrijf audit-event `ATW_VIOLATION_BLOCKED` bij elke 422 met type, current/threshold minutes en actor/employee ids.
    - _Requirements: 4.7_
  - [x] 5.6 Update `AtwModuleController::postInternalWorkEntriesValidateAtw` zodat de response-structuur de codes uit 5.3 reflecteert (`signals[].code`).
    - _Requirements: 4.8, 4.9_
  - [ ]* 5.7 Schrijf property-test (Property 4): pauze-plicht blokkeert.
    - **Property 4: ATW-pauzeplicht wordt afgedwongen**
    - **Validates: Requirements 4.1**
  - [ ]* 5.8 Schrijf property-test (Property 5): ≥60u/week blokkeert.
    - **Property 5: 60u-weekgrens blokkeert hard**
    - **Validates: Requirements 4.3**
  - [ ]* 5.9 Schrijf property-test (Property 6): AtwEngine-evaluate produceert juiste signals.
    - **Property 6: ATW-engine produceert juiste signals per drempel**
    - **Validates: Requirements 4.2, 4.4, 4.5, 4.6, 4.9**
  - [ ]* 5.10 Schrijf property-test (Property 7): validate-atw consistent met POST/PATCH.
    - **Property 7: Validate-ATW en POST/PATCH zijn consistent**
    - **Validates: Requirements 4.8**

- [x] 6. Welkomstmail bij accountcreatie (1E — MUST)
  - [x] 6.1 Voeg `email_templates`-seed toe met type `welcome_email` en NL-default body (subject "Welkom bij LaVita Urenregistratie") plus alle placeholders.
    - _Requirements: 5.1, 5.3, 5.7_
  - [x] 6.2 Refactor `AccountProvisioningService::create` zodat het `EmailTemplateService::render('welcome_email', $vars)` aanroept i.p.v. de inline body.
    - Wrap dispatch in dezelfde DB-transactie; bij outbox-fout `throw RuntimeException` met code `WELCOME_EMAIL_FAILED` en HTTP 500-handling in controller.
    - _Requirements: 5.1, 5.4, 5.5, 5.6_
  - [x] 6.3 Update `EmailFlowsModuleController::putInternalEmailTemplate` zodat `welcome_email` als geldige `type` wordt geaccepteerd (tabel `email_templates` allowed-types lijst uitbreiden).
    - _Requirements: 5.2_
  - [ ]* 6.4 Schrijf property-test (Property 8): welkomstmail-rendering compleet en geen wachtwoord-leak.
    - **Property 8: Welkomstmail render is volledig en lekt geen wachtwoord**
    - **Validates: Requirements 5.1, 5.3, 5.5**
  - [ ]* 6.5 Schrijf feature-test: nieuwe boekhouder zonder team_id krijgt welcome-mail zonder team-mention en zonder 422.
    - _Requirements: 5.6_

- [x] 7. Checkpoint — alle backend-MUST-features
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Frontend basis: Tailwind, design tokens, layout (1F — MUST)
  - [x] 8.1 Installeer en configureer Tailwind CSS 3, Inter, Geist Mono via `package.json` en `vite.config.js`; voeg `resources/css/app.css` met `@tailwind base/components/utilities`.
    - _Requirements: 6.13, NFR-3, NFR-4_
  - [x] 8.2 Maak `tailwind.config.js` met design tokens uit Components and Interfaces (kleuren, radii, fonts, breakpoints 1280/768).
    - _Requirements: 6.13, NFR-4_
  - [x] 8.3 Installeer Livewire 3 (`composer require livewire/livewire`) en publiceer assets.
    - _Requirements: NFR-2, NFR-4_
  - [x] 8.4 Maak `resources/views/layouts/app.blade.php` met sidebar 240 / content 720 / TOC 200 grid + skip-to-main link voor WCAG, alle landmarks (header/nav/main/footer).
    - _Requirements: 6.13, NFR-1_
  - [x] 8.5 Maak shared Livewire-components `Ui\Button`, `Ui\Card`, `Ui\TextInput`, `Ui\StatusBadge` met design tokens en focus-state `border 2px #00d4a4`.
    - _Requirements: 6.13, NFR-1, NFR-4_

- [x] 9. Frontend authenticatie + MFA (1F — MUST)
  - [x] 9.1 Maak Livewire `Auth\LoginForm` op `/inloggen` met form (email, password), inline error rendering, NL-labels, ARIA-attributen.
    - _Requirements: 6.1, 6.13, 6.14_
  - [x] 9.2 Maak Livewire `Auth\MfaVerifyForm` voor 6-cijferige TOTP-stap inclusief autofocus, throttle-feedback.
    - _Requirements: 6.1, 6.13_
  - [x] 9.3 Maak Livewire `Auth\MfaSetupQr` die secret + QR-data-URL (via `endroid/qr-code`) en 8 recovery codes toont; kopieer-knoppen met `aria-live`.
    - Voeg dependency `composer require endroid/qr-code` toe.
    - _Requirements: 6.1, 6.13_
  - [x] 9.4 Maak Livewire `Auth\PasswordForgotForm` op `/wachtwoord-vergeten` en `Auth\PasswordResetForm` op `/wachtwoord-reset` met sterkte-indicator (min 12 + mix).
    - _Requirements: 6.11, 6.13_
  - [x] 9.5 Registreer web-routes in `routes/web.php` voor alle auth-schermen (CSRF + sessie).
    - _Requirements: 6.1, 6.11_

- [x] 10. Frontend uren-modules (1F — MUST)
  - [x] 10.1 Maak Livewire `Hours\WeekOverviewTable` op `/uren/week` met tabel rijen=medewerkers, kolommen ma-zo, status-badges via `Ui\StatusBadge`, manager-team-scope-filter.
    - _Requirements: 6.2, 6.13_
  - [x] 10.2 Maak Livewire `Hours\EntryFormModal` met live `wire:model.live` op start/end/pauze die `getNetMinutes()` berekent en `POST /api/internal/work-entries/validate-atw` aanroept voor warnings/critical-melding vóór opslaan.
    - Voeg project- en kostenplaats-`select` met data uit `ProjectsService::list`.
    - _Requirements: 6.3, 6.13_
  - [x] 10.3 Maak Livewire `Hours\MyWeek` op `/uren/mijn-week` voor employees: eigen weekoverzicht + bezwaarknop per regel (modal `Objections\NewObjectionForm`).
    - _Requirements: 6.4, 6.13_
  - [x] 10.4 Maak Livewire `Hours\LeaveForm` op `/verlof` met type-select `{SICK, LEAVE, HOLIDAY}` (HOLIDAY alleen voor manager/owner), datum-range-picker, verplichte motivatie voor employee.
    - _Requirements: 6.10, 7.1, 7.2, 6.13_

- [x] 11. Frontend ATW-dashboard, bezwaren, dashboards (1F — MUST)
  - [x] 11.1 Maak Livewire `Atw\StatusDashboard` op `/atw` met grid: rijen=medewerkers, kolommen=DAILY_LIMIT/WEEKLY/16W/REST/PAUSE, kleurcodering via `Ui\StatusBadge`-varianten (groen/geel/rood) gebaseerd op `AtwService::getSignalsForUser`.
    - _Requirements: 6.5, 6.13_
  - [x] 11.2 Maak Livewire `Objections\ReviewForm` op `/bezwaren/{id}` met `motivation`-textarea (min:10, max:1000), submit-knop disabled bij <10 tekens, en `accept`/`reject`-knoppen.
    - _Requirements: 6.6, 6.13_
  - [x] 11.3 Maak Livewire `Dashboard\ManagerHome` op `/dashboard` met cards: aanwezigheid huidige week, openstaande bezwaren teller, ATW-warnings teller, snelkoppelingen.
    - _Requirements: 6.9, 6.13_

- [x] 12. Frontend rapportages, accountbeheer, e-mailtemplates (1F — MUST)
  - [x] 12.1 Maak Livewire `Reports\Filters` op `/rapportages` met filters medewerker/team/project/kostenplaats/periode + download-knoppen PDF/Excel die naar `/api/internal/reports/work-entries/{pdf,excel}` POST'en.
    - _Requirements: 6.7, 6.13_
  - [x] 12.2 Maak Livewire `Reports\YearExport` tab voor fiscale jaarexport (call naar nieuwe endpoint `GET /reports/year-export?year=&employee_id=`).
    - Voeg `ReportsModuleController::getYearExport` + `ReportQueryService::yearExport` toe met PDF-output via `barryvdh/laravel-dompdf`.
    - _Requirements: 6.7, 14.5_
  - [x] 12.3 Maak Livewire `Accounts\List` op `/accounts` met tabel + search; `Accounts\Form` voor create/edit; activeren/deactiveren toggle; soft-delete-knop alleen voor owner.
    - _Requirements: 6.8, 6.13, 10.1_
  - [x] 12.4 Maak Livewire `Settings\EmailTemplates` op `/instellingen/email` met lijst van 11 types, inline-editor met monospace, opslaan via `PUT /api/internal/email/templates/{type}`.
    - _Requirements: 6.12, 6.13_
  - [ ]* 12.5 Schrijf Livewire-feature-tests per scherm: render + één interactie + autorisatie-403.
    - _Requirements: 6.1 .. 6.14_
  - [ ]* 12.6 Schrijf axe-core/integratie-test op de 12 schermen (Pest browser plugin) voor WCAG 2.1 AA.
    - _Requirements: 6.13, NFR-1_

- [x] 13. Checkpoint — frontend MUST compleet
  - Ensure all tests pass, ask the user if questions arise.

- [x] 14. Verlof, ziekte en feestdagen (2A — SHOULD)
  - [x] 14.1 Maak migratie `create_holidays_table` met kolommen uit Data Models en uniek-index `(year, date)`.
    - _Requirements: 7.4_
  - [x] 14.2 Maak `app/Models/Holiday.php` met fillable + scopes `forYear`.
    - _Requirements: 7.4, 7.6_
  - [x] 14.3 Maak `app/Services/HolidaysService.php` met methode `computeNlHolidaysForYear(int $year): array` die Pasen via Gauss berekent en alle 11 nationale feestdagen retourneert (zie Property 10).
    - _Requirements: 7.5_
  - [x] 14.4 Maak `app/Console/Commands/ImportHolidaysCommand.php` met signature `holidays:import {year}` die `HolidaysService::computeNlHolidaysForYear` aanroept en `upsert`'t op `(year, date)`.
    - Registreer in `app/Console/Kernel.php` of via `bootstrap/app.php` (Laravel 13).
    - _Requirements: 7.5_
  - [x] 14.5 Maak `app/Http/Controllers/Transitie/HolidaysModule/HolidaysModuleController.php::getInternalHolidays(?year=)` en registreer route.
    - _Requirements: 7.6_
  - [x] 14.6 Update `WorkEntriesService::create/update`: bij `type ∈ {SICK, LEAVE, HOLIDAY}` → `start_time/end_time` optioneel (default 00:00/23:59), `pause_minutes = 0`; bij employee + `type=HOLIDAY` → 422 `INVALID_TYPE_FOR_ROLE`; bij `type ∈ {SICK, LEAVE}` voor employee → `note` verplicht.
    - _Requirements: 7.1, 7.2, 7.3_
  - [x] 14.7 Update `AtwEngine::evaluate` zodat existing shifts met `type ∈ {SICK, LEAVE, HOLIDAY, OTHER}` 0 minuten bijdragen aan `weeklyMinutes`/`total16Weeks`.
    - _Requirements: 7.8_
  - [x] 14.8 Update `Hours\WeekOverviewTable` om feestdagen als grijze cel met tooltip te tonen via `HolidaysService::forYear` lookup.
    - _Requirements: 7.7_
  - [ ]* 14.9 Schrijf property-test (Property 10): holidays:import correct voor jaren [1900..2099].
    - **Property 10: Holidays-import berekent NL-feestdagen correct**
    - **Validates: Requirements 7.5**
  - [ ]* 14.10 Schrijf property-test (Property 9): SICK/LEAVE/HOLIDAY tellen niet mee in ATW.
    - **Property 9: SICK/LEAVE/HOLIDAY tellen niet mee in ATW-werktijd**
    - **Validates: Requirements 7.8**

- [x] 15. Herhaalfunctie copy-week (2B — SHOULD)
  - [x] 15.1 Maak `app/Services/CopyWeekService.php::copyWeek(int $employeeId, string $sourceMon, string $targetMon, int $registrarId): array` met validatie van rolen, organisatiescope en maandagcheck.
    - Implementeer iteratie over bron-WORK-entries; per entry try `WorkEntriesService::create` en vang `ValidationException` om `skipped[]` met juiste reden te vullen.
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_
  - [x] 15.2 Voeg endpoint `POST /api/internal/work-entries/copy-week` toe in `WorkEntriesModuleController::postCopyWeek` en `routes/api.php`.
    - _Requirements: 8.1, 8.6_
  - [ ]* 15.3 Schrijf property-test (Property 11): created entries verschoven met 7 dagen, telling klopt.
    - **Property 11: Copy-week verschuift entries 7 dagen voorwaarts**
    - **Validates: Requirements 8.1**
  - [ ]* 15.4 Schrijf property-test (Property 12): conflict + ATW-blok in skipped[].
    - **Property 12: Copy-week conflicts en ATW-blokkades verschijnen in skipped[]**
    - **Validates: Requirements 8.3, 8.4**

- [x] 16. Openstaande-invoer herinnering (2C — SHOULD)
  - [x] 16.1 Voeg migratie `add_email_reminders_opt_in_to_users` toe (BOOLEAN DEFAULT TRUE).
    - _Requirements: 9.1_
  - [x] 16.2 Voeg migratie `add_pending_input_threshold_days_to_organizations` toe (TINYINT DEFAULT 3, CHECK 1..14).
    - _Requirements: 9.2_
  - [x] 16.3 Update `User`-model met `email_reminders_opt_in` in `Fillable`.
    - _Requirements: 9.1_
  - [x] 16.4 Breid `PendingInputReminderService` uit met (a) opt-out check, (b) threshold uit `organization.pending_input_threshold_days`, (c) ma-vr werkdag-skip op `WORK`-entries.
    - _Requirements: 9.4, 9.5_
  - [x] 16.5 Voeg scheduler-entry toe in `bootstrap/app.php` (`->dailyAt('08:00')->timezone('Europe/Amsterdam')`).
    - _Requirements: 9.3_
  - [x] 16.6 Voeg toggle "E-mail herinneringen ontvangen" toe in `Accounts\Form` (Livewire) gekoppeld aan `email_reminders_opt_in`.
    - _Requirements: 9.6_
  - [ ]* 16.7 Schrijf property-test (Property 13): reminder-tellingen kloppen, opt-out blokkeert.
    - **Property 13: Pending-input-reminder respecteert opt-out en threshold**
    - **Validates: Requirements 9.4, 9.5**

- [x] 17. AVG endpoints (2D — MUST)
  - [x] 17.1 Maak migratie `add_soft_delete_to_users` met `deleted_at`, `deleted_by_id`.
    - _Requirements: 10.1_
  - [x] 17.2 Maak `app/Services/DataExportService.php::exportFor(int $userId, int $requesterId): array` die alle gerelateerde data verzamelt en als array retourneert; gooi `FORBIDDEN_DATA_EXPORT` 403 als noch self noch owner.
    - _Requirements: 10.3, 10.4_
  - [x] 17.3 Breid `RetentionService` uit met `pseudonymize(int $userId, int $actorId): void` die name/full_name/email/phone overschrijft, `is_active=false` zet, `deleted_at=now()` zet, en `email_index_hash` bijwerkt.
    - Werp 409 `OPEN_OBJECTIONS` als er open bezwaren zijn.
    - Schrijf audit-event `ACCOUNT_PSEUDONYMIZED`.
    - _Requirements: 10.1, 10.2, 10.6, 10.7_
  - [x] 17.4 Maak `app/Http/Controllers/Transitie/AccountsModule/AccountsModuleController.php` met `deleteInternalAccount`, `getInternalAccountDataExport`, registreer routes `DELETE /api/internal/accounts/{id}` en `GET /api/internal/accounts/{id}/data-export`.
    - _Requirements: 10.1, 10.3, 10.4, 10.7_
  - [x] 17.5 Breid `RunRetentionCommand` uit met maandelijkse pseudonimisering: voor users met `deleted_at < now()-7y` → wis `employment_start/end`; voor `audit_events.created_at < now()-7y` → set `actor_id = null`.
    - Voeg scheduler-entry toe (`->monthlyOn(1, '03:00')->timezone('Europe/Amsterdam')`).
    - _Requirements: 10.5_
  - [ ]* 17.6 Schrijf property-test (Property 14): pseudonimisering behoudt urenintegriteit.
    - **Property 14: Pseudonimisering behoudt urenintegriteit**
    - **Validates: Requirements 10.1, 10.2**
  - [ ]* 17.7 Schrijf property-test (Property 15): data-export bevat alle data.
    - **Property 15: Data-export bevat alle gegevens van de gebruiker**
    - **Validates: Requirements 10.3**

- [x] 18. Jubileumnotificaties (2E — COULD)
  - [x] 18.1 Maak `app/Services/AnniversaryNotificationService.php::dispatchForDate(Carbon $today): array` die users matcht op `(month==today.month, day==today.day, year_diff ∈ {1,5,10,25}, is_active=true)`.
    - Roep `EmailOutboxService::dispatch` aan voor employee + manager(s); audit-event `ANNIVERSARY_DISPATCHED`.
    - _Requirements: 11.2, 11.3, 11.5, 11.6_
  - [x] 18.2 Maak `app/Console/Commands/RunAnniversaryNotificationCommand.php` met signature `notifications:anniversary`.
    - Voeg scheduler-entry toe (`->dailyAt('06:00')->timezone('Europe/Amsterdam')`).
    - _Requirements: 11.1, 11.2_
  - [x] 18.3 Voeg `email_templates`-seed toe voor type `anniversary` met placeholders en bewerkbaar via PUT.
    - _Requirements: 11.4_
  - [ ]* 18.4 Schrijf property-test (Property 16): jubileum-detectie correct.
    - **Property 16: Jubileumdetectie voor jaren {1,5,10,25}**
    - **Validates: Requirements 11.2, 11.3**

- [x] 19. Checkpoint — alle SHOULD/COULD-features
  - Ensure all tests pass, ask the user if questions arise.

- [x] 20. TLS, encryptie en at-rest (3A — MUST)
  - [x] 20.1 Maak migratie `add_email_index_hash_phone_to_users` met `email_index_hash CHAR(64) NULL UNIQUE` en `phone VARCHAR(40) NULL`.
    - _Requirements: 12.2_
  - [x] 20.2 Wijzig `User`-model:
    - Voeg `protected function casts()` uit met `'full_name' => 'encrypted'`, `'email' => 'encrypted'`, `'phone' => 'encrypted'`.
    - Voeg model-event `saving` om `email_index_hash = hash('sha256', strtolower((string)$user->email))` te zetten.
    - Pas alle `User::where('email', ...)` queries in services (`AuthMfaService`, login, password-reset) aan naar `User::where('email_index_hash', hash('sha256', strtolower($email)))`.
    - _Requirements: 12.1, 12.2_
  - [x] 20.3 Maak data-migratie `backfill_email_index_hash` die voor alle bestaande users `email_index_hash` vult.
    - _Requirements: 12.2_
  - [x] 20.4 Voeg `Strict-Transport-Security`-middleware toe (`HstsMiddleware`) met `max-age=31536000; includeSubDomains; preload`; registreer in global middleware-stack.
    - _Requirements: 12.6_
  - [x] 20.5 Voeg `RedirectIfNotSecure`-middleware toe die HTTP-requests in productie 308-redirect naar HTTPS.
    - _Requirements: 12.5_
  - [x] 20.6 Documenteer in `docs/technical/infrastructuur.md` de Plesk/Cloud86-stappen: TLS 1.3 cipher suite, OCSP stapling, HTTP→HTTPS redirect-rule, en MySQL data-dir op LUKS.
    - _Requirements: 12.3, 12.4_
  - [x] 20.7 Update `.env.example` met `APP_KEY=` placeholder en `APP_PREVIOUS_KEYS=` documentatie-comment.
    - _Requirements: 12.7_
  - [ ]* 20.8 Schrijf property-test (Property 17): encryptie-roundtrip + index-hash deterministisch.
    - **Property 17: Encryptie-roundtrip op users.email/full_name/phone**
    - **Validates: Requirements 12.1, 12.2**

- [x] 21. Backup, integriteit en retentie (3B — MUST)
  - [x] 21.1 Voeg `composer require spatie/laravel-backup` toe en publiceer `config/backup.php`.
    - Configureer source-tabellen + `storage/app/private` directory; encryption `aes-256-cbc` met `BACKUP_ARCHIVE_PASSWORD` env.
    - _Requirements: 13.1, 13.2_
  - [x] 21.2 Voeg scheduler-entries toe in `bootstrap/app.php`: `backup:run` dagelijks 02:00, `backup:clean` retention 30 dagen.
    - _Requirements: 13.1, 13.3_
  - [x] 21.3 Maak `app/Console/Commands/BackupVerifyCommand.php` met signature `backup:verify` die de laatste backup decrypt-test, SHA-256 manifest check, en bij mislukking audit-event + alert-mail aanmaakt.
    - Voeg scheduler-entry toe `->dailyAt('03:00')`.
    - _Requirements: 13.4, 13.5_
  - [x] 21.4 Maak `verify-backup.sh`-shellscript in repo-root als alternatief integriteits-runner voor cron buiten Laravel om.
    - _Requirements: 13.4_
  - [x] 21.5 Documenteer restore-procedure in `docs/technical/infrastructuur.md` met RTO 4 uur stappenplan.
    - _Requirements: 13.6_

- [x] 22. Checkpoint — infrastructuur compleet
  - Ensure all tests pass, ask the user if questions arise.

- [x] 23. Technische documentatie (4A — MUST)
  - [x] 23.1 Genereer/actualiseer `docs/technical/datamodel.md` met Mermaid ER-diagram inclusief nieuwe tabellen `projects`, `cost_centers`, `holidays` en kolom-additions op `users` en `work_entries`.
    - _Requirements: 14.1_
  - [x] 23.2 Schrijf `docs/technical/infrastructuur.md` met deployment topology, TLS-config, backup-config, monitoring (Cloud86/Plesk).
    - _Requirements: 14.2_
  - [x] 23.3 Schrijf `docs/technical/email-systeem.md` met overzicht van 11 templates, triggers, retry-policy, retention.
    - _Requirements: 14.3_
  - [x] 23.4 Schrijf `docs/technical/api-referentie.md` (of `openapi.yaml` met OpenAPI 3.1) met alle endpoints uit Requirement 14.5.
    - _Requirements: 14.4, 14.5_
  - [ ]* 23.5 Voeg een markdown-link-checker test toe (`tests/Feature/Docs/LinkCheckTest.php`) die alle interne links in `docs/` valideert.
    - _Requirements: 14.1, 14.2, 14.3, 14.4_

- [x] 24. Gebruikershandleidingen NL (4B — MUST)
  - [x] 24.1 Schrijf `docs/handleidingen/admin-manager.md` met stap-voor-stap instructies + screenshots-placeholders voor alle admin/manager-schermen.
    - _Requirements: 15.1, 15.3, 15.4_
  - [x] 24.2 Schrijf `docs/handleidingen/medewerker.md` met instructies inloggen+MFA, urenstaat, bezwaar indienen, verlof/ziekte, data-export.
    - _Requirements: 15.2, 15.3, 15.4_
  - [x] 24.3 Voeg veelgestelde-vragen-sectie toe en support-procedure-referentie aan beide handleidingen.
    - _Requirements: 15.5_

- [x] 25. AVG Verwerkersovereenkomst VWO (4C — MUST)
  - [x] 25.1 Schrijf `docs/juridisch/avg-verwerkersovereenkomst.md` met alle onderwerpen uit Requirement 16.1 (art. 28 AVG).
    - Vermeld bewaartermijn 7 jaar + pseudonimisering, TLS 1.3, AES-256.
    - _Requirements: 16.1, 16.2, 16.3_
  - [x] 25.2 Voeg sub-verwerkers (Cloud86/Plesk, SMTP-relay) en ondertekenpagina toe.
    - _Requirements: 16.4, 16.5_

- [x] 26. WOR-documentatie (4D — MUST)
  - [x] 26.1 Schrijf `docs/juridisch/wor-instemming.md` met uitleg van art. 27 lid 1 sub l WOR en checklist.
    - _Requirements: 17.1, 17.2_
  - [x] 26.2 Voeg Nederlandstalig sjabloon-instemmingsverzoek aan de OR toe.
    - _Requirements: 17.3_
  - [x] 26.3 Beschrijf wijzigingen die opnieuw OR-instemming vergen (bv. nieuwe profilering, nieuwe bewaartermijn).
    - _Requirements: 17.4_

- [x] 27. Eindcheckpoint — alle MUST/SHOULD/COULD compleet
  - Ensure all tests pass, ask the user if questions arise.
  - Run `php artisan test` (Pest) volledig groen.
  - Run `composer pint` zonder issues.
  - Run `php artisan migrate:fresh --seed --env=testing` zonder fouten.

## Notes

- Optionele subtaken (postfix `*`) zijn test-/documentatietaken die voor MVP geskipt kunnen worden zonder de feature te breken; voor productie-acceptatie zijn ze sterk aanbevolen wegens AVG/WCAG/audit-eisen.
- Iedere taak verwijst expliciet naar één of meer acceptance criteria uit `requirements.md` voor traceability.
- Property-test-subtaken citeren letterlijk de Property-titel uit `design.md` zodat de spec-task-execution subagent de property kan implementeren.
- Bij iedere checkpoint moet `php artisan test` 100% slagen voordat doorgaan.
