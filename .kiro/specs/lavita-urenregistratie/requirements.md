# Requirements Document — LaVita Urenregistratie

## Inleiding

LaVita Urenregistratie is een Laravel-webapplicatie (PHP 8.3, Laravel 13, MySQL) waarmee organisaties uren registreren met automatische naleving van de Nederlandse Arbeidstijdenwet (ATW), AVG/GDPR, WCAG 2.1 niveau AA en WOR-instemming bij meer dan 50 medewerkers. Het systeem ondersteunt vier rollen (admin/owner, manager, medewerker/employee, boekhouder/bookkeeper), een bezwaarprocedure op vastgestelde uren, rapportages met PDF/Excel export, project- en kostenplaatskoppeling, en een fiscale bewaartermijn van 7 jaar met aansluitende pseudonimisering.

Deze specificatie beschrijft alle ontbrekende functionaliteit ten opzichte van de bestaande backend (zie `app/Http/Controllers/Transitie/*`, `app/Services/*`, `database/migrations/*`). De prioritering volgt MoSCoW (MUST/SHOULD/COULD/WONT) zoals afgesproken in de opdrachtbrief en sluit aan op de bestaande modules `WorkEntriesService`, `AtwService`, `ObjectionsService`, `ReportQueryService`, `EmailOutboxService`, `AccountProvisioningService`, `RetentionService`, `PendingInputReminderService` en `AuditService`.

## Glossary

- **ATW**: Arbeidstijdenwet (NL). Daglimiet 12u, weekwaarschuwing 48u, harde weeklimiet 60u, 16-weken gemiddelde 48u, minimale rusttijd 11u, pauze ≥30 min bij >5,5u aaneengesloten werktijd.
- **AVG**: Algemene Verordening Gegevensbescherming (GDPR-NL).
- **WOR**: Wet op de ondernemingsraden — instemmingsrecht bij urenregistratiesystemen voor ondernemingen >50 medewerkers (art. 27 lid 1 sub l).
- **WCAG 2.1 AA**: Web Content Accessibility Guidelines, succescriteria niveau AA.
- **System**: De LaVita-Urenregistratie-applicatie als geheel (backend API + frontend).
- **API**: De interne JSON-API onder `/api/internal/*` met bearer-token authenticatie.
- **UI**: De webfrontend (Blade/Livewire of Vue 3 + Tailwind), gebouwd volgens de design tokens uit `design.md`.
- **Owner / Admin**: Organisatie-eigenaar; volledige rechten binnen organisatie.
- **Manager**: Teamleider; rechten beperkt tot eigen team.
- **Employee / Medewerker**: Eindgebruiker; bekijkt en bezwaart eigen uren.
- **Bookkeeper / Boekhouder**: Read-only rol op uren en rapportages, geen schrijfrechten.
- **WorkEntry**: Werkregel in tabel `work_entries` (één regel = één dienst/dag).
- **Objection**: Bezwaar van medewerker tegen vastgestelde werkregel (`objections`).
- **Project**: Kostenplaats- of projectkoppeling op een werkregel (`projects`, `cost_centers`).
- **ATW_WEEKLY_MAX_EXCEEDED**: Foutcode bij overschrijding 60u/week harde grens (HTTP 422).
- **MFA**: Multi-factor authenticatie (TOTP) — verplicht voor owner en manager, optioneel voor employee/bookkeeper.
- **Net minutes**: Bruto werkminuten minus pauzeminuten.
- **Bewaartermijn**: 7 jaar (fiscaal), waarna automatische pseudonimisering van persoonsgegevens.

## Niet-functionele eisen (van toepassing op alle requirements)

- **NFR-1 Toegankelijkheid**: Alle UI-schermen voldoen aan WCAG 2.1 niveau AA (contrast ≥4.5:1, screenreader-labels, toetsenbordnavigatie, focus-zichtbaar via `border 2px #00d4a4`).
- **NFR-2 Browsers**: Chrome, Firefox, Edge en Safari — desktop én mobiel — op laatste stabiele versie en N-1.
- **NFR-3 Mobile-first**: Layout responsief volgens grid-tokens (1280px max, gutters 32px; desktop 3-koloms 240/720/200, tablet 2-koloms, mobiel 1-koloms).
- **NFR-4 Design tokens**: UI gebruikt uitsluitend design tokens uit `design.md` (kleuren, typografie Inter/Geist Mono, radii, button-/card-/input-componenten).
- **NFR-5 ATW-naleving**: Daglimiet 12u, weekwaarschuwing 48u, harde weekgrens 60u, 16-weken gemiddelde 48u, minimum rusttijd 11u, pauze ≥30 min bij >5,5u werktijd.
- **NFR-6 AVG**: Persoonsgegevens (`users.name`, `users.full_name`, `users.email`, optioneel `users.phone`) versleuteld at-rest via Laravel `encrypted` cast en MySQL full-disk encryption; recht op inzage en pseudonimisering geïmplementeerd; bewaartermijn 7 jaar.
- **NFR-7 Transport**: TLS 1.3 verplicht op productiedomein (Cloud86/Plesk-configuratie); HSTS 12 maanden; cookies `Secure` + `SameSite=Strict`.
- **NFR-8 Backup**: Dagelijkse versleutelde backup om 02:00 via `php artisan backup:run`; retentie 30 dagen; integriteitscheck dagelijks via `verify-backup.sh`.
- **NFR-9 Bewaartermijn**: Fiscale documenten (work_entries, objections, audit_events) 7 jaar; daarna pseudonimisering van persoonsgegevens met behoud van geaggregeerde uren.
- **NFR-10 Taal**: UI, e-mailtemplates, foutmeldingen en handleidingen in het Nederlands.

## Requirements

### Requirement 1: Backend CRUD volledig op werkregels (1A — MUST)

**User Story:** Als manager of owner wil ik bestaande werkregels kunnen ophalen, bewerken en verwijderen via de API, zodat correcties mogelijk zijn binnen autorisatie- en ATW-grenzen.

#### Acceptance Criteria

1. WHEN een geauthenticeerde gebruiker `GET /api/internal/work-entries/{id}` aanroept op een werkregel binnen eigen organisatie, THEN THE System SHALL HTTP 200 retourneren met velden `id, employee_id, team_id, registered_by_id, entry_date, start_at, end_at, pause_minutes, net_minutes, type, note, project_id, cost_center_id, is_finalized, created_at, updated_at`.
2. WHEN `GET /api/internal/work-entries/{id}` wordt aangeroepen door een manager voor een werkregel buiten het eigen team, THEN THE System SHALL HTTP 403 retourneren met code `FORBIDDEN_TEAM_SCOPE`.
3. WHEN `GET /api/internal/work-entries/{id}` wordt aangeroepen door een employee voor een werkregel die niet van henzelf is, THEN THE System SHALL HTTP 403 retourneren met code `FORBIDDEN_OWNER_SCOPE`.
4. WHEN een owner of manager `PATCH /api/internal/work-entries/{id}` aanroept met geldige velden (`entry_date, start_time, end_time, pause_minutes, type, note, project_id, cost_center_id`) en alle ATW-validaties (zie Requirement 4) slagen, THEN THE System SHALL de werkregel bijwerken, `net_minutes` opnieuw berekenen, een audit-event `WORK_ENTRY_UPDATED` schrijven en HTTP 200 retourneren met de bijgewerkte representatie.
5. IF de aanvrager rol `boekhouder` heeft op `PATCH` of `DELETE` van `/api/internal/work-entries/{id}`, THEN THE System SHALL HTTP 403 retourneren met code `READ_ONLY_ROLE`.
6. IF de aanvrager rol `employee` heeft op `PATCH` of `DELETE` van `/api/internal/work-entries/{id}`, THEN THE System SHALL HTTP 403 retourneren met code `FORBIDDEN_ROLE`.
7. WHEN een owner of manager `DELETE /api/internal/work-entries/{id}` aanroept, THEN THE System SHALL de werkregel soft-deleten (`deleted_at`), een audit-event `WORK_ENTRY_DELETED` schrijven, gerelateerde `atw_violations` records markeren als `superseded` en HTTP 204 retourneren.
8. IF op een werkregel reeds een actief bezwaar (`objections.status = 'OPEN'`) bestaat bij `PATCH` of `DELETE`, THEN THE System SHALL HTTP 409 retourneren met code `OBJECTION_OPEN`.
9. WHEN `PATCH` of `DELETE` slaagt, THEN THE System SHALL een notificatiemail `work_entry_updated` of `work_entry_deleted` aanmaken in `email_outbox` voor de betrokken medewerker.

### Requirement 2: Project- en kostenplaatsmodule (1B — MUST)

**User Story:** Als admin wil ik projecten en kostenplaatsen beheren en aan werkregels koppelen, zodat ik kostprijsoverzichten per project en kostenplaats kan genereren.

#### Acceptance Criteria

1. THE System SHALL een tabel `projects` bevatten met kolommen `id, organization_id, code (uniek per org), name, description, hourly_rate (decimal 8,2 nullable), is_active, archived_at, created_at, updated_at`.
2. THE System SHALL een tabel `cost_centers` bevatten met kolommen `id, organization_id, code (uniek per org), name, description, is_active, archived_at, created_at, updated_at`.
3. THE System SHALL kolommen `project_id` (FK `projects.id`, nullable, ON DELETE SET NULL) en `cost_center_id` (FK `cost_centers.id`, nullable, ON DELETE SET NULL) aan `work_entries` toevoegen, geïndexeerd.
4. WHEN een owner `POST /api/internal/projects` aanroept met `code, name, description?, hourly_rate?`, THEN THE System SHALL het project aanmaken binnen de eigen organisatie en HTTP 201 retourneren.
5. THE System SHALL `GET /api/internal/projects`, `GET /api/internal/projects/{id}`, `PATCH /api/internal/projects/{id}` en `DELETE /api/internal/projects/{id}` (soft-delete via `archived_at`) endpoints aanbieden.
6. THE System SHALL identieke CRUD endpoints `/api/internal/cost-centers` aanbieden met dezelfde autorisatieregels.
7. IF een manager of employee `POST/PATCH/DELETE` op `/api/internal/projects` of `/api/internal/cost-centers` aanroept, THEN THE System SHALL HTTP 403 retourneren met code `FORBIDDEN_ROLE`.
8. WHEN een owner, manager of bookkeeper `GET /api/internal/reports/cost-overview?from=&to=&project_id=&cost_center_id=&employee_id=&team_id=` aanroept, THEN THE System SHALL gegroepeerde resultaten retourneren met velden `project_id, project_code, project_name, total_minutes, total_hours, hourly_rate, total_cost` (`total_cost = total_hours * hourly_rate`, of `null` indien geen tarief).
9. WHEN een werkregel wordt aangemaakt of bijgewerkt met `project_id` of `cost_center_id` die niet tot dezelfde organisatie behoren, THEN THE System SHALL HTTP 422 retourneren met foutcode `PROJECT_ORG_MISMATCH` respectievelijk `COST_CENTER_ORG_MISMATCH`.
10. IF een project of kostenplaats `is_active = false`, THEN THE System SHALL bij koppelen aan nieuwe werkregels HTTP 422 retourneren met foutcode `PROJECT_INACTIVE` of `COST_CENTER_INACTIVE`.

### Requirement 3: Boekhouder-rol met read-only middleware (1C — MUST)

**User Story:** Als boekhouder wil ik alle uren en rapportages kunnen inzien en exporteren, zonder enige schrijftoegang, zodat ik fiscale en loonadministratie kan voeren.

#### Acceptance Criteria

1. THE System SHALL de rol `boekhouder` accepteren in `users.role` (kolom is reeds aanwezig en wordt al gevalideerd in `AccountProvisioningService::ALLOWED_TARGET_ROLES`).
2. WHEN een gebruiker met rol `boekhouder` `GET /api/internal/work-entries`, `GET /api/internal/work-entries/{id}`, `GET /api/internal/objections`, `GET /api/internal/reports/work-entries/pdf`, `GET /api/internal/reports/work-entries/excel`, `GET /api/internal/reports/cost-overview`, `GET /api/internal/holidays`, `GET /api/internal/atw/signals` aanroept, THEN THE System SHALL HTTP 200 retourneren met de gevraagde data binnen de eigen organisatie.
3. THE System SHALL een middleware `bookkeeper.readonly` aanbieden die elke HTTP-methode anders dan `GET` afwijst met HTTP 403 en code `READ_ONLY_ROLE` voor gebruikers met rol `boekhouder`.
4. WHEN een boekhouder `POST /api/internal/work-entries` of `PATCH/DELETE /api/internal/work-entries/{id}` aanroept, THEN THE System SHALL HTTP 403 retourneren met code `READ_ONLY_ROLE`.
5. WHEN een boekhouder `POST /api/internal/objections` of `POST /api/internal/objections/{id}/review` aanroept, THEN THE System SHALL HTTP 403 retourneren met code `READ_ONLY_ROLE`.
6. WHEN een boekhouder `POST /api/internal/projects` of `POST /api/internal/cost-centers` aanroept, THEN THE System SHALL HTTP 403 retourneren met code `READ_ONLY_ROLE`.
7. THE System SHALL bij `GET /api/internal/reports/work-entries/pdf` en `excel` ook voor rol `boekhouder` de export toestaan.
8. WHEN een owner of manager via `POST /api/auth/accounts` een account aanmaakt met `role = boekhouder`, THEN THE System SHALL het account toestaan zonder team-binding (`team_id` mag `null` zijn).

### Requirement 4: ATW ontbrekende validaties (1D — MUST)

**User Story:** Als systeem wil ik bij iedere registratie of bewerking de Arbeidstijdenwet afdwingen, zodat overtredingen worden voorkomen of expliciet als waarschuwing of harde fout zichtbaar zijn.

#### Acceptance Criteria

1. WHEN `POST` of `PATCH` op `/api/internal/work-entries(/{id})` een dienst bevat met bruto werktijd >330 minuten (5,5u) en `pause_minutes < 30`, THEN THE System SHALL HTTP 422 retourneren met foutcode `ATW_PAUSE_REQUIRED` en bericht "Bij meer dan 5,5 uur werken is minimaal 30 minuten pauze verplicht".
2. WHEN `POST` of `PATCH` een dienst bevat die ervoor zorgt dat het weektotaal (ISO-week, ma-zo) ≥48u en <60u wordt, THEN THE System SHALL de werkregel toestaan en een ATW-signaal `WEEKLY_WARNING` met severity `warning` aanmaken in `atw_violations`.
3. WHEN `POST` of `PATCH` een dienst bevat die ervoor zorgt dat het weektotaal ≥60u wordt, THEN THE System SHALL HTTP 422 retourneren met foutcode `ATW_WEEKLY_MAX_EXCEEDED` en bericht "Hard weekmaximum (60 uur) overschreden", en de werkregel SHALL niet worden opgeslagen.
4. WHEN `POST` of `PATCH` een dienst bevat met netto werktijd ≥720 minuten (12u), THEN THE System SHALL HTTP 422 retourneren met foutcode `ATW_DAILY_MAX_EXCEEDED`.
5. WHEN `POST` of `PATCH` een dienst bevat met rusttijd <660 minuten (11u) ten opzichte van de vorige dienst van dezelfde medewerker, THEN THE System SHALL HTTP 422 retourneren met foutcode `ATW_REST_PERIOD_VIOLATED`.
6. WHEN `POST` of `PATCH` een dienst bevat die het 16-weken gemiddelde ≥48u/week brengt, THEN THE System SHALL de werkregel toestaan en een ATW-signaal `SIXTEEN_WEEK_AVERAGE` met severity `critical` aanmaken.
7. THE System SHALL voor elke geweigerde werkregel een audit-event `ATW_VIOLATION_BLOCKED` schrijven met `violation_type, current_minutes, threshold_minutes, employee_id, registrar_id`.
8. THE System SHALL `POST /api/internal/work-entries/validate-atw` (bestaande endpoint) uitbreiden met dezelfde foutcodes, zodat de frontend vóór opslaan kan valideren en signaleren.
9. THE System SHALL waarschuwingen (`severity: warning`) duidelijk onderscheiden van fouten (`severity: critical`) in elk responsobject `signals[]`.

### Requirement 5: Welkomstmail bij accountcreatie (1E — MUST)

**User Story:** Als nieuwe medewerker wil ik bij accountcreatie een welkomstmail in het Nederlands ontvangen met inlog-URL, e-mailadres en wachtwoord-set-instructie, zodat ik direct kan inloggen.

#### Acceptance Criteria

1. WHEN `POST /api/auth/accounts` succesvol een account aanmaakt, THEN THE System SHALL een e-mail vom type `welcome_email` (Nederlands) in `email_outbox` queueën met daarin volledige naam, organisatie, rol, login-URL (`{APP_URL}/inloggen`) en een wachtwoord-set-link (`{APP_URL}/wachtwoord-reset?token=...`) die 24 uur geldig is.
2. THE welcome_email template SHALL aanpasbaar zijn via `PUT /api/internal/email/templates/welcome_email` (bestaand `EmailFlowsModuleController` mechanisme).
3. THE welcome_email template SHALL placeholders ondersteunen: `{{ full_name }}, {{ email }}, {{ role }}, {{ organization_name }}, {{ login_url }}, {{ reset_link }}, {{ valid_hours }}`.
4. IF de welcome_email niet kan worden gequeued (database-fout), THEN THE System SHALL de account-creatie terugdraaien (transactie) en HTTP 500 retourneren met code `WELCOME_EMAIL_FAILED`.
5. THE System SHALL het wachtwoord van een nieuw aangemaakt account NIET in plaintext in de e-mail opnemen; alleen een set-link.
6. WHEN een gebruiker met rol `boekhouder` wordt aangemaakt zonder `team_id`, THEN THE welcome_email SHALL geen team vermelden en geen 422 produceren.
7. THE welcome_email SHALL standaard onderwerp "Welkom bij LaVita Urenregistratie" hebben.

### Requirement 6: Frontend — 12 schermen (1F — MUST)

**User Story:** Als gebruiker (in elke rol) wil ik via een Nederlandstalige web-UI alle functionaliteit van het systeem bedienen, zodat ik niet afhankelijk ben van API-clients.

#### Acceptance Criteria

1. THE UI SHALL scherm "Inlog + MFA + QR" bevatten op `/inloggen` met e-mail+wachtwoord stap, daarna 6-cijferige TOTP-stap, en een eerste-keer QR-setup-stap die `secret`, QR-image (data-URL via `chillerlan/php-qrcode` of `endroid/qr-code`) en 8 recovery codes toont.
2. THE UI SHALL scherm "Weekoverzicht admin/manager" bevatten op `/uren/week` met tabel: rijen = medewerkers van eigen organisatie (manager: eigen team), kolommen = ma t/m zo, cellen tonen status (vastgesteld/bezwaar/concept/leeg/feestdag) met badge-kleuren uit design tokens.
3. THE UI SHALL scherm "Invoermodal" bevatten dat live netto-minuten berekent terwijl de gebruiker typt (begin, eind, pauze) en vóór opslaan een ATW-waarschuwing toont op basis van `POST /api/internal/work-entries/validate-atw` (warning/critical kleurgecodeerd) inclusief project- en kostenplaats-selector.
4. THE UI SHALL scherm "Medewerker-urenstaat" bevatten op `/uren/mijn-week` met per regel een bezwaarknop, bezwaarstatus zichtbaar (open/akkoord/afgewezen).
5. THE UI SHALL scherm "ATW-statusdashboard" bevatten op `/atw` met per medewerker per limiettype (dag/week/16-weken/rust/pauze) een gekleurde status (groen=ok, geel=warning ≥48u, rood=critical ≥60u of overig kritiek).
6. THE UI SHALL scherm "Bezwaar beoordelen" bevatten waarop manager/owner met verplichte motivatie (`min:10, max:1000` tekens) een bezwaar accepteert of afwijst; submit-knop SHALL gedeactiveerd zijn zolang motivatie korter is dan 10 tekens.
7. THE UI SHALL scherm "Rapportages & export" bevatten met filters medewerker, team, project, kostenplaats, periode (van/tot), download-knoppen PDF en Excel, en een aparte tab "Jaaroverzicht" voor fiscale export.
8. THE UI SHALL scherm "Accountbeheer" bevatten waarop owner/manager accounts aanmaken, rollen toewijzen, activeren/deactiveren en bij owner ook softdelete kunnen.
9. THE UI SHALL scherm "Managementdashboard" bevatten met aanwezigheid huidige week, openstaande bezwaren teller, ATW-status samenvatting, snelkoppelingen naar weekoverzicht en rapportages.
10. THE UI SHALL scherm "Verlof/ziekte invoer" bevatten met aparte workflow voor `type ∈ {SICK, LEAVE, HOLIDAY}`, datumrange-picker en optionele toelichting (zie Requirement 8).
11. THE UI SHALL scherm "Wachtwoord vergeten/reset" bevatten op `/wachtwoord-reset` met tokenvalidatie en wachtwoordsterkte-indicator (min 12 tekens, mix hoofd/klein/cijfer/symbool).
12. THE UI SHALL scherm "E-mailcycli beheer" bevatten op `/instellingen/email` waarop owner alle templates (welcome_email, work_entry_finalized, work_entry_updated, work_entry_deleted, atw_warning, atw_critical, monthly_report, pending_input_reminder, anniversary, password_reset, objection_review) kan bekijken en bewerken.
13. THE UI SHALL alle 12 schermen volgens design tokens renderen, voldoen aan WCAG 2.1 AA, en mobile-first responsief zijn (zie NFR-1, NFR-3, NFR-4).
14. THE UI SHALL alle foutmeldingen en bevestigingen in het Nederlands tonen.

### Requirement 7: Verlof, ziekte en feestdagen (2A — SHOULD)

**User Story:** Als manager wil ik verlof, ziekte en feestdagen apart van gewone werkuren registreren en automatisch de Nederlandse feestdagenkalender laden, zodat planning en ATW-berekeningen kloppen.

#### Acceptance Criteria

1. WHEN `POST /api/internal/work-entries` wordt aangeroepen met `type ∈ {SICK, LEAVE, HOLIDAY}`, THEN THE System SHALL `start_time` en `end_time` optioneel maken (default 00:00–23:59 voor hele dag) en `pause_minutes` op 0 forceren.
2. WHEN `type ∈ {SICK, LEAVE, HOLIDAY}` en de aanvrager rol `employee` heeft, THEN THE System SHALL alleen `type = SICK` of `LEAVE` toestaan voor zichzelf met motivatie (`note` verplicht, min 1 teken) en HTTP 422 met code `INVALID_TYPE_FOR_ROLE` retourneren bij `HOLIDAY`.
3. THE System SHALL kolom `work_entries.type` accepteren met set `{WORK, SICK, LEAVE, HOLIDAY, OTHER}`.
4. THE System SHALL een tabel `holidays` aanmaken met kolommen `id, year, date, name, is_national (bool), created_at, updated_at` en uniek-index op `(year, date)`.
5. THE System SHALL een artisan-command `php artisan holidays:import {year}` aanbieden die de Nederlandse nationale feestdagen (Nieuwjaarsdag, Goede Vrijdag, Pasen, Koningsdag, Bevrijdingsdag (lustrum), Hemelvaart, Pinksteren, Kerst) berekent (of importeert via vaste regels) en in `holidays` opslaat.
6. WHEN een gebruiker `GET /api/internal/holidays?year={YYYY}` aanroept, THEN THE System SHALL een JSON-array `[{date, name, is_national}]` retourneren.
7. THE UI SHALL feestdagen in het weekoverzicht als grijze cel tonen met de feestdagnaam in tooltip.
8. WHEN een werkregel met `type = SICK` of `LEAVE` wordt aangemaakt, THEN THE System SHALL deze NIET meenemen in de ATW-werktijd-totalen, maar wel zichtbaar in rapportages.

### Requirement 8: Herhaalfunctie copy-week (2B — SHOULD)

**User Story:** Als manager wil ik een ingevulde werkweek kunnen kopiëren naar de volgende week, zodat ik bij vaste roosterpatronen tijd bespaar.

#### Acceptance Criteria

1. WHEN een owner of manager `POST /api/internal/work-entries/copy-week` aanroept met body `{ employee_id, source_week_start (Y-m-d, maandag), target_week_start (Y-m-d, maandag) }`, THEN THE System SHALL alle werkregels van type `WORK` uit de bron-week dupliceren naar de doel-week onder dezelfde `employee_id`, met `is_finalized = true`.
2. IF de bronweek geen werkregels bevat, THEN THE System SHALL HTTP 422 retourneren met code `SOURCE_WEEK_EMPTY`.
3. IF in de doel-week voor dezelfde dag/start-tijd reeds een werkregel bestaat, THEN THE System SHALL die specifieke kopie overslaan en in de response opnemen onder `skipped[]` (met reden `DUPLICATE`).
4. THE System SHALL voor elke gekopieerde werkregel ATW-validatie uitvoeren (Requirement 4); ATW-fouten (`severity: critical`) SHALL ervoor zorgen dat die specifieke kopie wordt overgeslagen en opgenomen onder `skipped[]` met reden `ATW_BLOCKED`.
5. THE response SHALL het formaat `{ created: WorkEntry[], skipped: { date, start_time, reason }[] }` hebben.
6. IF de aanvrager rol `employee` of `boekhouder` heeft, THEN THE System SHALL HTTP 403 retourneren met code `FORBIDDEN_ROLE` of `READ_ONLY_ROLE`.

### Requirement 9: Openstaande-invoer herinnering (2C — SHOULD)

**User Story:** Als manager wil ik per e-mail herinnerd worden wanneer een medewerker X dagen geen uren heeft ingevoerd, zodat ik tijdig kan bijsturen.

#### Acceptance Criteria

1. THE System SHALL kolom `users.email_reminders_opt_in` (bool, default `true`) toevoegen voor opt-out per gebruiker (AVG).
2. THE System SHALL configuratie `pending_input.threshold_days` (default 3, min 1, max 14) op `organizations` of `.env` beschikbaar maken.
3. THE System SHALL een dagelijkse scheduler-job (bestaande command `RunPendingInputReminderCommand` uitbreiden) hebben die elke dag om 08:00 Europe/Amsterdam draait.
4. WHEN de scheduler draait, THEN THE System SHALL voor iedere actieve medewerker met `email_reminders_opt_in = true` controleren of er in de afgelopen `threshold_days` werkdagen (ma-vr) geen werkregels bestaan; zo ja, THEN THE System SHALL een mail van type `pending_input_reminder` aan de manager(s) van diens team queueën.
5. IF een medewerker `email_reminders_opt_in = false` heeft, THEN THE System SHALL geen `pending_input_reminder` of `monthly_report` mail aanmaken voor die medewerker (essentiële mails als `welcome_email`, `password_reset`, `work_entry_finalized` blijven verstuurd).
6. THE UI SHALL bij accountinstellingen een toggle "E-mail herinneringen ontvangen" tonen die `email_reminders_opt_in` aanstuurt.

### Requirement 10: AVG endpoints — recht op verwijdering en inzage (2D — MUST)

**User Story:** Als medewerker (of admin namens medewerker) wil ik mijn persoonsgegevens kunnen exporteren en bij uitdiensttreding pseudonimiseren, terwijl uren 7 jaar bewaard blijven, zodat de AVG en fiscale bewaarplicht beide nageleefd worden.

#### Acceptance Criteria

1. WHEN een owner `DELETE /api/internal/accounts/{id}` aanroept binnen eigen organisatie, THEN THE System SHALL het account soft-deleten (`users.deleted_at`), `is_active = false` zetten, persoonsvelden pseudonimiseren (`name = "user-{id}"`, `full_name = null`, `email = "user-{id}@redacted.lavita.local"`, `phone = null`), `deleted_by_id` registreren, een audit-event `ACCOUNT_PSEUDONYMIZED` schrijven en HTTP 204 retourneren.
2. THE System SHALL bij pseudonimisering werkregels, bezwaren, audit-events behouden (`employee_id` blijft FK naar gepseudonimiseerd account).
3. WHEN een eigenaar of de gebruiker zelf `GET /api/internal/accounts/{id}/data-export` aanroept, THEN THE System SHALL JSON retourneren met `user, work_entries[], objections[], atw_violations[], email_outbox[] (alleen verzonden mails aan deze gebruiker), audit_events[]` binnen 30 seconden, of HTTP 202 met een job-id wanneer dataset >10 MB.
4. IF de aanvrager noch de gebruiker zelf noch een owner is, THEN THE System SHALL HTTP 403 retourneren met code `FORBIDDEN_DATA_EXPORT`.
5. THE System SHALL een maandelijkse scheduler-job `RunRetentionCommand` (bestaand, uit te breiden) hebben die elke 1e van de maand om 03:00 alle gepseudonimiseerde accounts ouder dan 7 jaar (`deleted_at < now()-7y`) verder anonimiseert door `users.employment_start, employment_end` op `null` te zetten en alle audit-events ouder dan 7 jaar `actor_id` te nullen.
6. THE System SHALL bij elke pseudonimisering een audit-event `ACCOUNT_PSEUDONYMIZED` schrijven met `target_user_id, actor_id, organization_id, reason`.
7. WHEN `DELETE /api/internal/accounts/{id}` wordt aangeroepen op een account met openstaande bezwaren, THEN THE System SHALL HTTP 409 retourneren met code `OPEN_OBJECTIONS` totdat alle bezwaren afgerond zijn.

### Requirement 11: Jubileumnotificaties (2E — COULD)

**User Story:** Als manager wil ik automatisch een mail ontvangen op het 1-, 5-, 10- of 25-jarig dienstverband van een medewerker, zodat ik dit kan vieren.

#### Acceptance Criteria

1. THE System SHALL een dagelijkse scheduler-job hebben die elke dag om 06:00 Europe/Amsterdam draait.
2. WHEN de scheduler draait, THEN THE System SHALL alle actieve medewerkers selecteren waarvoor vandaag (`now()->format('m-d')`) overeenkomt met `users.employment_start` en het aantal jaren dienstverband ∈ {1, 5, 10, 25} is.
3. WHEN een match gevonden wordt, THEN THE System SHALL een mail van type `anniversary` queueën voor zowel de medewerker als de manager(s) van diens team, met placeholders `{{ full_name }}, {{ years }}, {{ employment_start }}`.
4. THE anniversary template SHALL bewerkbaar zijn via `PUT /api/internal/email/templates/anniversary`.
5. IF `users.employment_start` `null` is, THEN THE System SHALL die gebruiker overslaan zonder fout.
6. THE System SHALL een audit-event `ANNIVERSARY_DISPATCHED` schrijven per verzonden mail.

### Requirement 12: TLS, encryptie en at-rest bescherming (3A — MUST)

**User Story:** Als security officer wil ik dat persoonsgegevens versleuteld in transit (TLS 1.3) en at-rest (AES-256) staan, zodat de organisatie aan AVG en interne baseline voldoet.

#### Acceptance Criteria

1. THE System SHALL het Laravel `encrypted` cast toepassen op `users.full_name`, `users.email` (via shadow-kolom `email_index_hash` voor lookups) en optioneel `users.phone`, zodat de waarden in de database AES-256-CBC versleuteld staan.
2. THE System SHALL voor `email`-lookups een SHA-256 deterministische hash bewaren in `users.email_index_hash` (uniek per organisatie) zodat login en account-aanmaak werken zonder full-table-decrypt.
3. THE deployment-documentatie SHALL stappen bevatten om TLS 1.3 op Cloud86/Plesk in te schakelen (cipher-lijst, OCSP-stapling, HSTS 12 maanden, redirect HTTP→HTTPS).
4. THE deployment-documentatie SHALL stappen bevatten om MySQL full-disk encryption in te schakelen op de Cloud86/Plesk-host (LUKS of equivalent).
5. WHEN een verzoek over HTTP (zonder TLS) binnenkomt op productiedomein, THEN THE System SHALL 308-redirecten naar HTTPS.
6. THE System SHALL `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` als response header zetten op alle HTTPS-responses.
7. WHEN encryptiesleutels rouleren (key rotation), THEN THE System SHALL de oude sleutel als `previous_keys` blijven accepteren via Laravel's APP_PREVIOUS_KEYS env, zodat lopende sessies niet breken.

### Requirement 13: Backup, integriteit en retentie (3B — MUST)

**User Story:** Als beheerder wil ik dagelijkse versleutelde backups met integriteitscheck en alerting, zodat herstel binnen RTO mogelijk is.

#### Acceptance Criteria

1. THE System SHALL een Laravel-scheduler-entry hebben die elke dag om 02:00 Europe/Amsterdam `php artisan backup:run` uitvoert (op basis van `spatie/laravel-backup` of equivalent).
2. THE backup SHALL alle MySQL-tabellen plus `storage/app/private` (geüploade bijlagen) bevatten, GPG- of OpenSSL-AES-256-versleuteld met een sleutel die niet in de codebase staat.
3. THE System SHALL backups 30 dagen bewaren en oudere automatisch verwijderen.
4. THE System SHALL elke dag om 03:00 een script `verify-backup.sh` draaien dat de meest recente backup decrypt-test doet, een SHA-256 manifest controleert en bij mislukken een `BACKUP_INTEGRITY_FAILED` alert via mail (`alerts@{org-domain}`) en audit-event genereert.
5. WHEN de backup-job faalt of niet binnen 90 minuten klaar is, THEN THE System SHALL een audit-event `BACKUP_JOB_FAILED` schrijven en een alert-mail aan de owner queueën.
6. THE deployment-documentatie SHALL beschrijven hoe een backup terug te zetten in een nieuw Plesk-environment binnen RTO 4 uur.

### Requirement 14: Technische documentatie en API-referentie (4A — MUST)

**User Story:** Als nieuwe ontwikkelaar of beheerder wil ik formele oplevering van de technische documentatie, zodat onderhoud en doorontwikkeling mogelijk is.

#### Acceptance Criteria

1. THE System SHALL een document `docs/technical/datamodel.md` opleveren met ER-diagram (Mermaid) van alle tabellen en relaties.
2. THE System SHALL een document `docs/technical/infrastructuur.md` opleveren met deployment topology (Cloud86/Plesk, MySQL, queue, scheduler), TLS-config, backup-config en monitoring.
3. THE System SHALL een document `docs/technical/email-systeem.md` opleveren met overzicht van alle 11 templates, triggers, retry-policy, retention en pseudonimisering van logs.
4. THE System SHALL een document `docs/technical/api-referentie.md` opleveren (of OpenAPI 3.1 `openapi.yaml`) met alle endpoints, parameters, responses, foutcodes en autorisatie per rol.
5. THE API-referentie SHALL minimaal alle endpoints bevatten: `/api/auth/*`, `/api/internal/work-entries(/{id}, /copy-week, /validate-atw)`, `/api/internal/objections(/, /{id}/review)`, `/api/internal/projects(/{id})`, `/api/internal/cost-centers(/{id})`, `/api/internal/reports/*`, `/api/internal/holidays`, `/api/internal/accounts(/{id}, /{id}/data-export)`, `/api/internal/email/*`, `/api/internal/atw/signals`, `/api/internal/audit/export`.

### Requirement 15: Gebruikershandleidingen NL (4B — MUST)

**User Story:** Als eindgebruiker wil ik Nederlandstalige handleidingen voor mijn rol, zodat ik zonder training het systeem kan gebruiken.

#### Acceptance Criteria

1. THE System SHALL een document `docs/handleidingen/admin-manager.md` (of PDF-export) opleveren met stap-voor-stap instructies voor accountbeheer, urenregistratie, bezwaarafhandeling, rapportages, projectbeheer, e-mailtemplates en ATW-dashboard.
2. THE System SHALL een document `docs/handleidingen/medewerker.md` opleveren met instructies voor inloggen + MFA-setup, eigen urenstaat bekijken, bezwaar indienen, verlof/ziekte registreren, data-export aanvragen.
3. THE handleidingen SHALL screenshots bevatten van de relevante schermen uit Requirement 6, in lijn met de design tokens (kleuren, typografie).
4. THE handleidingen SHALL in het Nederlands geschreven zijn op B1-niveau.
5. THE handleidingen SHALL een veelgestelde-vragen-sectie bevatten en een referentie naar de support-procedure.

### Requirement 16: AVG Verwerkersovereenkomst (VWO) (4C — MUST)

**User Story:** Als verwerkingsverantwoordelijke wil ik een ondertekende verwerkersovereenkomst conform art. 28 AVG met de leverancier, zodat de juridische basis op orde is.

#### Acceptance Criteria

1. THE System SHALL een document `docs/juridisch/avg-verwerkersovereenkomst.md` opleveren conform de eisen van art. 28 lid 3 AVG, inclusief: onderwerp, duur, aard en doel, type persoonsgegevens, betrokkenen, rechten en plichten verwerkingsverantwoordelijke, verplichtingen verwerker, sub-verwerkers, datalek-meldplicht, bewaartermijnen, audit-recht.
2. THE document SHALL bewaartermijn 7 jaar voor work_entries en pseudonimisering daarna vermelden, conform Requirement 10.
3. THE document SHALL TLS 1.3 in transit en AES-256 at-rest noemen, conform Requirement 12.
4. THE document SHALL sub-verwerkers (Cloud86/Plesk-hostingprovider, e-mailprovider) opsommen.
5. THE document SHALL een ondertekenpagina hebben voor verwerkingsverantwoordelijke en verwerker.

### Requirement 17: WOR-documentatie en instemmingsprocedure (4D — MUST)

**User Story:** Als bestuurder van een organisatie >50 medewerkers wil ik een WOR-instemmingsprocedure conform art. 27 lid 1 sub l WOR, zodat het urenregistratiesysteem rechtsgeldig in gebruik genomen wordt.

#### Acceptance Criteria

1. THE System SHALL een document `docs/juridisch/wor-instemming.md` opleveren met uitleg van art. 27 lid 1 sub l WOR (instemmingsplichtige besluiten over verwerking persoonsgegevens van werknemers).
2. THE document SHALL een checklist bevatten met: (a) tijdige aanvraag instemming OR, (b) verstrekte informatie aan OR (functioneel ontwerp, datamodel, AVG-verwerkersovereenkomst), (c) besluit OR, (d) afhandeling bij weigering instemming, (e) registratie van de schriftelijke instemming in `docs/juridisch/wor-besluit-{datum}.md`.
3. THE document SHALL een sjabloon-instemmingsverzoek aan de OR bevatten in het Nederlands.
4. THE document SHALL beschrijven welke wijzigingen na go-live opnieuw instemming vergen (bv. nieuwe profilering, nieuwe verstrekking aan derden, wijziging bewaartermijn).
