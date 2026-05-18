# API-referentie — LaVita Urenregistratie

Volledige referentie van alle API-endpoints inclusief request-parameters, response-structuren, foutcodes en autorisatie per rol.

**Base URL:** `https://uren.{domein}.nl/api`
**Content-Type:** `application/json`
**Authenticatie:** `Authorization: Bearer <session_token>` (tenzij anders vermeld)

---

## Inhoudsopgave

- [Authenticatie](#authenticatie)
- [Werkregels](#werkregels)
- [Bezwaren](#bezwaren)
- [ATW-signalen](#atw-signalen)
- [Projecten](#projecten)
- [Kostenplaatsen](#kostenplaatsen)
- [Rapportages](#rapportages)
- [Feestdagen](#feestdagen)
- [Accounts en AVG](#accounts-en-avg)
- [E-mail flows](#e-mail-flows)
- [Audit](#audit)
- [Systeem](#systeem)
- [Foutcodes](#foutcodes)
- [Autorisatiematrix](#autorisatiematrix)

---

## Authenticatie

### POST /auth/login

Inloggen met e-mailadres en wachtwoord.

**Rate limit:** 20/min per IP | **Auth:** Nee

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `email` | string | Ja | RFC-e-mailadres |
| `password` | string | Ja | Minimaal 12 tekens |

**Response 200:**
```json
{
  "status": "ok",
  "user_id": 42,
  "session_token": "abcdef1234...",
  "expires_at": "2026-05-17T14:00:00+00:00",
  "mfa_required": true
}
```

**Fouten:** `422` Ongeldige inloggegevens of account gedeactiveerd

---

### POST /auth/mfa/verify

MFA-code verifiëren (6-cijferige TOTP of 10-teken recovery-code).

**Rate limit:** 5/min per user_id+IP | **Auth:** Nee

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `user_id` | integer | Ja | Gebruiker-ID |
| `code` | string | Ja | TOTP-code of recovery-code |

**Response 200:** `{ "status": "ok", "verified": true }`

**Fouten:** `422` Ongeldige code | `429` Rate limit

---

### POST /auth/mfa/setup

MFA instellen of roteren. Genereert TOTP-secret en 8 recovery-codes.

**Auth:** Ja | **Rollen:** alle

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `user_id` | integer | Ja | Eigen user-ID |
| `password_confirmation` | string | Ja | Huidig wachtwoord |

**Response 201:**
```json
{
  "status": "ok",
  "user_id": 42,
  "provisioning_secret": "BASE32SECRET...",
  "recovery_codes": ["ABCDE12345", "..."]
}
```

**Fouten:** `403` Ander account | `422` Verkeerd wachtwoord

---

### POST /auth/logout

Huidige sessie intrekken.

**Auth:** Ja | **Rollen:** alle

**Request:** geen body

**Response 200:** `{ "status": "ok", "revoked": true }`

---

### POST /auth/accounts

Nieuw gebruikersaccount aanmaken. Triggert `welcome_email`.

**Auth:** Ja | **Rollen:** owner, manager

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `password_confirmation` | string | Ja | Wachtwoord van aanmaker |
| `name` | string | Ja | Gebruikersnaam (max 255) |
| `full_name` | string | Nee | Volledige naam |
| `email` | string | Ja | Uniek e-mailadres |
| `role` | string | Ja | `manager`, `employee`, `boekhouder` |
| `team_id` | integer | Nee | Team-ID (nullable voor boekhouder) |
| `is_active` | boolean | Nee | Default: true |
| `employment_start` | date | Nee | Datum in dienst (Y-m-d) |
| `employment_end` | date | Nee | Datum uit dienst (Y-m-d) |

**Response 201:**
```json
{
  "status": "ok",
  "account": { "id": 123, "name": "jan.de.vries", "email": "jan@bedrijf.nl", "role": "employee" }
}
```

**Fouten:** `403` Onvoldoende rechten | `422` Validatiefout | `500` `WELCOME_EMAIL_FAILED`

---

### POST /auth/password-reset/request

Wachtwoord-reset aanvragen. Response is altijd identiek (anti-enumeratie).

**Rate limit:** 20/min per IP | **Auth:** Nee

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `email` | string | Ja | E-mailadres |

**Response 200:** `{ "ok": true, "message": "Als dit e-mailadres bestaat, ontvang je een resetlink." }`

---

### POST /auth/password-reset/confirm

Nieuw wachtwoord instellen met reset-token.

**Rate limit:** 20/min per IP | **Auth:** Nee

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `token` | string | Ja | Token uit resetlink |
| `password` | string | Ja | Nieuw wachtwoord (min 10, max 128) |

**Response 200:** `{ "ok": true, "message": "Wachtwoord succesvol gewijzigd." }`

**Fouten:** `422` Ongeldig of verlopen token

---

## Werkregels

### POST /internal/work-entries

Nieuwe werkregel aanmaken.

**Auth:** Ja | **Rollen:** owner, manager

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `employee_id` | integer | Ja | Medewerker-ID |
| `entry_date` | date | Ja | Datum (Y-m-d) |
| `start_time` | string | Ja* | Begintijd (HH:MM). *Optioneel bij type SICK/LEAVE/HOLIDAY |
| `end_time` | string | Ja* | Eindtijd (HH:MM). *Optioneel bij type SICK/LEAVE/HOLIDAY |
| `pause_minutes` | integer | Ja | Pauze in minuten (0–240) |
| `type` | string | Nee | `WORK` (default), `SICK`, `LEAVE`, `HOLIDAY`, `OTHER` |
| `note` | string | Nee | Opmerking (max 500). Verplicht bij SICK/LEAVE voor employee |
| `project_id` | integer | Nee | Project-ID (zelfde organisatie) |
| `cost_center_id` | integer | Nee | Kostenplaats-ID (zelfde organisatie) |

**Response 201:**
```json
{
  "id": 456,
  "employee_id": 99,
  "entry_date": "2026-05-16",
  "start_at": "2026-05-16T08:00:00+02:00",
  "end_at": "2026-05-16T17:00:00+02:00",
  "pause_minutes": 60,
  "net_minutes": 480,
  "type": "WORK",
  "project_id": null,
  "cost_center_id": null,
  "is_finalized": true
}
```

**Fouten:**
- `403` `READ_ONLY_ROLE` | `FORBIDDEN_ROLE`
- `422` `ATW_PAUSE_REQUIRED` | `ATW_DAILY_MAX_EXCEEDED` | `ATW_WEEKLY_MAX_EXCEEDED` | `ATW_REST_PERIOD_VIOLATED` | `PROJECT_ORG_MISMATCH` | `COST_CENTER_ORG_MISMATCH` | `PROJECT_INACTIVE` | `COST_CENTER_INACTIVE` | `INVALID_TYPE_FOR_ROLE`

---

### GET /internal/work-entries

Werkregels ophalen (eigen organisatie).

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `employee_id` | integer | Nee | Filter op medewerker |
| `from` | date | Nee | Vanaf datum |
| `to` | date | Nee | Tot datum |

**Response 200:** `{ "data": [...], "count": 25 }`

---

### GET /internal/work-entries/{id}

Enkele werkregel ophalen.

**Auth:** Ja | **Rollen:** owner, manager (eigen team), employee (eigen entries), boekhouder

**Response 200:**
```json
{
  "id": 456,
  "employee_id": 99,
  "team_id": 3,
  "registered_by_id": 42,
  "entry_date": "2026-05-16",
  "start_at": "2026-05-16T08:00:00+02:00",
  "end_at": "2026-05-16T17:00:00+02:00",
  "pause_minutes": 60,
  "net_minutes": 480,
  "type": "WORK",
  "note": null,
  "project_id": null,
  "cost_center_id": null,
  "is_finalized": true,
  "created_at": "2026-05-16T09:00:00+02:00",
  "updated_at": "2026-05-16T09:00:00+02:00"
}
```

**Fouten:** `403` `FORBIDDEN_TEAM_SCOPE` | `FORBIDDEN_OWNER_SCOPE` | `404`

---

### PATCH /internal/work-entries/{id}

Werkregel bijwerken. Herberekent `net_minutes`. Schrijft audit-event.

**Auth:** Ja | **Rollen:** owner, manager

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `entry_date` | date | Nee | Nieuwe datum |
| `start_time` | string | Nee | Nieuwe begintijd (HH:MM) |
| `end_time` | string | Nee | Nieuwe eindtijd (HH:MM) |
| `pause_minutes` | integer | Nee | Nieuwe pauze |
| `type` | string | Nee | Nieuw type |
| `note` | string | Nee | Nieuwe opmerking |
| `project_id` | integer | Nee | Nieuw project (nullable) |
| `cost_center_id` | integer | Nee | Nieuwe kostenplaats (nullable) |

**Response 200:** Bijgewerkte werkregel (zelfde structuur als GET)

**Fouten:**
- `403` `READ_ONLY_ROLE` | `FORBIDDEN_ROLE`
- `409` `OBJECTION_OPEN`
- `422` ATW-foutcodes | `PROJECT_ORG_MISMATCH` | `COST_CENTER_ORG_MISMATCH` | `PROJECT_INACTIVE` | `COST_CENTER_INACTIVE`

---

### DELETE /internal/work-entries/{id}

Werkregel soft-deleten. Markeert gerelateerde ATW-violations als superseded.

**Auth:** Ja | **Rollen:** owner, manager

**Response 204:** Geen body

**Fouten:**
- `403` `READ_ONLY_ROLE` | `FORBIDDEN_ROLE`
- `409` `OBJECTION_OPEN`

---

### POST /internal/work-entries/copy-week

Werkweek kopiëren naar volgende week.

**Auth:** Ja | **Rollen:** owner, manager

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `employee_id` | integer | Ja | Medewerker-ID |
| `source_week_start` | date | Ja | Maandag van bronweek (Y-m-d) |
| `target_week_start` | date | Ja | Maandag van doelweek (Y-m-d) |

**Response 201:**
```json
{
  "created": [
    { "id": 500, "entry_date": "2026-05-26", "net_minutes": 480 }
  ],
  "skipped": [
    { "date": "2026-05-27", "start_time": "08:00", "reason": "DUPLICATE" },
    { "date": "2026-05-28", "start_time": "06:00", "reason": "ATW_BLOCKED" }
  ]
}
```

**Fouten:**
- `403` `READ_ONLY_ROLE` | `FORBIDDEN_ROLE`
- `422` `SOURCE_WEEK_EMPTY`

---

### POST /internal/work-entries/validate-atw

ATW-validatie uitvoeren zonder op te slaan (pre-check voor frontend).

**Auth:** Ja | **Rollen:** owner, manager

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `employee_id` | integer | Ja | Medewerker-ID |
| `entry_date` | date | Ja | Datum |
| `start_time` | string | Ja | Begintijd (HH:MM) |
| `end_time` | string | Ja | Eindtijd (HH:MM) |
| `pause_minutes` | integer | Ja | Pauze in minuten |

**Response 200:**
```json
{
  "signals": [
    {
      "code": "WEEKLY_WARNING",
      "type": "WEEKLY_WARNING",
      "severity": "warning",
      "message": "Weekgrens 48 uur bereikt.",
      "threshold_minutes": 2880,
      "current_minutes": 2940
    }
  ],
  "has_critical": false,
  "has_warning": true
}
```

**Signaalcodes:**

| Code | Drempel | Severity | Blokkerend |
|------|---------|----------|------------|
| `DAILY_LIMIT` | Netto ≥ 720 min (12u) | critical | Ja |
| `WEEKLY_WARNING` | Week ≥ 2880 min (48u) | warning | Nee |
| `WEEKLY_LIMIT` | Week ≥ 3600 min (60u) | critical | Ja |
| `SIXTEEN_WEEK_AVERAGE` | 16w gem. ≥ 2880 min | critical | Nee |
| `REST_PERIOD` | Rust < 660 min (11u) | critical | Ja |
| `PAUSE_REQUIRED` | >330 min & pauze <30 | critical | Ja |

---

## Bezwaren

### POST /internal/objections

Bezwaar indienen op een werkregel.

**Auth:** Ja | **Rollen:** employee

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `work_entry_id` | integer | Ja | ID van betwiste werkregel |
| `motivation` | string | Ja | Motivatie (min 10, max 2000 tekens) |

**Response 201:**
```json
{
  "id": 789,
  "work_entry_id": 456,
  "status": "OPEN",
  "motivation": "Mijn start- en eindtijd kloppen niet...",
  "submitted_at": "2026-05-16T10:30:00+02:00"
}
```

**Fouten:** `403` `READ_ONLY_ROLE` | `422` Validatiefout

---

### POST /internal/objections/{id}/review

Bezwaar beoordelen (goedkeuren of afwijzen).

**Auth:** Ja | **Rollen:** owner, manager

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `decision` | string | Ja | `APPROVED` of `REJECTED` |
| `manager_response` | string | Nee | Toelichting (max 2000) |
| `corrected_start_time` | string | Nee | Gecorrigeerde begintijd (bij APPROVED) |
| `corrected_end_time` | string | Nee | Gecorrigeerde eindtijd (bij APPROVED) |
| `corrected_pause_minutes` | integer | Nee | Gecorrigeerde pauze (bij APPROVED) |

**Response 200:**
```json
{
  "id": 789,
  "status": "APPROVED",
  "manager_response": "Akkoord, tijden gecorrigeerd.",
  "reviewed_at": "2026-05-16T11:00:00+02:00"
}
```

**Fouten:** `403` `READ_ONLY_ROLE` | `FORBIDDEN_ROLE`

---

### GET /internal/objections

Bezwaren ophalen (eigen organisatie).

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `status` | string | Nee | Filter: `OPEN`, `APPROVED`, `REJECTED` |

**Response 200:** `{ "data": [...], "count": 5 }`

---

## ATW-signalen

### GET /internal/atw/signals

Actieve ATW-signalen ophalen per medewerker.

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `user_id` | integer | Ja | Medewerker-ID |

**Response 200:** `{ "data": [...], "count": 2 }`

---

## Projecten

### GET /internal/projects

Alle projecten ophalen (eigen organisatie).

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "code": "PRJ-001",
      "name": "Website redesign",
      "description": null,
      "hourly_rate": "85.00",
      "is_active": true,
      "archived_at": null
    }
  ],
  "count": 1
}
```

---

### GET /internal/projects/{id}

Enkel project ophalen.

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

**Response 200:** Enkel project-object

**Fouten:** `404` Niet gevonden

---

### POST /internal/projects

Nieuw project aanmaken.

**Auth:** Ja | **Rollen:** owner

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `code` | string | Ja | Unieke code binnen organisatie (max 40) |
| `name` | string | Ja | Projectnaam (max 120) |
| `description` | string | Nee | Beschrijving (max 500) |
| `hourly_rate` | decimal | Nee | Uurtarief (max 999999.99) |

**Response 201:** Aangemaakt project-object

**Fouten:** `403` `FORBIDDEN_ROLE` | `422` Code al in gebruik

---

### PATCH /internal/projects/{id}

Project bijwerken.

**Auth:** Ja | **Rollen:** owner

Zelfde velden als POST (allemaal optioneel).

**Response 200:** Bijgewerkt project-object

**Fouten:** `403` `FORBIDDEN_ROLE` | `422` Validatiefout

---

### DELETE /internal/projects/{id}

Project archiveren (soft-delete via `archived_at`).

**Auth:** Ja | **Rollen:** owner

**Response 204:** Geen body

**Fouten:** `403` `FORBIDDEN_ROLE`

---

## Kostenplaatsen

### GET /internal/cost-centers

Alle kostenplaatsen ophalen (eigen organisatie).

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "code": "KP-100",
      "name": "Overhead",
      "description": null,
      "is_active": true,
      "archived_at": null
    }
  ],
  "count": 1
}
```

---

### GET /internal/cost-centers/{id}

Enkele kostenplaats ophalen.

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

**Response 200:** Enkel kostenplaats-object

**Fouten:** `404` Niet gevonden

---

### POST /internal/cost-centers

Nieuwe kostenplaats aanmaken.

**Auth:** Ja | **Rollen:** owner

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `code` | string | Ja | Unieke code binnen organisatie (max 40) |
| `name` | string | Ja | Naam (max 120) |
| `description` | string | Nee | Beschrijving (max 500) |

**Response 201:** Aangemaakt kostenplaats-object

**Fouten:** `403` `FORBIDDEN_ROLE` | `422` Code al in gebruik

---

### PATCH /internal/cost-centers/{id}

Kostenplaats bijwerken.

**Auth:** Ja | **Rollen:** owner

Zelfde velden als POST (allemaal optioneel).

**Response 200:** Bijgewerkt kostenplaats-object

**Fouten:** `403` `FORBIDDEN_ROLE` | `422` Validatiefout

---

### DELETE /internal/cost-centers/{id}

Kostenplaats archiveren (soft-delete via `archived_at`).

**Auth:** Ja | **Rollen:** owner

**Response 204:** Geen body

**Fouten:** `403` `FORBIDDEN_ROLE`

---

## Rapportages

### GET /internal/reports/work-entries/pdf

PDF-rapport van werkregels genereren en downloaden.

**Auth:** Ja | **Rollen:** owner, manager, boekhouder
**Response Content-Type:** `application/pdf`

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `employee_id` | integer | Nee | Filter op medewerker |
| `team_id` | integer | Nee | Filter op team |
| `from` | date | Nee | Vanaf datum |
| `to` | date | Nee | Tot datum |

---

### GET /internal/reports/work-entries/excel

Excel-rapport van werkregels genereren en downloaden.

**Auth:** Ja | **Rollen:** owner, manager, boekhouder
**Response Content-Type:** `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`

Parameters: identiek aan PDF-endpoint.

---

### GET /internal/reports/cost-overview

Kostenoverzicht per project/kostenplaats.

**Auth:** Ja | **Rollen:** owner, manager, boekhouder

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `from` | date | Nee | Vanaf datum |
| `to` | date | Nee | Tot datum |
| `project_id` | integer | Nee | Filter op project |
| `cost_center_id` | integer | Nee | Filter op kostenplaats |
| `employee_id` | integer | Nee | Filter op medewerker |
| `team_id` | integer | Nee | Filter op team |

**Response 200:**
```json
{
  "data": [
    {
      "project_id": 1,
      "project_code": "PRJ-001",
      "project_name": "Website redesign",
      "total_minutes": 2400,
      "total_hours": 40.0,
      "hourly_rate": "85.00",
      "total_cost": "3400.00"
    }
  ]
}
```

> `total_cost` is `null` wanneer `hourly_rate` niet is ingesteld.

---

### GET /internal/reports/year-export

Fiscale jaarexport als PDF.

**Auth:** Ja | **Rollen:** owner, manager, boekhouder
**Response Content-Type:** `application/pdf`

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `year` | integer | Ja | Jaar (bijv. 2026) |
| `employee_id` | integer | Nee | Filter op medewerker |

---

## Feestdagen

### GET /internal/holidays

Nederlandse feestdagen ophalen per jaar.

**Auth:** Ja | **Rollen:** alle

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `year` | integer | Nee | Jaar (default: huidig jaar) |

**Response 200:**
```json
{
  "data": [
    { "date": "2026-01-01", "name": "Nieuwjaarsdag", "is_national": true },
    { "date": "2026-04-03", "name": "Goede Vrijdag", "is_national": true },
    { "date": "2026-04-05", "name": "Eerste Paasdag", "is_national": true },
    { "date": "2026-04-06", "name": "Tweede Paasdag", "is_national": true },
    { "date": "2026-04-27", "name": "Koningsdag", "is_national": true },
    { "date": "2026-05-14", "name": "Hemelvaartsdag", "is_national": true },
    { "date": "2026-05-24", "name": "Eerste Pinksterdag", "is_national": true },
    { "date": "2026-05-25", "name": "Tweede Pinksterdag", "is_national": true },
    { "date": "2026-12-25", "name": "Eerste Kerstdag", "is_national": true },
    { "date": "2026-12-26", "name": "Tweede Kerstdag", "is_national": true }
  ]
}
```

> Bevrijdingsdag (5 mei) wordt alleen opgenomen in lustrumjaren (2025, 2030, etc.).

---

## Accounts en AVG

### DELETE /internal/accounts/{id}

Account pseudonimiseren (AVG recht op verwijdering). Soft-delete met pseudonimisering van persoonsgegevens.

**Auth:** Ja | **Rollen:** owner

**Acties:**
- `users.is_active` → false
- `users.deleted_at` → now()
- `users.name` → `"user-{id}"`
- `users.full_name` → null
- `users.email` → `"user-{id}@redacted.lavita.local"`
- `users.phone` → null
- `users.email_index_hash` → bijgewerkt
- Audit-event `ACCOUNT_PSEUDONYMIZED`

**Response 204:** Geen body

**Fouten:**
- `403` `FORBIDDEN_ROLE` (niet-owner)
- `409` `OPEN_OBJECTIONS` (openstaande bezwaren)

---

### GET /internal/accounts/{id}/data-export

AVG data-export voor een gebruiker. Retourneert alle persoonsgegevens en gerelateerde data.

**Auth:** Ja | **Rollen:** owner, of de gebruiker zelf

**Response 200:**
```json
{
  "user": {
    "id": 99,
    "name": "jan.de.vries",
    "full_name": "Jan de Vries",
    "email": "jan@bedrijf.nl",
    "role": "employee",
    "employment_start": "2020-06-01"
  },
  "work_entries": [...],
  "objections": [...],
  "atw_violations": [...],
  "email_outbox": [...],
  "audit_events": [...]
}
```

> Bij datasets >10 MB: HTTP 202 met job-ID voor asynchrone verwerking.

**Fouten:** `403` `FORBIDDEN_DATA_EXPORT`

---

## E-mail flows

### POST /internal/email/dispatch

E-mail handmatig dispatchen via de outbox (idempotent).

**Auth:** Ja | **Rollen:** owner, manager

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `recipient` | email | Ja | Ontvanger |
| `subject` | string | Ja | Onderwerp (max 500) |
| `body_text` | string | Ja | Platte-tekst body |
| `body_html` | string | Ja | HTML-body |
| `type` | string | Nee | E-mailtype voor logging |
| `user_id` | integer | Nee | Gekoppelde gebruiker |
| `idempotency_key` | string | Nee | Voorkomt dubbele verzending (max 128) |

**Response 202:** `{ "status": "queued", "outbox_id": 101 }`

---

### PUT /internal/email/templates/{type}

E-mailtemplate aanmaken of bijwerken.

**Auth:** Ja | **Rollen:** owner, manager

**URL-parameter:** `type` — Een van de 11 template-types (zie E-mailsysteem documentatie)

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `subject_template` | string | Ja | Onderwerp met placeholders |
| `body_text_template` | string | Ja | Platte-tekst met placeholders |
| `body_html_template` | string | Ja | HTML met placeholders |
| `is_active` | boolean | Nee | Aan/uit (default: true) |

**Geldige types:** `welcome_email`, `password_reset`, `work_entry_finalized`, `work_entry_updated`, `work_entry_deleted`, `objection_review`, `atw_warning`, `atw_critical`, `pending_input_reminder`, `monthly_report`, `anniversary`

**Response 200:**
```json
{
  "status": "ok",
  "template": { "type": "welcome_email", "subject_template": "...", "is_active": true }
}
```

---

### GET /internal/email/templates/{type}

E-mailtemplate ophalen.

**Auth:** Ja | **Rollen:** owner, manager

**Response 200:** Template-object (zelfde structuur als PUT-response)

---

### POST /internal/jobs/monthly-report

Maandrapport-job handmatig triggeren.

**Auth:** Ja | **Rollen:** owner

**Response 202:** `{ "status": "queued", "job": "monthly-report" }`

---

## Audit

### GET /internal/audit/export

Auditlog exporteren (eigen organisatie).

**Auth:** Ja | **Rollen:** owner, manager

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `action` | string | Nee | Filter op actie-type |
| `target_type` | string | Nee | Filter op doeltype |
| `target_id` | integer | Nee | Filter op doel-ID |
| `actor_id` | integer | Nee | Filter op actor |
| `start_date` | date | Nee | Begindatum |
| `end_date` | date | Nee | Einddatum |

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "action": "WORK_ENTRY_CREATED",
      "actor_id": 42,
      "target_type": "WorkEntry",
      "target_id": "456",
      "before_data": null,
      "after_data": { "net_minutes": 480 },
      "created_at": "2026-05-16T09:00:00+02:00"
    }
  ],
  "count": 1
}
```

**Audit-event types:**
- `WORK_ENTRY_CREATED`, `WORK_ENTRY_UPDATED`, `WORK_ENTRY_DELETED`
- `ATW_VIOLATION_BLOCKED`
- `PROJECT_CREATED`, `PROJECT_UPDATED`, `PROJECT_DELETED`
- `COST_CENTER_CREATED`, `COST_CENTER_UPDATED`, `COST_CENTER_DELETED`
- `ACCOUNT_PSEUDONYMIZED`
- `ANNIVERSARY_DISPATCHED`
- `BACKUP_INTEGRITY_FAILED`, `BACKUP_JOB_FAILED`

---

## Systeem

### GET /health

Liveness-check (database-connectiviteit).

**Auth:** Nee

**Response 200:**
```json
{
  "status": "ok",
  "service": "lavita-ur-laravel-rebuild",
  "checks": { "app": "ok", "database": "ok" },
  "timestamp": "2026-05-16T12:00:00+02:00"
}
```

**Response 503:** Database onbereikbaar

---

### GET /ready

Readiness-check.

**Auth:** Nee

**Response 200:** `{ "status": "ready", "service": "lavita-ur-laravel-rebuild" }`

**Response 503:** `{ "status": "not_ready" }`

---

## Foutcodes

### HTTP-statuscodes

| Code | Betekenis |
|------|-----------|
| `200` | Succes |
| `201` | Aangemaakt |
| `202` | Geaccepteerd (async verwerking) |
| `204` | Succes, geen body (DELETE) |
| `308` | Permanent redirect (HTTP → HTTPS) |
| `401` | Authenticatie vereist of sessie verlopen |
| `403` | Onvoldoende rechten |
| `404` | Niet gevonden |
| `409` | Conflict |
| `422` | Validatiefout |
| `429` | Rate limit overschreden |
| `500` | Interne serverfout |
| `503` | Service onbeschikbaar |

### Applicatie-foutcodes

Alle foutresponses volgen het formaat:
```json
{
  "error": "Mensleesbare NL-melding",
  "code": "MACHINE_LEESBARE_CODE",
  "errors": { "veld": ["specifieke foutmelding"] }
}
```

| Code | HTTP | Bron | Beschrijving |
|------|------|------|--------------|
| `ATW_PAUSE_REQUIRED` | 422 | Werkregels | Pauze <30 min bij dienst >5,5u |
| `ATW_DAILY_MAX_EXCEEDED` | 422 | Werkregels | Netto werktijd ≥12u |
| `ATW_WEEKLY_MAX_EXCEEDED` | 422 | Werkregels | Weektotaal ≥60u |
| `ATW_REST_PERIOD_VIOLATED` | 422 | Werkregels | Rusttijd <11u |
| `READ_ONLY_ROLE` | 403 | Middleware | Boekhouder probeert te schrijven |
| `FORBIDDEN_ROLE` | 403 | Controllers | Rol heeft geen toegang |
| `FORBIDDEN_TEAM_SCOPE` | 403 | Werkregels | Manager buiten eigen team |
| `FORBIDDEN_OWNER_SCOPE` | 403 | Werkregels | Employee bekijkt andermans entry |
| `FORBIDDEN_DATA_EXPORT` | 403 | Accounts | Geen recht op data-export |
| `OBJECTION_OPEN` | 409 | Werkregels | Actief bezwaar blokkeert wijziging |
| `OPEN_OBJECTIONS` | 409 | Accounts | Openstaande bezwaren blokkeren delete |
| `PROJECT_ORG_MISMATCH` | 422 | Werkregels | Project behoort tot andere organisatie |
| `COST_CENTER_ORG_MISMATCH` | 422 | Werkregels | Kostenplaats behoort tot andere org |
| `PROJECT_INACTIVE` | 422 | Werkregels | Project is gedeactiveerd |
| `COST_CENTER_INACTIVE` | 422 | Werkregels | Kostenplaats is gedeactiveerd |
| `INVALID_TYPE_FOR_ROLE` | 422 | Werkregels | Employee mag geen HOLIDAY registreren |
| `SOURCE_WEEK_EMPTY` | 422 | Copy-week | Bronweek bevat geen werkregels |
| `WELCOME_EMAIL_FAILED` | 500 | Accounts | Welkomstmail kon niet worden gequeued |
| `MFA_ROTATION_REQUIRED` | 403 | Auth | MFA-secret ouder dan 180 dagen |

---

## Autorisatiematrix

Overzicht van toegang per rol en endpoint:

| Endpoint | Owner | Manager | Employee | Boekhouder |
|----------|-------|---------|----------|------------|
| `POST /auth/login` | ✓ | ✓ | ✓ | ✓ |
| `POST /auth/mfa/verify` | ✓ | ✓ | ✓ | ✓ |
| `POST /auth/mfa/setup` | ✓ | ✓ | ✓ | ✓ |
| `POST /auth/logout` | ✓ | ✓ | ✓ | ✓ |
| `POST /auth/accounts` | ✓ | ✓ | ✗ | ✗ |
| `POST /auth/password-reset/*` | ✓ | ✓ | ✓ | ✓ |
| `POST /internal/work-entries` | ✓ | ✓ | ✗ | ✗ |
| `GET /internal/work-entries` | ✓ | ✓ (team) | ✗ | ✓ |
| `GET /internal/work-entries/{id}` | ✓ | ✓ (team) | ✓ (eigen) | ✓ |
| `PATCH /internal/work-entries/{id}` | ✓ | ✓ (team) | ✗ | ✗ |
| `DELETE /internal/work-entries/{id}` | ✓ | ✓ (team) | ✗ | ✗ |
| `POST /internal/work-entries/copy-week` | ✓ | ✓ | ✗ | ✗ |
| `POST /internal/work-entries/validate-atw` | ✓ | ✓ | ✗ | ✗ |
| `POST /internal/objections` | ✗ | ✗ | ✓ | ✗ |
| `POST /internal/objections/{id}/review` | ✓ | ✓ | ✗ | ✗ |
| `GET /internal/objections` | ✓ | ✓ | ✗ | ✓ |
| `GET /internal/atw/signals` | ✓ | ✓ | ✗ | ✓ |
| `GET /internal/projects` | ✓ | ✓ | ✗ | ✓ |
| `POST /internal/projects` | ✓ | ✗ | ✗ | ✗ |
| `PATCH /internal/projects/{id}` | ✓ | ✗ | ✗ | ✗ |
| `DELETE /internal/projects/{id}` | ✓ | ✗ | ✗ | ✗ |
| `GET /internal/cost-centers` | ✓ | ✓ | ✗ | ✓ |
| `POST /internal/cost-centers` | ✓ | ✗ | ✗ | ✗ |
| `PATCH /internal/cost-centers/{id}` | ✓ | ✗ | ✗ | ✗ |
| `DELETE /internal/cost-centers/{id}` | ✓ | ✗ | ✗ | ✗ |
| `GET /internal/reports/*/pdf` | ✓ | ✓ | ✗ | ✓ |
| `GET /internal/reports/*/excel` | ✓ | ✓ | ✗ | ✓ |
| `GET /internal/reports/cost-overview` | ✓ | ✓ | ✗ | ✓ |
| `GET /internal/reports/year-export` | ✓ | ✓ | ✗ | ✓ |
| `GET /internal/holidays` | ✓ | ✓ | ✓ | ✓ |
| `DELETE /internal/accounts/{id}` | ✓ | ✗ | ✗ | ✗ |
| `GET /internal/accounts/{id}/data-export` | ✓ | ✗ | ✓ (eigen) | ✗ |
| `POST /internal/email/dispatch` | ✓ | ✓ | ✗ | ✗ |
| `PUT /internal/email/templates/{type}` | ✓ | ✓ | ✗ | ✗ |
| `GET /internal/email/templates/{type}` | ✓ | ✓ | ✗ | ✗ |
| `POST /internal/jobs/monthly-report` | ✓ | ✗ | ✗ | ✗ |
| `GET /internal/audit/export` | ✓ | ✓ | ✗ | ✗ |

**Legenda:**
- ✓ = Toegang
- ✓ (team) = Alleen binnen eigen team
- ✓ (eigen) = Alleen eigen data
- ✗ = Geen toegang (403)

---

## Rate limiting

| Groep | Limiet | Scope |
|-------|--------|-------|
| `auth` | 20/min | Per IP |
| `mfa` | 5/min | Per user_id + IP |
| `api` | 60/min | Per bearer token |

Bij overschrijding: HTTP 429 met `Retry-After` header.

---

## Middleware-stack (beveiligde routes)

Alle `/api/internal/*` routes passeren:

1. **`internal.auth`** — Bearer-token validatie + sessie-check
2. **`throttle:api`** — Rate limiting (60/min)
3. **`bookkeeper.readonly`** — Blokkeert non-GET voor boekhouder (403 `READ_ONLY_ROLE`)

---

*Versie: mei 2026*
