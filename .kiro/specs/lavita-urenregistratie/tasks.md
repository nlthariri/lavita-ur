# Implementation Plan: La Vita Urenregistratie

## Overview

Dit implementatieplan breekt de La Vita Urenregistratie feature op in incrementele taken, geordend op afhankelijkheid en prioriteit (Fase 1 → 2 → 3). Elke taak bouwt voort op voorgaande stappen. De stack is Laravel 13 + Livewire 3.6 + Tailwind CSS + Alpine.js + MySQL.

## Tasks

- [x] 1. UI Atoms: Herbruikbare Blade-componenten aanmaken
  - [x] 1.1 Implementeer `<x-ui.toast>` Blade-component met Alpine.js toastManager
    - Maak `resources/views/components/ui/toast.blade.php` met Alpine.js `x-data="toastManager()"` container
    - Implementeer queue-logica (max 3 zichtbaar), auto-dismiss (5s default, 8s error), hover-pause
    - Slide-in animatie (translate-x), fade-out, positioning (fixed top-4 right-4 desktop, inset-x-4 mobiel)
    - ARIA: `role="alert"`, `aria-live="polite"`, sluit-knop met `aria-label="Melding sluiten"`
    - Registreer globale `@toast.window` event listener in `layouts/app.blade.php`
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10, 12.1_

  - [x] 1.2 Implementeer `<x-ui.modal>` Blade-component
    - Maak `resources/views/components/ui/modal.blade.php` met props: title, size (sm/md/lg), show
    - Alpine.js `x-show` met `x-transition` (scale 95%→100% + opacity, 200ms)
    - Focus-trap, Escape-toets sluit modal, backdrop click sluit modal
    - ARIA: `role="dialog"`, `aria-modal="true"`, `aria-labelledby`
    - _Requirements: 12.2, 12.8, 12.9_

  - [x] 1.3 Implementeer `<x-ui.progress>` Blade-component
    - Maak `resources/views/components/ui/progress.blade.php` met props: value, max, variant, label, showPercentage
    - Breedte-berekening: `min(100, max(0, (value / max) * 100))%`
    - Variant-kleuren: success=brand-green, warning=warning, danger=danger
    - ARIA: `role="progressbar"`, `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax`
    - _Requirements: 12.3, 12.8, 12.10_

  - [x] 1.4 Implementeer `<x-ui.skeleton>` Blade-component
    - Maak `resources/views/components/ui/skeleton.blade.php` met props: type (text/card/chart/avatar), lines
    - Pulserende placeholder met `animate-pulse bg-surface rounded`
    - Types: text (variërende breedte), card (120px), chart (200px), avatar (cirkel)
    - _Requirements: 12.4_

  - [x] 1.5 Implementeer `<x-ui.stat-card>` Blade-component
    - Maak `resources/views/components/ui/stat-card.blade.php` met props: title, value, trend, trendValue, icon
    - Wraps `<x-ui.card>` met `border-l-4` accent: brand-green (up), danger (down), hairline (neutral)
    - Trend-pijl iconen (omhoog/omlaag/neutraal)
    - _Requirements: 12.5_

  - [x] 1.6 Implementeer `<x-ui.avatar>` Blade-component
    - Maak `resources/views/components/ui/avatar.blade.php` met props: name, size (sm/md/lg), src
    - Initialen-algoritme: eerste+laatste woord eerste letter, of eerste 2 letters bij 1 woord
    - Deterministieke achtergrondkleur op basis van naam-hash (6 kleuren)
    - Fallback naar foto wanneer `src` beschikbaar
    - _Requirements: 12.6_

  - [x]* 1.7 Write property tests voor UI atoms
    - **Property 8: Toast-variant bepaalt correcte styling en timing**
    - **Property 9: Toast-queue toont maximaal 3 tegelijk**
    - **Property 10: Progress-bar breedte is proportioneel aan value/max**
    - **Property 11: Avatar-initialen worden correct afgeleid**
    - **Validates: Requirements 3.1, 3.5, 3.6, 3.9, 3.10, 12.3, 12.6**

- [x] 2. Checkpoint — UI Atoms gereed
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Fase 1: DashboardAggregationService en LeaveBalanceService
  - [x] 3.1 Implementeer `DashboardAggregationService`
    - Maak `app/Services/DashboardAggregationService.php`
    - Implementeer `getKpiData(User $user, ?int $teamFilter): array` met eager-loaded queries
    - Bereken: total_hours_this_week, total_hours_prev_week, attendance_percentage, pending_leave_count, atw_critical_count, atw_warning_count, open_objections_count, sick_percentage, chart_data, activity_feed
    - Scope-filtering: team_id voor manager, organization_id voor owner
    - _Requirements: 1.2, 1.3, 1.4, 1.8_

  - [x]* 3.2 Write property test voor KPI-aggregatie
    - **Property 16: KPI-aggregatie is consistent met onderliggende data**
    - **Validates: Requirements 1.2, 1.3**

  - [x] 3.3 Implementeer `LeaveBalanceService`
    - Maak `app/Services/LeaveBalanceService.php`
    - Implementeer `getBalance(int $userId, int $year): array` met status (ok/warning/danger/unconfigured)
    - Implementeer `calculateTakenDays(int $userId, int $year): float` met half-dag detectie (0.5)
    - Filter op: type=LEAVE, is_finalized=true, deleted_at=null, counts_towards_balance=true
    - _Requirements: 9.3, 9.4, 10.4, 11.6_

  - [x]* 3.4 Write property test voor verlof-saldo berekening
    - **Property 5: Verlof-saldo berekening is correct**
    - **Validates: Requirements 9.3, 9.4, 10.4, 10.9, 11.6**

- [x] 4. Fase 1: Manager/Owner Dashboard uitbreiden
  - [x] 4.1 Uitbreiden `Dashboard\ManagerHome` Livewire-component met KPI-cards
    - Voeg `DashboardAggregationService` dependency injection toe
    - Render 6 KPI-cards via `<x-ui.stat-card>`: uren, aanwezigheid, verlofaanvragen, ATW-meldingen, bezwaren, ziekteverzuim
    - Implementeer `wire:poll.30s` voor auto-refresh
    - Toon persoonlijke begroeting met naam + datum (Nederlands formaat)
    - Skeleton placeholders tijdens laden via `<x-ui.skeleton type="card">`
    - _Requirements: 1.1, 1.2, 1.6, 1.7, 1.9_

  - [x] 4.2 Voeg ApexCharts staafgrafiek toe aan ManagerHome
    - Integreer ApexCharts via CDN in layout (indien nog niet aanwezig)
    - Render bar chart met uren per dag (ma-zo), gegroepeerd per team (owner) of totaal (manager)
    - Lazy-load chart via Livewire `lazy` attribute
    - Skeleton placeholder `<x-ui.skeleton type="chart">` tijdens laden
    - _Requirements: 1.3, 1.10_

  - [x] 4.3 Voeg activiteit-feed en snelactie-knoppen toe aan ManagerHome
    - Render laatste 10 acties (uren ingevoerd, verlof aangevraagd, bezwaar) met `<x-ui.avatar>`
    - Snelactie-knoppen: "Uren invoeren", "Verlof goedkeuren" (badge), "Bezwaar beoordelen" (badge)
    - _Requirements: 1.4, 1.5_

  - [x]* 4.4 Write property test voor data-scoping
    - **Property 4: Data-scoping per rol is waterdicht**
    - **Validates: Requirements 1.8, 8.3**

- [x] 5. Fase 1: Employee Dashboard uitbreiden
  - [x] 5.1 Uitbreiden `Dashboard\EmployeeHome` Livewire-component
    - Voeg `LeaveBalanceService` dependency injection toe
    - Toon persoonlijke begroeting met naam + datum
    - Progress_Bar "Mijn uren deze week" (netto-minuten vs contracturen) via `<x-ui.progress>`
    - Verlof-saldo Progress_Bar met opgenomen/resterend, waarschuwingskleuren (≤3 = oranje, ≤0 = rood)
    - Verberg widgets wanneer niet geconfigureerd (annual_leave_days=null, geen contracturen)
    - Lijst openstaande bezwaren met status-badge
    - Snelactie-knoppen: "Uren invoeren", "Verlof aanvragen"
    - Mini-weekoverzicht met horizontale balken per dag + Color_Coding
    - Skeleton placeholders tijdens laden
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10_

- [x] 6. Checkpoint — Fase 1 Dashboards gereed
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Fase 2: Weekoverzicht kleurcodering, totalen en copy-week
  - [x] 7.1 Uitbreiden `Hours\WeekOverviewTable` met kleurcodering en totalen
    - Implementeer Color_Coding per cel: WORK=emerald-50/brand-green, SICK=red-50/danger, LEAVE=blue-50/blue-500, HOLIDAY=purple-50/purple-500
    - Lege cellen: `border-dashed border-hairline bg-canvas` met klikbaar oppervlak → open invoer-modal
    - Totaal-kolom per medewerker (weeksom HH:mm), totaal-rij per dag, Grand_Total rechtsonder
    - Visueel onderscheid totalen: `bg-surface` + bold
    - Tooltips bij hover (type + netto voor gevulde cellen, instructie voor lege cellen)
    - ATW-waarschuwing: oranje rand + icoon bij cellen met violations
    - Horizontaal scrollbaar bij >15 medewerkers, sticky eerste kolom
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 4.10_

  - [x]* 7.2 Write property tests voor weekoverzicht
    - **Property 2: Weekoverzicht totalen zijn consistent met individuele cellen**
    - **Property 3: Kleurcodering is deterministisch per type**
    - **Validates: Requirements 4.1, 4.3, 4.4, 4.5, 4.10**

  - [x] 7.3 Implementeer copy-week functionaliteit in WeekOverviewTable
    - "Kopieer vorige week" knop in toolbar (alleen owner/manager)
    - Bevestigingsmodal met bron/doel-week datums
    - Aanroep `CopyWeekService::copyWeek()` met scope-filtering
    - Toast feedback: success (X regels), warning (Y overgeslagen + detail), info (bronweek leeg)
    - Loading state: knop disabled + spinner tijdens operatie
    - Verberg knop voor employee/boekhouder
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8_

  - [x]* 7.4 Write property tests voor copy-week
    - **Property 6: Copy-week verschuift entries exact 7 dagen**
    - **Property 7: Copy-week created + skipped = bron-totaal**
    - **Validates: Requirements 5.3, 5.4, 5.5, 5.6**

  - [x] 7.5 Implementeer ATW-indicatoren in weekoverzicht
    - Waarschuwingsicoon naast medewerker-naam: oranje driehoek (warning), rood uitroepteken (critical)
    - Tooltip met overtreding-type (weekwaarschuwing, weeklimiet, rusttijd)
    - Cel-markering met oranje/rode rand bij ATW-triggerende werkregels
    - Alleen zichtbaar voor owner/manager/boekhouder
    - Ophalen in dezelfde query als status-matrix (geen extra roundtrips)
    - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5_

- [x] 8. Fase 2: Verbeterde invoer-modal
  - [x] 8.1 Uitbreiden `Hours\EntryFormModal` met slimme defaults en keyboard shortcuts
    - Auto-focus op begintijd-veld bij openen
    - Tab-volgorde: begintijd → eindtijd → pauze → project → kostenplaats → notitie → opslaan
    - Slimme defaults: placeholder "Vorige dag: [start] - [end]" op basis van vorige werkdag
    - Live netto-minuten berekening (Livewire of Alpine.js) in formaat "X uur Y minuten"
    - Keyboard shortcuts: Enter = submit (als velden gevuld), Escape = sluiten
    - ATW-validatie vóór opslaan: oranje banner (warning), rode banner + blokkeer knop (critical)
    - Toast (success) + event `entry-saved` bij succesvol opslaan
    - Inline NL-foutmeldingen bij validatiefouten, modal blijft open
    - Project-selector en kostenplaats-selector dropdowns
    - Focus-trap en `role="dialog"`, `aria-modal="true"`
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 6.10_

  - [x]* 8.2 Write property tests voor invoer-modal
    - **Property 1: Netto-minuten berekening is correct**
    - **Property 12: Slimme defaults tonen vorige werkdag-tijden**
    - **Validates: Requirements 6.2, 6.3**

- [x] 9. Fase 2: Mijn Week visuele tijdlijn
  - [x] 9.1 Uitbreiden `Hours\MyWeek` met visuele tijdlijn
    - Per dag (ma-zo) horizontale tijdlijnbalk (bereik 06:00-22:00)
    - Positie-berekening: `left% = (start_minutes - 360) / 960 × 100`, `width% = duration / 960 × 100`
    - Color_Coding per type op de tijdlijnbalk
    - Weektotaal bovenaan: "XX uur YY minuten" + vergelijking met contracturen
    - Bezwaar-icoon naast balk (open=oranje, akkoord=groen, afgewezen=rood)
    - Begin/eindtijd tekst naast balk: "09:00 - 17:30 (8u netto)"
    - Lege dag: gestippelde rand + "Geen registratie" in steel-kleur
    - Week-navigatie (vorige/volgende, vandaag-knop) + keyboard shortcuts (←/→)
    - Klik op balk → uitklapbaar detailpaneel
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8_

  - [x]* 9.2 Write property test voor tijdlijn-positie
    - **Property 17: Tijdlijn-positie is proportioneel aan tijdstip**
    - **Validates: Requirements 7.1**

- [x] 10. Fase 2: Print-functionaliteit
  - [x] 10.1 Implementeer print-functionaliteit voor weekoverzicht
    - "Printen" knop in toolbar naast "Kopieer vorige week"
    - `window.print()` trigger met print-specifieke CSS stylesheet
    - `@media print`: verberg navigatie/sidebar/toolbar, toon alleen weektabel + totalen
    - Kleurcodering behouden via `-webkit-print-color-adjust: exact`
    - Print-header: "Weekoverzicht — Week [nr] — [organisatienaam]" + printdatum
    - Print-footer: "Gegenereerd door La Vita Urenregistratie op [datum tijd]"
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5, 14.6_

- [x] 11. Checkpoint — Fase 2 gereed
  - Ensure all tests pass, ask the user if questions arise.

- [x] 12. Fase 3: Database-migraties en verlof-types
  - [x] 12.1 Maak database-migraties voor verlof-systeem
    - Migratie 1: `create_leave_types_table` met alle kolommen, indexes en FK
    - Migratie 2: `add_annual_leave_days_to_users` (nullable SMALLINT)
    - Migratie 3: `add_leave_type_id_to_work_entries` (nullable FK naar leave_types)
    - Maak `LeaveType` Eloquent model met relaties en scopes
    - Update `User` model: `annual_leave_days` fillable, `leaveTypes()` relatie
    - Update `WorkEntry` model: `leaveType()` belongsTo relatie
    - _Requirements: 11.1, 11.4, 9.1_

  - [x] 12.2 Implementeer seeder voor standaard verlof-types
    - Maak `LeaveTypeSeeder` die 4 standaard types aanmaakt per organisatie
    - VAKANTIE (counts_towards_balance=true), BIJZONDER, ONBETAALD, OUDERSCHAP (false)
    - Integreer in `DatabaseSeeder` of organisatie-aanmaak flow
    - _Requirements: 11.2_

  - [x] 12.3 Implementeer `Settings\LeaveTypesManager` Livewire-component (nieuw)
    - Route: `/instellingen/verlof-types` (alleen owner)
    - CRUD: aanmaken, bewerken, deactiveren (soft) van verlof-types
    - Validatie: unieke code per organisatie, verplichte velden
    - Audit-events: LEAVE_TYPE_CREATED, LEAVE_TYPE_UPDATED, LEAVE_TYPE_DEACTIVATED
    - Dispatch `leave-type-updated` event bij wijzigingen
    - _Requirements: 11.3, 11.9_

  - [x]* 12.4 Write property test voor verlof-types filter
    - **Property 13: Verlof-types dropdown toont alleen actieve types van eigen organisatie**
    - **Validates: Requirements 11.5, 11.9**

- [x] 13. Fase 3: Verlof-aanvraag uitbreiden (half-dag, annuleren, types)
  - [x] 13.1 Uitbreiden `Hours\LeaveForm` met half-dag verlof en verlof-types
    - Radio-buttons: "Hele dag" / "Halve dag" met visuele scheiding
    - Halve dag opties: "Ochtend (tot 12:30)" / "Middag (vanaf 12:30)"
    - Ochtend: start_at=00:00, end_at=12:30, net_minutes=0
    - Middag: start_at=12:30, end_at=23:59, net_minutes=0
    - Verlof-type dropdown met actieve types van eigen organisatie
    - Verlof-saldo weergave op aanvraag-pagina
    - Waarschuwing bij max_days_per_year bereikt (soft limit, oranje banner)
    - Validatie: leaveTypeId required_if type=LEAVE
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.10, 11.5, 11.6, 11.7, 9.9_

  - [x] 13.2 Implementeer verlof-annulering in LeaveForm
    - "Annuleren" knop naast PENDING verlofaanvragen (is_finalized=false)
    - Bevestigingsmodal: "Weet je zeker dat je deze verlofaanvraag wilt annuleren?"
    - Bij bevestiging: soft-delete werkregel, audit-event LEAVE_CANCELLED, Toast (success)
    - Verberg knop bij goedgekeurd verlof (is_finalized=true)
    - HTTP 409 met code LEAVE_ALREADY_APPROVED bij directe API-aanroep op goedgekeurd verlof
    - Verlof-saldo direct bijwerken na annulering
    - _Requirements: 10.5, 10.6, 10.7, 10.8, 10.9_

  - [x]* 13.3 Write property test voor verlof-annulering blokkade
    - **Property 14: Goedgekeurd verlof kan niet geannuleerd worden**
    - **Validates: Requirements 10.8**

- [x] 14. Fase 3: Verlofkalender
  - [x] 14.1 Implementeer `Hours\LeaveCalendar` Livewire-component (nieuw)
    - Route: `/verlof/kalender` (owner, manager, boekhouder)
    - Maandweergave grid: rijen=medewerkers, kolommen=dagen
    - Color_Coding: SICK=rood, LEAVE=blauw, HOLIDAY=grijs, leeg=wit
    - Scope-filtering: manager=eigen team, owner=alle teams (optioneel team-filter)
    - Feestdagen markeren in kolomheader (grijze achtergrond + tooltip)
    - Klik op lege cel → snelle verlof-invoer-modal (medewerker+datum vooringevuld)
    - Maand-navigatie (vorige/volgende, vandaag-knop) + keyboard shortcuts (←/→)
    - Verticaal scrollbaar bij >20 medewerkers, sticky header-rij
    - Totaal-kolom per medewerker (verlofdagen in maand)
    - Lazy-load via Livewire `lazy`
    - HTTP 403 voor employee-rol
    - Legenda met actieve verlof-types + kleurcodering
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7, 8.8, 8.9, 8.10, 11.10_

- [x] 15. Fase 3: Verlof e-mail notificaties
  - [x] 15.1 Implementeer verlof e-mail templates en dispatch-logica
    - Voeg templates toe aan `email_templates`: leave_approved, leave_rejected, leave_requested, leave_reminder
    - Implementeer dispatch bij goedkeuring/afwijzing (queue in email_outbox)
    - Implementeer dispatch bij nieuwe aanvraag (naar manager(s) van team)
    - Implementeer herinnering-job: check onbehandelde aanvragen >3 werkdagen
    - Respecteer opt-out: essentiële mails altijd, herinneringen alleen bij opt-in
    - Alle mails in het Nederlands met correcte onderwerpen
    - Maak templates bewerkbaar via `/instellingen/email`
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5, 13.6, 13.7_

  - [x]* 15.2 Write property test voor verlof-herinnering opt-out
    - **Property 15: Verlof-herinnering respecteert opt-out en threshold**
    - **Validates: Requirements 13.5, 13.7**

- [x] 16. Fase 3: Verlof-saldo configuratie in accountbeheer
  - [x] 16.1 Uitbreiden accountbeheer met verlof-saldo configuratie
    - Owner/manager kan `annual_leave_days` instellen per medewerker
    - Validatie: nullable, integer, min:0, max:365
    - Audit-event: LEAVE_ALLOWANCE_UPDATED bij wijziging
    - Toon saldo-overzicht (recht, opgenomen, resterend) bij medewerker-details
    - _Requirements: 9.1, 9.2, 9.5, 9.6, 9.7, 9.8, 9.10_

- [x] 17. Final checkpoint — Alle fasen gereed
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- De stack is PHP/Laravel/Livewire/Alpine.js — alle code-voorbeelden in PHP en JavaScript
- Bestaande services (WorkEntriesService, AtwService, CopyWeekService) worden hergebruikt
- ApexCharts wordt via CDN geladen (geen npm build nodig op shared hosting)

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3", "1.4", "1.5", "1.6"] },
    { "id": 1, "tasks": ["1.7", "3.1", "3.3"] },
    { "id": 2, "tasks": ["3.2", "3.4", "4.1", "5.1"] },
    { "id": 3, "tasks": ["4.2", "4.3", "4.4"] },
    { "id": 4, "tasks": ["7.1", "8.1", "12.1"] },
    { "id": 5, "tasks": ["7.2", "7.3", "7.5", "8.2", "9.1", "12.2"] },
    { "id": 6, "tasks": ["7.4", "9.2", "10.1", "12.3"] },
    { "id": 7, "tasks": ["12.4", "13.1", "14.1"] },
    { "id": 8, "tasks": ["13.2", "13.3", "15.1", "16.1"] },
    { "id": 9, "tasks": ["15.2"] }
  ]
}
```
