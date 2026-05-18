# Requirements Document

## Introduction

Dit document beschrijft de requirements voor de frontend-verbetering van de La Vita Urenregistratie applicatie. De backend is grotendeels compleet en live op https://ur.la-vitatrading.nl/. De focus ligt op drie prioriteitsfasen: (1) Dashboard Revolutie, (2) Uren-invoer Verbeteren, en (3) Verlof-systeem Uitbreiden. Deze fasen transformeren de huidige kale UI naar een enterprise-grade, moderne en informatieve gebruikerservaring.

De applicatie draait op Laravel 13 + Livewire 3.6 + Tailwind CSS + Alpine.js + MySQL, gehost op Cloud86 shared hosting (cPanel, FTP, geen SSH).

## Glossary

- **System**: De La Vita Urenregistratie webapplicatie (Livewire frontend + Laravel backend).
- **Dashboard**: Het startscherm na inloggen, rol-afhankelijk (manager/owner vs employee).
- **Owner**: Organisatie-eigenaar met volledige rechten.
- **Manager**: Teamleider met rechten beperkt tot eigen team.
- **Employee**: Medewerker die eigen uren bekijkt en verlof aanvraagt.
- **Boekhouder**: Read-only rol op uren en rapportages.
- **WorkEntry**: Werkregel in tabel `work_entries` (één dienst per dag).
- **KPI_Card**: Visuele kaart op het dashboard met een kernmetric, trend-indicator en optionele sparkline.
- **Sparkline**: Compacte inline-grafiek die een trend over tijd toont zonder assen.
- **Toast**: Kortstondige notificatie-melding die automatisch verdwijnt na configureerbare tijd.
- **Copy_Week**: Functionaliteit om werkregels van een bronweek te dupliceren naar een doelweek.
- **ATW**: Arbeidstijdenwet — Nederlandse wetgeving voor werktijden en rusttijden.
- **Verlof_Saldo**: Het verschil tussen opgebouwd verlofrecht en opgenomen verlofdagen.
- **Half_Dag_Verlof**: Verlofaanvraag voor alleen ochtend (tot 12:30) of middag (vanaf 12:30).
- **Verlofkalender**: Maandweergave met alle medewerkers en hun verlof/ziekte/feestdagen.
- **ApexCharts**: JavaScript charting-library voor interactieve grafieken (via CDN).
- **Design_Tokens**: Gestandaardiseerde kleuren, typografie en spacing uit tailwind.config.js.
- **Lazy_Loading**: Livewire `lazy` attribute waarmee zware componenten pas laden na initial paint.
- **Skeleton_Loading**: Placeholder-animatie die de layout van content simuleert tijdens het laden.
- **Wire_Poll**: Livewire `wire:poll` directive voor periodieke server-updates zonder page refresh.
- **Progress_Bar**: Visuele balk die voortgang toont (bijv. uren vs contracturen).
- **Color_Coding**: Kleurcodering per werkregel-type: WORK=#00d4a4 (groen), SICK=#ef4444 (rood), LEAVE=#3b82f6 (blauw), HOLIDAY=#8b5cf6 (paars).
- **Grand_Total**: Totaalsom rechtsonder in het weekoverzicht (alle medewerkers × alle dagen).

## Niet-functionele eisen (van toepassing op alle requirements)

- **NFR-1 Toegankelijkheid**: Alle UI-schermen voldoen aan WCAG 2.1 niveau AA (contrast ≥4.5:1, aria-labels, toetsenbordnavigatie, focus-ring `2px #00d4a4`).
- **NFR-2 Browsers**: Chrome, Firefox, Edge en Safari — desktop én mobiel — laatste stabiele versie en N-1.
- **NFR-3 Mobile-first**: Layout responsief; desktop 3-koloms (sidebar 240px, content, toc 200px), tablet 2-koloms, mobiel 1-koloms met gutter 24px.
- **NFR-4 Design tokens**: UI gebruikt uitsluitend design tokens (brand-green #00d4a4, Inter font, Geist Mono, canvas/surface/ink/steel/hairline kleuren).
- **NFR-5 Performance**: Eerste contentful paint < 2 seconden; lazy-load zware widgets; debounce Livewire-roundtrips (250ms minimum).
- **NFR-6 Taal**: Alle UI-labels, foutmeldingen, bevestigingen en tooltips in het Nederlands.
- **NFR-7 Bestaande componenten**: Hergebruik `<x-ui.card>`, `<x-ui.button>`, `<x-ui.text-input>`, `<x-ui.status-badge>` waar mogelijk; nieuwe atoms alleen wanneer noodzakelijk.
- **NFR-8 Geen N+1**: Alle database-queries gebruiken eager loading (`with()`) en zijn gepagineerd bij >20 items.
- **NFR-9 Organisatie-scope**: Alle data is gefilterd op `organization_id`; geen data-lekkage tussen organisaties.
- **NFR-10 Audit-trail**: Elke mutatie (create/update/delete) schrijft een audit-event naar `audit_events`.

## Requirements

### Requirement 1: Manager/Owner Dashboard met KPI-cards en grafieken (Fase 1 — MUST)

**User Story:** Als manager of owner wil ik een informatief dashboard zien met KPI-cards, grafieken en activiteit-feed, zodat ik in één oogopslag de status van mijn team/organisatie kan beoordelen.

#### Acceptance Criteria

1. WHEN een gebruiker met rol `owner`, `manager` of `boekhouder` het dashboard opent op `/dashboard`, THE System SHALL een persoonlijke begroeting tonen met de volledige naam van de gebruiker en de huidige datum in het formaat "dag DD maand JJJJ" (Nederlands).
2. THE System SHALL KPI_Cards tonen voor: (a) totaal uren deze week met trend-pijl (omhoog/omlaag) ten opzichte van vorige week, (b) aanwezigheidspercentage met donut-chart, (c) openstaande verlofaanvragen met urgentie-indicator, (d) ATW-meldingen gesplitst in critical (rood) en warning (oranje), (e) openstaande bezwaren, (f) ziekteverzuim-percentage.
3. THE System SHALL een staafgrafiek (bar chart) tonen via ApexCharts met uren per dag (ma-zo) voor de huidige week, gegroepeerd per team (owner) of totaal (manager).
4. THE System SHALL een activiteit-feed tonen met de laatste 10 acties binnen de organisatie-scope (uren ingevoerd, verlof aangevraagd, bezwaar ingediend), gesorteerd op `created_at` aflopend.
5. THE System SHALL snelactie-knoppen tonen: "Uren invoeren" (opent invoer-modal), "Verlof goedkeuren" (badge met aantal openstaand), "Bezwaar beoordelen" (badge met aantal openstaand).
6. WHILE het dashboard geladen wordt, THE System SHALL Skeleton_Loading placeholders tonen voor elke KPI_Card en de grafiek.
7. THE System SHALL de KPI-tellingen elke 30 seconden verversen via Wire_Poll zonder volledige page refresh.
8. WHEN de manager-rol actief is, THE System SHALL alle data filteren op het eigen team (`user.team_id`); WHEN de owner-rol actief is, THE System SHALL data tonen voor alle teams binnen de organisatie.
9. THE System SHALL elke KPI_Card renderen als `<x-ui.card>` met een gekleurde accent-rand (brand-green voor positieve trend, danger voor negatieve trend).
10. THE System SHALL de staafgrafiek lazy-loaden via Livewire `lazy` attribute zodat de initial paint niet geblokkeerd wordt door chart-rendering.

### Requirement 2: Employee Dashboard met voortgang en verlof-saldo (Fase 1 — MUST)

**User Story:** Als medewerker wil ik op mijn dashboard mijn uren-voortgang, verlof-saldo en openstaande bezwaren zien, zodat ik mijn werkweek en verlof kan overzien.

#### Acceptance Criteria

1. WHEN een gebruiker met rol `employee` het dashboard opent op `/dashboard`, THE System SHALL een persoonlijke begroeting tonen met volledige naam en huidige datum (Nederlands).
2. THE System SHALL een Progress_Bar tonen met "Mijn uren deze week" als verhouding van geregistreerde netto-minuten ten opzichte van contracturen (indien geconfigureerd), met numerieke weergave in uren:minuten formaat.
3. THE System SHALL het Verlof_Saldo tonen als visuele Progress_Bar met opgebouwde dagen, opgenomen dagen en resterend saldo.
4. WHEN het Verlof_Saldo minder dan 3 dagen resterend bevat, THE System SHALL een waarschuwings-badge tonen met tekst "Bijna op".
5. THE System SHALL een lijst tonen van eigen openstaande bezwaren met status-badge (open/akkoord/afgewezen) en datum.
6. THE System SHALL snelactie-knoppen tonen: "Uren invoeren" en "Verlof aanvragen".
7. THE System SHALL een mini-weekoverzicht tonen met per dag (ma-zo) de geregistreerde uren als horizontale balk, met Color_Coding per type.
8. WHILE het dashboard geladen wordt, THE System SHALL Skeleton_Loading placeholders tonen voor elke widget.
9. THE System SHALL notificaties tonen wanneer verlof is goedgekeurd of afgewezen, of wanneer een werkregel is gewijzigd door een manager.
10. IF de medewerker geen contracturen geconfigureerd heeft, THE System SHALL de Progress_Bar verbergen en alleen het absolute urentotaal tonen.

### Requirement 3: Toast-notificatiesysteem (Fase 1 — MUST)

**User Story:** Als gebruiker wil ik na elke actie (opslaan, verwijderen, fout) een kortstondige visuele bevestiging zien, zodat ik weet dat mijn actie is verwerkt.

#### Acceptance Criteria

1. THE System SHALL een `<x-ui.toast>` component aanbieden met varianten: `success` (groen), `error` (rood), `warning` (oranje), `info` (blauw).
2. WHEN een mutatie succesvol is uitgevoerd (werkregel opgeslagen, verlof aangevraagd, bezwaar ingediend), THE System SHALL een Toast tonen met variant `success` en een Nederlandstalig bevestigingsbericht.
3. WHEN een mutatie mislukt door validatie of serverfout, THE System SHALL een Toast tonen met variant `error` en het foutbericht in het Nederlands.
4. THE System SHALL Toasts positioneren rechtsboven in het viewport (desktop) of bovenmidden (mobiel), met een slide-in animatie van rechts.
5. THE System SHALL elke Toast na 5 seconden automatisch laten verdwijnen met een fade-out animatie, tenzij de gebruiker eroverheen hovert (dan pauzeert de timer).
6. WHEN meerdere Toasts tegelijk actief zijn, THE System SHALL deze verticaal stapelen met 8px tussenruimte, maximaal 3 zichtbaar tegelijk.
7. THE System SHALL elke Toast voorzien van een sluit-knop (×) met `aria-label="Melding sluiten"` voor toetsenbordgebruikers.
8. THE System SHALL de Toast-component implementeren via Alpine.js voor client-side animatie en timing, getriggerd door Livewire `dispatch('toast', ...)` events.
9. IF een Toast variant `error` heeft, THE System SHALL de auto-dismiss timer verlengen naar 8 seconden zodat de gebruiker meer leestijd heeft.
10. THE System SHALL Toasts renderen met `role="alert"` en `aria-live="polite"` zodat screenreaders de melding aankondigen.

### Requirement 4: Weekoverzicht kleurcodering en totalen (Fase 2 — MUST)

**User Story:** Als manager of owner wil ik in het weekoverzicht direct zien welk type registratie elke cel bevat (werk/ziek/verlof/feestdag) via kleurcodering, en wil ik dag- en weektotalen zien, zodat ik snel de status van mijn team kan beoordelen.

#### Acceptance Criteria

1. THE System SHALL elke cel in het weekoverzicht (`/uren/week`) kleuren volgens het type van de werkregel: WORK = achtergrond `bg-emerald-50` met rand `border-l-4 border-brand-green`, SICK = achtergrond `bg-red-50` met rand `border-l-4 border-danger`, LEAVE = achtergrond `bg-blue-50` met rand `border-l-4 border-blue-500`, HOLIDAY = achtergrond `bg-purple-50` met rand `border-l-4 border-purple-500`.
2. WHEN een cel geen werkregel bevat, THE System SHALL deze tonen als lichtgrijs gestippeld (`border-dashed border-hairline bg-canvas`) met een klikbaar oppervlak dat de invoer-modal opent.
3. THE System SHALL per medewerker-rij een totaal-kolom tonen rechts met de weeksom in formaat "HH:mm" (uren:minuten).
4. THE System SHALL per dag-kolom een totaal-rij tonen onderaan met de dagsom van alle zichtbare medewerkers in formaat "HH:mm".
5. THE System SHALL rechtsonder een Grand_Total cel tonen met de som van alle uren van alle zichtbare medewerkers voor de gehele week.
6. WHEN een gebruiker met de muis over een cel hovert, THE System SHALL een tooltip tonen met tekst "Klik om uren in te voeren voor [naam] op [dag datum]" (voor lege cellen) of "[type]: [HH:mm] netto" (voor gevulde cellen).
7. WHEN een cel een ATW-waarschuwing bevat (warning of critical), THE System SHALL een oranje rand (`border-warning`) tonen rond de cel met een waarschuwingsicoon.
8. THE System SHALL de totaal-kolom en totaal-rij visueel onderscheiden met een lichtere achtergrond (`bg-surface`) en vetgedrukt lettertype.
9. WHEN het weekoverzicht meer dan 15 medewerkers bevat, THE System SHALL de tabel horizontaal scrollbaar maken op mobiel met een sticky eerste kolom (namen).
10. THE System SHALL de kleurcodering consistent toepassen ongeacht of de werkregel `is_finalized = true` of `false` is.

### Requirement 5: Copy-week functionaliteit in de UI (Fase 2 — MUST)

**User Story:** Als manager of owner wil ik via een knop in het weekoverzicht de werkregels van de vorige week kopiëren naar de huidige week, zodat ik bij vaste roosters tijd bespaar.

#### Acceptance Criteria

1. THE System SHALL een "Kopieer vorige week" knop tonen in de toolbar van het weekoverzicht, alleen zichtbaar voor rollen `owner` en `manager`.
2. WHEN de gebruiker op "Kopieer vorige week" klikt, THE System SHALL een bevestigingsmodal tonen met tekst "Wil je de werkregels van [vorige week maandag - zondag] kopiëren naar [huidige week maandag - zondag]?" en knoppen "Kopiëren" (primary) en "Annuleren" (secondary).
3. WHEN de gebruiker "Kopiëren" bevestigt, THE System SHALL `POST /api/internal/work-entries/copy-week` aanroepen voor elke medewerker in de zichtbare scope met `source_week_start` = vorige maandag en `target_week_start` = huidige maandag.
4. WHEN de copy-week operatie succesvol is, THE System SHALL een Toast (success) tonen met "Week gekopieerd: [X] regels aangemaakt" en het weekoverzicht verversen.
5. IF de copy-week operatie regels overslaat (duplicaten of ATW-blokkades), THE System SHALL een Toast (warning) tonen met "Week gekopieerd met [Y] overgeslagen regels" en een uitklapbaar detail-overzicht in de modal met reden per overgeslagen regel.
6. IF de bronweek geen werkregels bevat, THE System SHALL een Toast (info) tonen met "Vorige week bevat geen werkregels om te kopiëren" en de modal sluiten.
7. WHILE de copy-week operatie loopt, THE System SHALL de "Kopiëren" knop deactiveren en een laad-spinner tonen.
8. IF de gebruiker rol `employee` of `boekhouder` heeft, THE System SHALL de "Kopieer vorige week" knop niet renderen.

### Requirement 6: Verbeterde invoer-modal met slimme defaults (Fase 2 — MUST)

**User Story:** Als manager of owner wil ik sneller uren invoeren via een geoptimaliseerde modal met slimme defaults, live berekening en keyboard shortcuts, zodat de dagelijkse registratie minder tijd kost.

#### Acceptance Criteria

1. WHEN de invoer-modal opent, THE System SHALL auto-focus plaatsen op het begintijd-veld en de tab-volgorde optimaliseren als: begintijd → eindtijd → pauze → project → kostenplaats → notitie → opslaan.
2. WHEN de medewerker de vorige werkdag een dienst had (bijv. 09:00-17:30), THE System SHALL die tijden als placeholder/suggestie tonen in de begin- en eindtijd-velden met tekst "Vorige dag: 09:00 - 17:30".
3. THE System SHALL live de netto-minuten berekenen terwijl de gebruiker typt (begintijd, eindtijd, pauze) en deze prominent tonen in een apart veld met label "Netto werktijd" in formaat "X uur Y minuten".
4. WHEN de gebruiker Enter indrukt terwijl de modal open is en alle verplichte velden gevuld zijn, THE System SHALL het formulier opslaan (equivalent aan klik op "Opslaan").
5. WHEN de gebruiker Escape indrukt terwijl de modal open is, THE System SHALL de modal sluiten zonder op te slaan.
6. THE System SHALL vóór opslaan een ATW-validatie uitvoeren via `POST /api/internal/work-entries/validate-atw` en het resultaat tonen: warnings als oranje banner boven de opslaan-knop, critical errors als rode banner die de opslaan-knop blokkeert.
7. WHEN de werkregel succesvol is opgeslagen, THE System SHALL de modal sluiten, een Toast (success) tonen met "Werkregel opgeslagen", en het weekoverzicht verversen via Livewire event `entry-saved`.
8. IF een validatiefout optreedt (ATW critical, ontbrekende velden), THE System SHALL de foutmelding in het Nederlands tonen bij het betreffende veld en de modal open houden.
9. THE System SHALL een project-selector en kostenplaats-selector tonen als dropdowns, gevuld met actieve projecten/kostenplaatsen van de organisatie.
10. THE System SHALL de modal renderen met `role="dialog"`, `aria-modal="true"` en focus-trap zodat toetsenbordgebruikers niet buiten de modal kunnen navigeren.

### Requirement 7: Mijn Week visuele tijdlijn (Fase 2 — SHOULD)

**User Story:** Als medewerker wil ik mijn werkweek zien als visuele tijdlijn met horizontale balken per dag, zodat ik in één oogopslag mijn werkpatroon kan overzien.

#### Acceptance Criteria

1. THE System SHALL op `/uren/mijn-week` per dag (ma-zo) een horizontale tijdlijnbalk tonen die het bereik 06:00-22:00 representeert, met de geregistreerde dienst als gekleurde balk binnen dat bereik.
2. THE System SHALL de dienst-balk kleuren volgens Color_Coding: WORK = brand-green, SICK = danger, LEAVE = blauw, HOLIDAY = paars.
3. THE System SHALL bovenaan de pagina het weektotaal prominent tonen in formaat "XX uur YY minuten" met een vergelijking ten opzichte van contracturen (indien geconfigureerd): "32:00 / 40:00 uur".
4. WHEN een werkregel een actief bezwaar heeft, THE System SHALL naast de tijdlijnbalk een bezwaar-icoon tonen met kleurcodering: open = oranje, akkoord = groen, afgewezen = rood.
5. THE System SHALL per dag de begin- en eindtijd als tekst tonen naast de tijdlijnbalk in formaat "09:00 - 17:30 (8u netto)".
6. WHEN een dag geen werkregel bevat, THE System SHALL een lege balk tonen met gestippelde rand en tekst "Geen registratie" in steel-kleur.
7. THE System SHALL week-navigatie bieden (vorige/volgende week, vandaag-knop) consistent met het weekoverzicht.
8. WHEN de medewerker op een tijdlijnbalk klikt, THE System SHALL de details van die werkregel tonen in een uitklapbaar paneel met alle velden (begin, eind, pauze, netto, type, project, notitie).

### Requirement 8: Verlofkalender (Fase 3 — SHOULD)

**User Story:** Als manager of owner wil ik een maandkalender zien met alle verlof, ziekte en feestdagen van mijn team, zodat ik de bezetting kan plannen en verlofaanvragen kan beoordelen.

#### Acceptance Criteria

1. THE System SHALL een verlofkalender-pagina aanbieden op `/verlof/kalender` toegankelijk voor rollen `owner`, `manager` en `boekhouder`.
2. THE System SHALL een maandweergave tonen als grid met rijen = medewerkers en kolommen = dagen van de maand, met Color_Coding per type: SICK = rood, LEAVE = blauw, HOLIDAY = grijs, leeg = wit.
3. WHEN een manager de kalender opent, THE System SHALL alleen medewerkers van het eigen team tonen; WHEN een owner de kalender opent, THE System SHALL alle medewerkers van de organisatie tonen met optioneel team-filter.
4. THE System SHALL feestdagen markeren in de kolomheader met een grijze achtergrond en de feestdagnaam als tooltip.
5. WHEN een owner of manager op een lege dag-cel klikt, THE System SHALL een snelle verlof-invoer-modal openen met de medewerker en datum vooringevuld.
6. THE System SHALL maand-navigatie bieden (vorige/volgende maand, vandaag-knop) en het huidige maand/jaar prominent tonen.
7. WHEN de kalender meer dan 20 medewerkers bevat, THE System SHALL de tabel verticaal scrollbaar maken met een sticky header-rij (dagen).
8. IF de gebruiker rol `employee` heeft, THE System SHALL de verlofkalender niet tonen in de navigatie en HTTP 403 retourneren bij directe toegang.
9. THE System SHALL per medewerker-rij een totaal-kolom tonen met het aantal verlofdagen in de zichtbare maand.
10. THE System SHALL de kalender lazy-loaden via Livewire `lazy` attribute om de initial paint niet te blokkeren.

### Requirement 9: Verlof-saldo tracking (Fase 3 — SHOULD)

**User Story:** Als medewerker wil ik mijn verlof-saldo zien (opgebouwd vs opgenomen), zodat ik weet hoeveel verlofdagen ik nog kan opnemen.

#### Acceptance Criteria

1. THE System SHALL een kolom `users.annual_leave_days` (integer, nullable, default null) toevoegen voor het jaarlijks verlofrecht per medewerker.
2. WHEN een owner of manager het verlofrecht van een medewerker configureert via accountbeheer, THE System SHALL de waarde opslaan in `users.annual_leave_days` en een audit-event `LEAVE_ALLOWANCE_UPDATED` schrijven.
3. THE System SHALL het opgenomen verlof berekenen als het aantal werkregels met `type = LEAVE` en `is_finalized = true` in het huidige kalenderjaar voor de betreffende medewerker.
4. THE System SHALL het Verlof_Saldo berekenen als: `annual_leave_days - opgenomen_dagen = resterend`.
5. WHEN een medewerker het employee-dashboard opent en `annual_leave_days` is geconfigureerd, THE System SHALL het Verlof_Saldo tonen als Progress_Bar met labels "Opgenomen: X dagen" en "Resterend: Y dagen".
6. WHEN het resterend saldo ≤ 3 dagen is, THE System SHALL de Progress_Bar in waarschuwingskleur (oranje) tonen met badge "Bijna op".
7. WHEN het resterend saldo ≤ 0 dagen is, THE System SHALL de Progress_Bar in danger-kleur (rood) tonen met badge "Saldo op".
8. IF `annual_leave_days` niet is geconfigureerd (null), THE System SHALL de verlof-saldo widget verbergen op het dashboard en geen waarschuwingen genereren.
9. THE System SHALL het verlof-saldo ook tonen op de verlof-aanvraag pagina zodat de medewerker het resterende saldo ziet voordat een aanvraag wordt ingediend.
10. WHEN een owner of manager het verlof-saldo van een medewerker bekijkt via accountbeheer, THE System SHALL het saldo tonen met opbouw-details (recht, opgenomen, resterend).

### Requirement 10: Half-dag verlof en verlof annuleren (Fase 3 — SHOULD)

**User Story:** Als medewerker wil ik een halve dag verlof kunnen aanvragen (ochtend of middag) en een nog niet-goedgekeurde aanvraag kunnen annuleren, zodat ik flexibel met mijn verlof kan omgaan.

#### Acceptance Criteria

1. THE System SHALL bij verlof-aanvraag een optie "Halve dag" aanbieden met keuze "Ochtend (tot 12:30)" of "Middag (vanaf 12:30)".
2. WHEN een medewerker "Halve dag - Ochtend" selecteert, THE System SHALL de werkregel aanmaken met `start_at = 00:00`, `end_at = 12:30`, `type = LEAVE` en `net_minutes = 0`.
3. WHEN een medewerker "Halve dag - Middag" selecteert, THE System SHALL de werkregel aanmaken met `start_at = 12:30`, `end_at = 23:59`, `type = LEAVE` en `net_minutes = 0`.
4. THE System SHALL een Half_Dag_Verlof tellen als 0,5 dag bij de berekening van het Verlof_Saldo.
5. WHEN een medewerker een eigen verlofaanvraag wil annuleren die status `PENDING` (nog niet goedgekeurd) heeft, THE System SHALL een "Annuleren" knop tonen naast de aanvraag.
6. WHEN de medewerker op "Annuleren" klikt, THE System SHALL een bevestigingsmodal tonen met "Weet je zeker dat je deze verlofaanvraag wilt annuleren?" en knoppen "Ja, annuleren" (danger) en "Nee, behouden" (secondary).
7. WHEN de annulering bevestigd wordt, THE System SHALL de werkregel soft-deleten, een audit-event `LEAVE_CANCELLED` schrijven, en een Toast (success) tonen met "Verlofaanvraag geannuleerd".
8. IF de verlofaanvraag reeds goedgekeurd is (`is_finalized = true`), THE System SHALL de "Annuleren" knop niet tonen en bij directe API-aanroep HTTP 409 retourneren met code `LEAVE_ALREADY_APPROVED`.
9. THE System SHALL bij annulering het Verlof_Saldo direct bijwerken (opgenomen -1 of -0,5 dag).
10. THE System SHALL de verlof-aanvraag-modal voorzien van een duidelijke visuele scheiding tussen "Hele dag" en "Halve dag" opties via radio-buttons met labels.

### Requirement 11: Verlof-types uitbreiden (Fase 3 — SHOULD)

**User Story:** Als owner wil ik meerdere verlof-types configureren (vakantie, bijzonder verlof, onbetaald verlof, ouderschapsverlof), zodat de organisatie verschillende soorten afwezigheid apart kan registreren en rapporteren.

#### Acceptance Criteria

1. THE System SHALL een tabel `leave_types` aanmaken met kolommen `id, organization_id, code (uniek per org), name, description, max_days_per_year (nullable), counts_towards_balance (bool, default true), is_active (bool, default true), created_at, updated_at`.
2. THE System SHALL standaard verlof-types seeden bij organisatie-aanmaak: "Vakantieverlof" (counts_towards_balance=true), "Bijzonder verlof" (counts_towards_balance=false), "Onbetaald verlof" (counts_towards_balance=false), "Ouderschapsverlof" (counts_towards_balance=false).
3. WHEN een owner verlof-types beheert via `/instellingen/verlof-types`, THE System SHALL CRUD-functionaliteit bieden (aanmaken, bewerken, deactiveren) met validatie op unieke code per organisatie.
4. THE System SHALL een kolom `work_entries.leave_type_id` (FK `leave_types.id`, nullable) toevoegen die alleen gevuld wordt wanneer `type = LEAVE`.
5. WHEN een medewerker verlof aanvraagt, THE System SHALL een dropdown tonen met alle actieve verlof-types van de organisatie.
6. THE System SHALL bij Verlof_Saldo-berekening alleen verlof-types meetellen waarvoor `counts_towards_balance = true`.
7. WHEN een verlof-type `max_days_per_year` geconfigureerd heeft en de medewerker dat maximum bereikt, THE System SHALL een waarschuwing tonen "Maximum [type] bereikt ([X] dagen per jaar)" maar de aanvraag niet blokkeren (soft limit).
8. THE System SHALL in rapportages verlof uitsplitsen per verlof-type met aparte totalen.
9. IF een verlof-type gedeactiveerd wordt (`is_active = false`), THE System SHALL bestaande werkregels met dat type behouden maar het type niet meer aanbieden bij nieuwe aanvragen.
10. THE System SHALL de verlofkalender (Requirement 8) uitbreiden met een legenda die alle actieve verlof-types toont met hun kleurcodering.

### Requirement 12: UI-componenten uitbreiding (Fase 1-2 — MUST)

**User Story:** Als ontwikkelaar wil ik herbruikbare UI-componenten (toast, modal, progress, skeleton, stat-card) beschikbaar hebben, zodat alle schermen consistent en snel gebouwd kunnen worden.

#### Acceptance Criteria

1. THE System SHALL een `<x-ui.toast>` Blade-component aanbieden met props: `variant` (success/error/warning/info), `message` (string), `duration` (milliseconden, default 5000).
2. THE System SHALL een `<x-ui.modal>` Blade-component aanbieden met props: `title` (string), `size` (sm/md/lg), `show` (bool via Alpine.js), met open/close animatie (scale + fade, 200ms) en focus-trap.
3. THE System SHALL een `<x-ui.progress>` Blade-component aanbieden met props: `value` (0-100), `max` (default 100), `variant` (success/warning/danger), `label` (string), `show-percentage` (bool).
4. THE System SHALL een `<x-ui.skeleton>` Blade-component aanbieden met props: `type` (text/card/chart/avatar), `lines` (integer, voor type=text), die een pulserende placeholder-animatie toont.
5. THE System SHALL een `<x-ui.stat-card>` Blade-component aanbieden met props: `title` (string), `value` (string/number), `trend` (up/down/neutral), `trend-value` (string), `icon` (optioneel), gerenderd als `<x-ui.card>` met trend-indicator.
6. THE System SHALL een `<x-ui.avatar>` Blade-component aanbieden met props: `name` (string, voor initialen-generatie), `size` (sm/md/lg), `src` (optioneel, voor foto), die initialen toont in een gekleurde cirkel wanneer geen foto beschikbaar is.
7. THE System SHALL alle nieuwe componenten documenteren in `resources/views/components/ui/` met PHPDoc-blokken die props, varianten en gebruiksvoorbeelden beschrijven.
8. THE System SHALL alle nieuwe componenten voorzien van WCAG 2.1 AA compliance: correcte aria-attributen, toetsenbordnavigatie, en contrast ≥4.5:1.
9. THE System SHALL de `<x-ui.modal>` component voorzien van `role="dialog"`, `aria-modal="true"`, `aria-labelledby` (verwijzend naar de title), en Escape-toets om te sluiten.
10. THE System SHALL de `<x-ui.progress>` component voorzien van `role="progressbar"`, `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax`.

### Requirement 13: Verlof-notificaties per e-mail (Fase 3 — SHOULD)

**User Story:** Als medewerker wil ik per e-mail geïnformeerd worden wanneer mijn verlofaanvraag is goedgekeurd of afgewezen, en als manager wil ik een mail ontvangen bij nieuwe aanvragen, zodat niemand een actie mist.

#### Acceptance Criteria

1. WHEN een manager of owner een verlofaanvraag goedkeurt, THE System SHALL een e-mail van type `leave_approved` queueën in `email_outbox` voor de betreffende medewerker met placeholders `{{ full_name }}, {{ leave_date }}, {{ leave_type }}, {{ approved_by }}`.
2. WHEN een manager of owner een verlofaanvraag afwijst, THE System SHALL een e-mail van type `leave_rejected` queueën met placeholders `{{ full_name }}, {{ leave_date }}, {{ leave_type }}, {{ rejected_by }}, {{ reason }}`.
3. WHEN een medewerker een nieuwe verlofaanvraag indient, THE System SHALL een e-mail van type `leave_requested` queueën voor de manager(s) van het team met placeholders `{{ employee_name }}, {{ leave_date }}, {{ leave_type }}, {{ note }}`.
4. THE System SHALL de templates `leave_approved`, `leave_rejected` en `leave_requested` toevoegen aan de bewerkbare e-mailtemplates in `/instellingen/email`.
5. IF een verlofaanvraag langer dan 3 werkdagen onbehandeld is, THE System SHALL een herinneringsmail `leave_reminder` queueën voor de manager(s) met tekst "Er staat een verlofaanvraag van [naam] open sinds [datum]".
6. THE System SHALL alle verlof-gerelateerde mails in het Nederlands versturen met onderwerpen: "Verlof goedgekeurd", "Verlof afgewezen", "Nieuwe verlofaanvraag", "Herinnering: openstaande verlofaanvraag".
7. IF de medewerker `email_reminders_opt_in = false` heeft, THE System SHALL essentiële verlof-mails (goedkeuring/afwijzing) nog steeds versturen maar herinneringen overslaan.

### Requirement 14: Print-functionaliteit weekoverzicht (Fase 2 — COULD)

**User Story:** Als manager wil ik het weekoverzicht kunnen printen als overzichtelijk document, zodat ik een fysiek overzicht kan bewaren of delen.

#### Acceptance Criteria

1. THE System SHALL een "Printen" knop tonen in de toolbar van het weekoverzicht naast de "Kopieer vorige week" knop.
2. WHEN de gebruiker op "Printen" klikt, THE System SHALL `window.print()` triggeren met een print-specifieke CSS stylesheet die de tabel optimaal formatteert voor A4 landscape.
3. THE System SHALL in de print-weergave de navigatie, sidebar en toolbar verbergen en alleen de weektabel met totalen, weeknummer en organisatienaam tonen.
4. THE System SHALL in de print-weergave de kleurcodering behouden via `@media print` CSS regels met `-webkit-print-color-adjust: exact`.
5. THE System SHALL in de print-header de tekst "Weekoverzicht — Week [nr] — [organisatienaam]" en de printdatum tonen.
6. THE System SHALL de print-weergave voorzien van een footer met "Gegenereerd door La Vita Urenregistratie op [datum tijd]".

### Requirement 15: Weekoverzicht print-knop en ATW-indicator (Fase 2 — COULD)

**User Story:** Als manager wil ik in het weekoverzicht direct zien welke medewerkers een ATW-waarschuwing hebben, zodat ik proactief kan ingrijpen.

#### Acceptance Criteria

1. WHEN een medewerker in de zichtbare week een actieve ATW-violation heeft (niet-superseded), THE System SHALL naast de naam van die medewerker een waarschuwingsicoon tonen: oranje driehoek voor `severity = warning`, rood uitroepteken voor `severity = critical`.
2. WHEN de gebruiker over het ATW-icoon hovert, THE System SHALL een tooltip tonen met het type overtreding: "Weekwaarschuwing: [X]u van max 48u" of "Weeklimiet overschreden: [X]u van max 60u" of "Rusttijd te kort: [X]u van min 11u".
3. THE System SHALL de ATW-indicatoren ophalen in dezelfde query als de status-matrix om extra database-roundtrips te voorkomen.
4. WHEN een cel een werkregel bevat die een ATW-violation heeft getriggerd, THE System SHALL die specifieke cel markeren met een oranje of rode rand (afhankelijk van severity).
5. THE System SHALL de ATW-indicatoren alleen tonen voor rollen `owner`, `manager` en `boekhouder`; niet voor `employee` (die ziet ATW-feedback in de invoer-modal).
