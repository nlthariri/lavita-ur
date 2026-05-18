# Tech Team Briefing — La Vita Urenregistratie

## Status: LIVE op https://ur.la-vitatrading.nl/
## Datum: 18 mei 2026
## Stack: Laravel 13 + Livewire 3.6 + Tailwind CSS + Alpine.js + MySQL
## Ambitie: Enterprise-grade urenregistratie — de BESTE oplossing, geen compromissen

---

## 1. VISIE & AMBITIE

Dit wordt een **miljarden-enterprise-grade** urenregistratiesysteem. Elke feature moet de beste zijn. Elke interactie moet vloeiend zijn. Elke pagina moet modern, snel en intuïtief zijn. "Het werkt al zo" is geen argument — als het beter kan, moet het beter.

**Kernprincipes:**
- Excellentie boven snelheid
- Gebruiksvriendelijkheid boven complexiteit
- Volledigheid boven MVP
- Moderniteit boven conservatisme

---

## 2. HUIDIGE SITUATIE (eerlijk)

De applicatie is live maar in een **onvoltooide staat**. De backend is grotendeels compleet, maar de frontend is op veel plekken arm, incompleet en niet op enterprise-niveau.

### Wat WEL werkt:
- ✅ Login + MFA flow
- ✅ Accountbeheer (CRUD + MFA toggle + verwijderen)
- ✅ E-mail systeem (outbox queue + cron + 11 templates)
- ✅ Verlof aanvragen + goedkeuren/afwijzen
- ✅ Bezwaren indienen + beoordelen
- ✅ ATW-validatie bij uren-invoer
- ✅ Rapportages PDF/Excel export
- ✅ Instellingen (organisatie, teams, projecten, feestdagen, email)
- ✅ Profiel bewerken + wachtwoord wijzigen
- ✅ Rol-gebaseerde navigatie
- ✅ Audit-trail op alle mutaties

### Wat NIET werkt of ONTBREEKT:
- ❌ Dashboard is extreem kaal (alleen tellingen, geen grafieken)
- ❌ Geen agenda/kalender-view
- ❌ Geen notificatie-systeem in de UI
- ❌ Geen real-time updates
- ❌ Geen dark mode
- ❌ Geen animaties of transities
- ❌ Geen onboarding voor nieuwe gebruikers
- ❌ Geen globale zoekfunctie
- ❌ Weekoverzicht heeft geen totalen, geen kleurcodering
- ❌ Geen verlofkalender of verlof-saldo
- ❌ Geen timer/stopwatch voor real-time registratie
- ❌ Mobiele ervaring is basis (niet gepolijst)
- ❌ Geen PWA (niet installeerbaar op telefoon)
- ❌ Rapportages alleen als download, niet visueel in browser

---

## 3. ARCHITECTUUR

```
laravel-rebuild/
├── app/
│   ├── Livewire/          ← Frontend componenten (26 stuks)
│   │   ├── Auth/          ← Login, MFA, wachtwoord reset (5 componenten)
│   │   ├── Dashboard/     ← Manager + Employee dashboards (2)
│   │   ├── Hours/         ← Weekoverzicht, invoer-modal, verlof, mijn-week (5)
│   │   ├── Objections/    ← Bezwaren lijst + review (3)
│   │   ├── Accounts/      ← Accountbeheer + formulier (2)
│   │   ├── Reports/       ← Rapportages + jaaroverzicht (2)
│   │   ├── Settings/      ← Email, organisatie, teams, projecten, feestdagen (5)
│   │   ├── Atw/           ← ATW-statusdashboard (1)
│   │   └── Profile/       ← Profielpagina (1)
│   ├── Services/          ← Business logic (12 services)
│   ├── Models/            ← Eloquent models (15+)
│   └── Http/Middleware/   ← Auth, rate limiting, headers
├── resources/views/
│   ├── layouts/           ← Hoofd-layout
│   ├── components/ui/     ← UI atoms (button, card, text-input, status-badge)
│   └── livewire/          ← Blade views per component
├── routes/
│   ├── web.php            ← Livewire pagina-routes (25+ routes)
│   └── api.php            ← Interne API-routes (bearer token auth)
├── database/migrations/   ← 30+ migraties
└── docs/                  ← Documentatie
```

### Rollen & Rechten
| Rol | Scope | Kan |
|-----|-------|-----|
| owner | Hele organisatie | Alles: CRUD, instellingen, accounts, MFA-beheer, verwijderen |
| manager | Eigen team | Uren invoeren, verlof goedkeuren, bezwaren beoordelen, rapportages |
| employee | Eigen data | Mijn-week bekijken, verlof aanvragen, bezwaren indienen, profiel |
| boekhouder | Read-only | Rapportages bekijken, ATW-overzicht, geen mutaties |

### Database-tabellen (belangrijkste)
- `users` — accounts met encrypted velden (email, full_name, phone)
- `organizations` — organisatie-instellingen + ATW-limieten
- `teams` — teamindeling met manager-koppeling
- `work_entries` — werkregels (uren, verlof, ziekte, feestdagen)
- `objections` — bezwaren op werkregels
- `atw_violations` — ATW-meldingen (critical/warning)
- `projects` / `cost_centers` — project- en kostenplaatsbeheer
- `email_outbox` — e-mail queue met retry-logica
- `email_templates` — 11 configureerbare e-mailtemplates
- `holidays` — feestdagen per jaar
- `audit_events` — volledige audit-trail
- `auth_sessions` — sessie-beheer met idle-timeout + IP-check

### E-mail systeem
- Outbox-pattern: mails worden gequeued in `email_outbox`, niet direct verstuurd
- Cron elke 5 min verwerkt de queue via SMTP
- Exponential backoff bij fouten (max 5 retries)
- 11 template-types configureerbaar door de owner

---

## 4. WAT HET TECH TEAM MOET DOEN

### FASE 1: DASHBOARD REVOLUTIE (hoogste prioriteit)

Het dashboard moet het **visitekaartje** van de applicatie zijn. Modern, informatief, interactief.

#### Owner/Manager Dashboard moet bevatten:
1. **Hero-sectie** met persoonlijke begroeting + datum/tijd + weer (optioneel)
2. **KPI-cards met sparklines:**
   - Totaal uren deze week (met trend vs vorige week, pijltje omhoog/omlaag)
   - Aanwezigheidspercentage (met donut-chart)
   - Openstaande verlofaanvragen (met urgentie-indicator)
   - ATW-meldingen (critical rood, warning oranje)
   - Openstaande bezwaren
   - Ziekteverzuim-percentage
3. **Uren-grafiek** (bar chart): uren per dag deze week, per team of totaal
4. **Aanwezigheids-heatmap**: wie is er vandaag/deze week (grid met avatars/initialen)
5. **Recente activiteit-feed**: laatste 10 acties (uren ingevoerd, verlof aangevraagd, etc.)
6. **Snelle acties**: 
   - "Uren invoeren" knop die direct de modal opent
   - "Verlof goedkeuren" badge met aantal openstaand
   - "Bezwaar beoordelen" badge
7. **Mini-kalender** met markering van feestdagen en verlof
8. **Team-overzicht widget**: wie werkt er vandaag, wie is vrij, wie is ziek

#### Employee Dashboard moet bevatten:
1. **Persoonlijke begroeting** + motiverende tekst
2. **Mijn uren deze week** (visueel: progress bar naar contracturen)
3. **Mijn verlof-saldo** (opgebouwd vs opgenomen, visueel)
4. **Mijn openstaande bezwaren** met status
5. **Snelle acties**: "Uren invoeren", "Verlof aanvragen"
6. **Mijn rooster deze week** (mini-tabel met diensten)
7. **Notificaties** (verlof goedgekeurd, werkregel gewijzigd, etc.)

#### Technische implementatie:
- Gebruik **ApexCharts** of **Chart.js** voor grafieken (via CDN of npm)
- Livewire `wire:poll.30s` voor near-realtime updates op tellingen
- Lazy-load zware widgets met Livewire's `lazy` attribute
- Skeleton loading states tijdens het laden

---

### FASE 2: UREN-INVOER VERBETEREN

#### Weekoverzicht (`/uren/week`):
1. **Kleurcodering per type:**
   - WORK = groen
   - SICK = rood
   - LEAVE = blauw
   - HOLIDAY = paars/grijs
   - Leeg = lichtgrijs gestippeld (klikbaar)
2. **Totaal-kolom** per medewerker (weeksom in uren:minuten)
3. **Totaal-rij** per dag (dagsom alle medewerkers)
4. **Grand total** rechtsonder
5. **Copy-week knop**: kopieer alle werkregels van vorige week (backend bestaat al)
6. **Bulk-modus**: selecteer meerdere cellen → vul in één keer
7. **Hover-tooltip** op elke cel: "Klik om uren in te voeren voor [naam] op [datum]"
8. **Visuele indicator** voor cellen met ATW-waarschuwing (oranje rand)
9. **Print-knop** voor het weekoverzicht

#### Invoer-modal:
1. **Snellere invoer**: tab-volgorde geoptimaliseerd, auto-focus op begintijd
2. **Slimme defaults**: als vorige dag 09:00-17:30 was, stel dat voor
3. **Live netto-berekening** (bestaat al, maar prominenter tonen)
4. **Favoriete diensten**: sla veelgebruikte patronen op (bijv. "Standaard 8u")
5. **Keyboard shortcuts**: Enter = opslaan, Escape = sluiten

#### Mijn Week (`/uren/mijn-week`):
1. **Visuele tijdlijn** per dag (horizontale balk 00:00-24:00 met dienst gemarkeerd)
2. **Week-totaal** prominent bovenaan
3. **Vergelijking met contracturen** (als die beschikbaar zijn)
4. **Bezwaar-status** per regel duidelijker (icoon + kleur)

---

### FASE 3: VERLOF-SYSTEEM UITBREIDEN

1. **Verlofkalender** (`/verlof/kalender`):
   - Maandweergave met alle medewerkers
   - Kleurcodering per type (ziek=rood, verlof=blauw, feestdag=grijs)
   - Klik op een dag om verlof aan te vragen
   - Zichtbaar voor manager/owner: wie is wanneer vrij

2. **Verlof-saldo**:
   - Configureerbaar per medewerker (jaarlijks verlofrecht)
   - Automatische berekening: recht - opgenomen = resterend
   - Visuele progress bar op employee-dashboard
   - Waarschuwing bij bijna-op

3. **Verlof-types uitbreiden**:
   - Vakantieverlof (standaard)
   - Bijzonder verlof (huwelijk, overlijden, verhuizing)
   - Onbetaald verlof
   - Ouderschapsverlof
   - Elk type met eigen quota

4. **Half-dag verlof**: ochtend of middag vrij

5. **Automatische notificaties**:
   - Medewerker → mail bij goedkeuring/afwijzing
   - Manager → mail bij nieuwe aanvraag
   - Herinnering bij lang-openstaande aanvragen

6. **Annuleren**: medewerker kan eigen aanvraag annuleren (zolang nog niet goedgekeurd)

---

### FASE 4: AGENDA & PLANNING

1. **Agenda-pagina** (`/agenda`):
   - Week- en maandweergave
   - Diensten van het team (manager) of eigen diensten (employee)
   - Feestdagen gemarkeerd
   - Verlof gemarkeerd
   - Drag-and-drop voor het verplaatsen van diensten (owner/manager)

2. **Rooster-planning** (owner/manager):
   - Weekrooster opstellen voor het team
   - Templates: "Standaard werkweek", "Ploegendienst", etc.
   - Automatische verdeling op basis van beschikbaarheid

---

### FASE 5: NOTIFICATIE-SYSTEEM

1. **In-app notificaties**:
   - Bell-icoon in de header met badge (aantal ongelezen)
   - Dropdown met recente notificaties
   - Klik → navigeer naar relevante pagina
   - "Markeer alles als gelezen"

2. **Notificatie-types**:
   - Verlof goedgekeurd/afgewezen
   - Werkregel gewijzigd/verwijderd
   - Bezwaar beoordeeld
   - ATW-waarschuwing
   - Nieuw account aangemaakt (voor de medewerker)
   - Herinnering openstaande uren

3. **Notificatie-voorkeuren** per gebruiker:
   - Welke notificaties wil je in-app?
   - Welke per e-mail?
   - Welke helemaal niet?

4. **Database**: `notifications` tabel met `user_id`, `type`, `data` (JSON), `read_at`

---

### FASE 6: RAPPORTAGES VERRIJKEN

1. **Visuele rapportages in de browser**:
   - Uren per medewerker (bar chart)
   - Uren per project (pie chart)
   - Uren per kostenplaats
   - Trend over maanden (line chart)
   - Vergelijking teams

2. **Kosten-rapportage**:
   - Uren × uurtarief per project
   - Totale loonkosten per periode
   - Budget vs werkelijk per project

3. **Ziekteverzuim-rapportage**:
   - Percentage per team
   - Trend over maanden
   - Vergelijking met vorig jaar

4. **Automatische maandrapportage**:
   - Trigger-knop in de UI (backend bestaat al)
   - Automatisch elke 1e van de maand via cron

5. **Custom rapportages**:
   - Drag-and-drop rapport-builder (ambitieus maar waardevol)
   - Opslaan als favoriet
   - Delen met collega's

---

### FASE 7: UI/UX REVOLUTIE

#### Design System uitbreiden:
1. **Meer UI-componenten**:
   - `<x-ui.modal>` — herbruikbare modal met animatie
   - `<x-ui.toast>` — toast-notificaties (success/error/info/warning)
   - `<x-ui.dropdown>` — dropdown-menu
   - `<x-ui.tabs>` — tab-navigatie
   - `<x-ui.avatar>` — gebruiker-avatar met initialen
   - `<x-ui.progress>` — progress bar
   - `<x-ui.skeleton>` — loading skeleton
   - `<x-ui.empty-state>` — lege staat met illustratie
   - `<x-ui.stat-card>` — KPI-card met trend-indicator
   - `<x-ui.chart>` — wrapper voor chart-library
   - `<x-ui.calendar>` — kalender-widget
   - `<x-ui.timeline>` — activiteit-tijdlijn
   - `<x-ui.data-table>` — sorteerbare, filterbare tabel

2. **Animaties & Transities**:
   - Page transitions (fade-in bij navigatie)
   - Modal open/close (scale + fade)
   - Toast slide-in van rechts
   - Skeleton → content fade
   - Hover-effecten op knoppen en cards
   - Success-animatie bij opslaan (checkmark)

3. **Dark Mode**:
   - Toggle in de header of profiel
   - Opslaan als voorkeur per gebruiker
   - Respecteer `prefers-color-scheme` van het OS
   - Alle kleuren via CSS custom properties

4. **Mobiele Optimalisatie**:
   - Bottom navigation bar (Dashboard, Uren, Verlof, Profiel)
   - Swipe-gestures voor week-navigatie
   - Pull-to-refresh
   - Grotere touch-targets (min 44px)
   - Sticky header met scroll-away

5. **PWA (Progressive Web App)**:
   - `manifest.json` met app-naam, iconen, kleuren
   - Service worker voor offline-basis (toon "geen verbinding" pagina)
   - "Installeer app" prompt op mobiel
   - Push-notificaties (toekomst)

6. **Micro-interacties**:
   - Knop-press effect (scale down)
   - Input focus glow (al aanwezig, verfijnen)
   - Checkbox/toggle animatie
   - Counter-animatie op dashboard-cijfers
   - Confetti bij jubileum-melding 🎉

---

### FASE 8: SLIMME FEATURES

1. **Timer/Stopwatch**:
   - Start/stop knop op het dashboard of in de header
   - Automatisch werkregel aanmaken bij stoppen
   - Pauze-knop
   - Herinnering als timer > 10 uur draait

2. **Slimme suggesties**:
   - "Je hebt gisteren geen uren ingevuld — wil je dat nu doen?"
   - "Vorige week werkte je ma-vr 09:00-17:30 — zelfde deze week?"
   - "Je hebt nog 5 verlofdagen over dit jaar"

3. **Bulk-acties**:
   - Selecteer meerdere werkregels → verwijderen/bewerken
   - Selecteer meerdere verlofaanvragen → goedkeuren
   - Selecteer meerdere medewerkers → team-wijziging

4. **Import/Export**:
   - CSV-import van uren (voor migratie van oud systeem)
   - iCal-export van diensten (voor persoonlijke agenda)
   - API-koppeling met boekhoudsoftware (toekomst)

5. **Zoekfunctie**:
   - Globale zoekbalk in de header (Cmd+K / Ctrl+K)
   - Zoek op medewerker, project, datum, type
   - Recente zoekopdrachten
   - Snelle navigatie ("ga naar accounts", "ga naar rapportages")

---

### FASE 9: COMMUNICATIE & SAMENWERKING

1. **Interne berichten** (simpel):
   - Manager kan bericht sturen naar medewerker
   - Zichtbaar als notificatie
   - Geen volledige chat — alleen korte mededelingen

2. **Opmerkingen op werkregels**:
   - Manager kan opmerking plaatsen bij een werkregel
   - Medewerker ziet de opmerking in mijn-week
   - Threaded (reactie mogelijk)

3. **Team-aankondigingen**:
   - Owner/manager kan aankondiging plaatsen
   - Zichtbaar op het dashboard van teamleden
   - Verloopt na X dagen

---

### FASE 10: GEAVANCEERDE INSTELLINGEN

1. **Organisatie-branding**:
   - Logo uploaden (getoond in header + e-mails)
   - Primaire kleur aanpassen (brand-green → eigen kleur)
   - Bedrijfsnaam in de footer

2. **Werkrooster-configuratie**:
   - Standaard werkdagen (ma-vr of anders)
   - Standaard werktijden per dag
   - Contracturen per medewerker
   - Parttime-percentage

3. **Verlof-configuratie**:
   - Jaarlijks verlofrecht per medewerker
   - Opbouw-schema (maandelijks, per kwartaal)
   - Overdracht naar volgend jaar (ja/nee, max dagen)
   - Bijzonder-verlof-types definiëren

4. **Integraties** (toekomst):
   - Google Calendar sync
   - Microsoft 365 sync
   - Slack/Teams notificaties
   - Boekhoudsoftware (Exact, Twinfield, etc.)

---

## 5. TECHNISCHE RICHTLIJNEN

### Code-stijl
- PHP 8.3+ strict types overal
- Laravel Pint voor formatting
- Livewire 3 patterns (geen v2 syntax)
- Tailwind CSS utility-first
- Alpine.js voor client-side interactiviteit
- TypeScript voor complexe JS (optioneel)

### Bestaande UI-componenten (HERGEBRUIKEN)
```
<x-ui.card>          — paneel met optionele <x-slot:header>
<x-ui.button>        — variant: primary/secondary/ghost/danger, as="a" voor links
<x-ui.text-input>    — input met label, error, help props
<x-ui.status-badge>  — variant: success/warning/danger/concept + icon prop
```

### Design Tokens (in Tailwind config)
```
Kleuren:
- brand-green: #00d4a4 (primaire actie-kleur)
- ink: tekst-kleur (donker)
- steel: secundaire tekst (grijs)
- hairline: border-kleur (lichtgrijs)
- canvas: achtergrond (wit/lichtgrijs)
- surface: card-achtergrond
- danger/warning/success: status-kleuren

Spacing:
- sidebar: 240px
- gutter: 24px
- toc: 200px

Typography:
- font-sans: Inter
- font-mono: Geist Mono
```

### Performance-richtlijnen
- Geen N+1 queries (gebruik `with()` eager loading)
- Pagineer lijsten > 20 items
- Lazy-load zware componenten
- Cache waar mogelijk (config, routes, views)
- Minimaliseer Livewire-roundtrips (debounce, lazy)

### Database
- MySQL op shared hosting (Cloud86)
- Encrypted kolommen: `full_name`, `email`, `phone` → TEXT type (niet VARCHAR)
- `email_index_hash` (SHA-256) als zoek-index
- Soft-deletes op `users` en `work_entries`
- `archived_at` op `projects` en `cost_centers`
- Alle datums in UTC opslaan, weergeven in Europe/Amsterdam

### Deployment
- FTP naar Cloud86 shared hosting (geen SSH)
- Na upload: clear caches via script of cPanel terminal
- Cron via cPanel: elke 5 min email queue
- Geen CI/CD (handmatig) — mag opgezet worden als mogelijk

---

## 6. PRIORITEITEN-MATRIX

| # | Wat | Impact | Effort | Prioriteit |
|---|-----|--------|--------|-----------|
| 1 | Dashboard herontwerp met charts | HOOG | MIDDEL | 🔴 NU |
| 2 | Weekoverzicht kleurcodering + totalen | HOOG | LAAG | 🔴 NU |
| 3 | Toast-notificaties | HOOG | LAAG | 🔴 NU |
| 4 | Copy-week functionaliteit | HOOG | LAAG | 🔴 NU |
| 5 | Verlofkalender | HOOG | MIDDEL | 🟠 SNEL |
| 6 | In-app notificaties | HOOG | MIDDEL | 🟠 SNEL |
| 7 | Dark mode | MIDDEL | MIDDEL | 🟡 GEPLAND |
| 8 | Timer/stopwatch | MIDDEL | MIDDEL | 🟡 GEPLAND |
| 9 | Verlof-saldo tracking | HOOG | MIDDEL | 🟠 SNEL |
| 10 | Visuele rapportages | MIDDEL | HOOG | 🟡 GEPLAND |
| 11 | PWA manifest | MIDDEL | LAAG | 🟡 GEPLAND |
| 12 | Agenda-pagina | MIDDEL | HOOG | 🟡 GEPLAND |
| 13 | Globale zoekfunctie | MIDDEL | MIDDEL | 🟡 GEPLAND |
| 14 | Animaties & transities | LAAG | MIDDEL | 🔵 LATER |
| 15 | Rooster-planning | MIDDEL | HOOG | 🔵 LATER |
| 16 | Import/export CSV | LAAG | MIDDEL | 🔵 LATER |
| 17 | Integraties (Google/MS) | LAAG | HOOG | 🔵 LATER |

---

## 7. KWALITEITSEISEN

### Elke pagina moet voldoen aan:
- [ ] Geen console-errors
- [ ] Geen 403/404/500 bij normaal gebruik
- [ ] Responsive: mobiel + tablet + desktop
- [ ] Loading state zichtbaar bij elke actie
- [ ] Bevestiging na elke mutatie (toast of banner)
- [ ] Foutmelding in het Nederlands bij elke validatie-fout
- [ ] Keyboard-navigeerbaar (tab-volgorde logisch)
- [ ] Screenreader-vriendelijk (aria-labels, roles)
- [ ] Snelle laadtijd (< 2 seconden eerste paint)

### Elke feature moet voldoen aan:
- [ ] Werkt voor alle relevante rollen
- [ ] Organisatie-scoped (geen data-lekkage tussen organisaties)
- [ ] Audit-trail bij mutaties
- [ ] E-mail notificatie waar relevant
- [ ] Undo/annuleer mogelijkheid waar logisch

---

## 8. BESTANDEN DIE PRIORITEIT HEBBEN

| Bestand | Wat ermee moet |
|---------|---------------|
| `resources/views/livewire/dashboard/manager-home.blade.php` | Volledig herontwerpen met charts, KPI's, activiteit-feed |
| `resources/views/livewire/dashboard/employee-home.blade.php` | Uitbreiden met verlof-saldo, rooster, notificaties |
| `resources/views/livewire/hours/week-overview-table.blade.php` | Kleurcodering, totalen, copy-week, hover-tooltips |
| `resources/views/livewire/hours/entry-form-modal.blade.php` | Slimme defaults, keyboard shortcuts, favorieten |
| `app/Livewire/Hours/LeaveForm.php` + view | Verlofkalender, saldo, half-dag, annuleren |
| `resources/views/layouts/app.blade.php` | Notificatie-bell, globale zoek, dark mode toggle |
| `resources/css/app.css` | Dark mode variabelen, animatie-utilities |
| `tailwind.config.js` | Uitbreiden met animatie-config, dark mode |

---

## 9. INSPIRATIE & REFERENTIES

Kijk naar deze applicaties voor inspiratie:
- **Personio** — HR-software met modern dashboard
- **Clockify** — Tijdregistratie met timer en rapportages
- **BambooHR** — Verlofbeheer met kalender
- **Monday.com** — Dashboard-widgets en kleurgebruik
- **Linear** — Snelheid, keyboard shortcuts, minimalistisch design
- **Notion** — Command palette (Cmd+K), snelle navigatie

---

## 10. DEPLOYMENT & HOSTING

- **URL:** https://ur.la-vitatrading.nl/
- **Hosting:** Cloud86 shared hosting (cPanel)
- **Database:** MySQL
- **SMTP:** la-vitatrading.nl:587 (STARTTLS)
- **Cron:** Elke 5 min email queue verwerken
- **FTP:** Voor deployment (geen SSH beschikbaar)
- **Repository:** Lokaal — overweeg GitHub/GitLab voor samenwerking

---

---

### FASE 11: MEDEWERKER-BEHEER & HR-FUNCTIES

1. **Medewerker-profiel uitgebreid**:
   - Foto/avatar uploaden
   - Noodcontact-gegevens
   - BSN (encrypted opslaan, alleen owner zichtbaar)
   - Bankrekeningnummer (encrypted)
   - Rijbewijs-categorie
   - Certificaten/diploma's met verloopdatum
   - Notities-veld (alleen manager/owner zichtbaar)
   - Documenten uploaden (arbeidscontract, ID-kopie, etc.)

2. **Contractbeheer**:
   - Contracttype (vast, tijdelijk, oproep, ZZP)
   - Contracturen per week
   - Salaris/uurtarief (encrypted, alleen owner)
   - Proeftijd-einddatum met herinnering
   - Contract-einddatum met automatische waarschuwing 3 maanden vooraf
   - Contractverlenging-workflow

3. **Functie- en competentiebeheer**:
   - Functies definiëren per organisatie
   - Functie toewijzen aan medewerker
   - Competenties/skills per medewerker
   - Functie-historie (promoties, wijzigingen)

4. **Onboarding-checklist**:
   - Configureerbare checklist per organisatie
   - Items: "Bankgegevens ontvangen", "ID-kopie", "Contract getekend", etc.
   - Voortgangsindicator per nieuwe medewerker
   - Automatische herinnering bij incomplete onboarding

5. **Offboarding-workflow**:
   - Uitdiensttreding-wizard
   - Checklist: "Sleutels ingeleverd", "Laptop ingeleverd", "Eindafrekening"
   - Automatische deactivering op einddatum
   - Exit-interview notities

---

### FASE 12: PLANNING & ROOSTERING

1. **Rooster-module** (`/planning`):
   - Weekrooster per team in drag-and-drop grid
   - Dienst-templates: "Vroege dienst 06:00-14:00", "Late dienst 14:00-22:00", etc.
   - Rooster kopiëren naar volgende week
   - Rooster publiceren (medewerkers krijgen notificatie)
   - Concept-modus (rooster opstellen zonder te publiceren)

2. **Beschikbaarheid**:
   - Medewerkers geven beschikbaarheid op per week
   - Groen/oranje/rood per dagdeel
   - Manager ziet beschikbaarheid bij het roosteren
   - Conflictdetectie (ingeroosterd terwijl niet beschikbaar)

3. **Dienst-ruil**:
   - Medewerker kan ruil-verzoek indienen
   - Andere medewerker accepteert
   - Manager keurt goed
   - Automatische aanpassing van werkregels

4. **Bezetting-overzicht**:
   - Minimale bezetting per dag/dagdeel configureerbaar
   - Waarschuwing bij onderbezetting
   - Visueel: groen (voldoende), oranje (krap), rood (tekort)

5. **Auto-scheduling** (ambitieus):
   - Op basis van beschikbaarheid + contracturen + ATW-limieten
   - Eerlijke verdeling van onpopulaire diensten
   - Suggestie-modus (manager keurt goed)

---

### FASE 13: FINANCIEEL & FACTURATIE

1. **Uurtarief-beheer**:
   - Uurtarief per medewerker (standaard)
   - Uurtarief per project (overschrijft standaard)
   - Toeslagen: avond (+25%), nacht (+50%), weekend (+50%), feestdag (+100%)
   - Toeslag-configuratie per organisatie

2. **Kosten-dashboard**:
   - Totale loonkosten per week/maand/jaar
   - Kosten per team
   - Kosten per project
   - Budget vs werkelijk per project
   - Grafiek: kosten-trend over tijd

3. **Facturatie-voorbereiding**:
   - Uren per project per periode → factuurregels genereren
   - Export naar facturatie-formaat (UBL, CSV)
   - Markering "gefactureerd" per werkregel
   - Openstaande uren (nog niet gefactureerd) overzicht

4. **Kilometerregistratie**:
   - Woon-werk kilometers per medewerker
   - Zakelijke kilometers per dag (optioneel veld bij uren-invoer)
   - Kilometervergoeding-berekening
   - Maandoverzicht kilometers

5. **Onkosten-declaraties**:
   - Declaratie indienen met foto van bon
   - Categorie (reiskosten, maaltijd, materiaal, etc.)
   - Goedkeuring door manager
   - Export voor boekhouding

---

### FASE 14: COMMUNICATIE & DOCUMENTEN

1. **Mededelingen-bord** (`/mededelingen`):
   - Owner/manager plaatst mededelingen
   - Zichtbaar op dashboard van relevante medewerkers
   - Categorie: "Belangrijk", "Informatie", "Sociaal"
   - Verloopt na configureerbare periode
   - Bevestiging "gelezen" per medewerker

2. **Documenten-bibliotheek** (`/documenten`):
   - Organisatie-documenten uploaden (huisregels, protocollen, etc.)
   - Categorieën en mappen
   - Versie-beheer (nieuwe versie uploaden, oude bewaren)
   - "Verplicht lezen" — medewerker moet bevestigen
   - Zoeken in documenten

3. **Persoonlijke documenten**:
   - Per medewerker: arbeidscontract, loonstroken, beoordelingen
   - Alleen zichtbaar voor de medewerker zelf + owner
   - Upload door owner, download door medewerker

4. **E-mail historie**:
   - Overzicht van alle verstuurde e-mails per medewerker
   - Status: verstuurd, mislukt, geopend (als tracking actief)
   - Opnieuw versturen-knop bij mislukte mails

---

### FASE 15: ANALYTICS & INZICHTEN

1. **Analytics-dashboard** (`/analytics`):
   - Uren-trend per week/maand (line chart)
   - Verdeling uren per type (pie chart: WORK vs SICK vs LEAVE)
   - Top-5 projecten op uren
   - Gemiddelde werkdag-lengte per team
   - Piekuren (wanneer wordt het meest gewerkt)
   - Vergelijking met vorig jaar

2. **Ziekteverzuim-analytics**:
   - Verzuimpercentage per team/organisatie
   - Frequent kort verzuim detectie (signalering)
   - Trend over maanden
   - Benchmark (optioneel: vergelijk met branche-gemiddelde)

3. **Productiviteits-inzichten**:
   - Uren per medewerker vs contracturen (over/onder)
   - Overwerk-trend
   - Verlof-opname-patroon (wie neemt nooit verlof op?)

4. **Voorspellingen** (AI-achtig):
   - Verwachte bezetting volgende week op basis van patronen
   - Verwacht ziekteverzuim op basis van seizoen
   - Waarschuwing: "Medewerker X heeft 20 verlofdagen over, jaar loopt af"

---

### FASE 16: MOBIEL & OFFLINE

1. **Native-achtige mobiele ervaring**:
   - Bottom navigation (4 tabs: Home, Uren, Verlof, Menu)
   - Swipe-navigatie tussen weken
   - Pull-to-refresh
   - Haptic feedback bij acties (via Vibration API)
   - Grote touch-targets (min 48px)
   - Thumb-zone optimalisatie

2. **Offline-modus** (basis):
   - Service worker cachet de app-shell
   - Bij geen verbinding: toon "Geen internet" pagina
   - Queue acties lokaal, sync bij reconnect (ambitieus)

3. **Push-notificaties**:
   - Web Push API
   - Opt-in per gebruiker
   - Verlof goedgekeurd → push
   - Herinnering uren invullen → push
   - ATW-waarschuwing → push

4. **Biometrische login** (toekomst):
   - WebAuthn/FIDO2 als alternatief voor TOTP-MFA
   - Vingerafdruk of Face ID op mobiel
   - Hardware security key ondersteuning

---

### FASE 17: MULTI-ORGANISATIE & SCHAALBAARHEID

1. **Multi-tenant uitbreidingen**:
   - Meerdere organisaties in één installatie
   - Super-admin rol (boven owner)
   - Organisatie-switcher in de header
   - Gedeelde feestdagen-configuratie

2. **Vestigingen/locaties**:
   - Meerdere vestigingen per organisatie
   - Locatie toewijzen aan teams
   - Locatie-specifieke instellingen (werktijden, feestdagen)
   - Locatie-filter in rapportages

3. **Afdelingen**:
   - Hiërarchie: Organisatie → Afdeling → Team
   - Afdelingshoofd-rol
   - Rapportages per afdeling

4. **API voor derden**:
   - Publieke REST API met OAuth2
   - Webhook-notificaties bij events
   - API-documentatie (Swagger/OpenAPI)
   - Rate limiting per API-key

---

### FASE 18: GAMIFICATION & ENGAGEMENT

1. **Achievements/badges**:
   - "Eerste week compleet" badge
   - "100 uur geregistreerd" badge
   - "Altijd op tijd" badge (nooit te laat ingevuld)
   - Zichtbaar op profiel

2. **Streaks**:
   - "5 weken op rij alle uren ingevuld"
   - Visuele streak-counter op dashboard
   - Verlies-waarschuwing: "Vul vandaag in om je streak te behouden!"

3. **Team-competitie** (optioneel, configureerbaar):
   - Welk team vult het snelst in?
   - Leaderboard (anoniem of met namen)
   - Maandelijkse "Team van de maand"

4. **Jubileum-viering**:
   - Automatische detectie van werkjubilea
   - Felicitatie op dashboard van collega's
   - Confetti-animatie 🎉
   - E-mail naar het team

---

### FASE 19: COMPLIANCE & JURIDISCH

1. **AVG/GDPR-module**:
   - Data-export per medewerker (alle persoonsgegevens, JSON/PDF)
   - Recht op vergetelheid workflow (pseudonimisering)
   - Verwerkingsregister in de UI
   - Cookie-consent met granulaire keuzes
   - Privacy-dashboard voor medewerkers ("welke data hebben we van jou?")

2. **Arbeidstijdenwet-rapportage**:
   - Automatische ATW-compliance-rapportage per maand
   - Export voor Arbeidsinspectie
   - Historische ATW-overtredingen met context
   - Preventieve waarschuwingen (trend-based)

3. **CAO-integratie** (toekomst):
   - CAO-regels configureerbaar (toeslagen, verlofrecht, etc.)
   - Automatische berekening op basis van CAO
   - Waarschuwing bij afwijking van CAO

4. **Digitale handtekening**:
   - Medewerker tekent digitaal voor akkoord op uren
   - Wekelijkse/maandelijkse accordering
   - Juridisch geldig (qualified electronic signature — toekomst)

---

### FASE 20: ADMIN & SYSTEEMBEHEER

1. **Systeem-status pagina** (`/admin/status`):
   - Database-verbinding status
   - E-mail queue status (hoeveel in wachtrij, hoeveel mislukt)
   - Laatste cron-run tijdstip
   - Disk-gebruik
   - PHP-versie en extensies
   - Laravel-versie

2. **Audit-log viewer** (`/admin/audit`):
   - Doorzoekbare lijst van alle audit-events
   - Filter op actor, actie, target, datum
   - Detail-view met before/after data
   - Export naar CSV

3. **Sessie-beheer** (`/admin/sessies`):
   - Alle actieve sessies per gebruiker
   - Forceer-uitlog per sessie of per gebruiker
   - Laatste activiteit per sessie
   - IP-adres en user-agent

4. **E-mail beheer** (`/admin/email`):
   - Queue-overzicht: queued, sent, failed, retrying
   - Handmatig opnieuw versturen van mislukte mails
   - E-mail preview (hoe ziet de mail eruit?)
   - Test-mail versturen

5. **Database-onderhoud**:
   - Backup-status (laatste backup, grootte)
   - Retentie-overzicht (welke data wordt wanneer verwijderd)
   - Tabel-statistieken (aantal records per tabel)

6. **Feature flags**:
   - Features aan/uit zetten per organisatie
   - Beta-features voor specifieke gebruikers
   - Geleidelijke uitrol van nieuwe functionaliteit

7. **Systeem-logs**:
   - Laatste errors/warnings uit Laravel log
   - Filterbaar op level, datum, bericht
   - Alerting bij kritieke errors (email naar owner)

8. **Import-wizard**:
   - CSV-import voor medewerkers (bulk-aanmaken)
   - CSV-import voor uren (migratie van oud systeem)
   - Mapping-stap (welke kolom = welk veld)
   - Preview + bevestiging voor import
   - Rollback bij fouten

---

## 11. SAMENVATTING VOOR HET TEAM

**In één zin:** Maak van deze werkende-maar-kale urenregistratie een **moderne, feature-rijke, visueel aantrekkelijke enterprise-applicatie** waar gebruikers graag mee werken — met de diepte en breedte van Personio, de snelheid van Linear, en de gebruiksvriendelijkheid van Notion.

**De vijf pijlers:**
1. **Dashboard = WOW-factor** — grafieken, KPI's, activiteit, snelle acties, real-time
2. **Alles 100% werkend** — geen errors, geen dode links, geen halve features
3. **Modern en snel** — animaties, dark mode, PWA, toast-notificaties, keyboard shortcuts
4. **Feature-compleet** — planning, verlofkalender, analytics, documenten, communicatie
5. **Enterprise-ready** — multi-tenant, API, compliance, audit, schaalbaarheid

**Budget en tijd:** Onbeperkt. De beste oplossing wint altijd. Neem de tijd die nodig is om het GOED te doen. Elke zwakheid die we NU missen, kost later miljoenen.
