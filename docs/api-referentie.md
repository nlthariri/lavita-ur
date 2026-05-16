# API-referentie — LaVita Urenregistratie

Volledige referentie van alle API-endpoints, inclusief request-parameters, response-structuren en foutcodes.

**Base URL:** `https://uren.uw-domein.nl/api`  
**Content-Type:** `application/json`  
**Authenticatie:** `Authorization: Bearer <session_token>` (tenzij anders vermeld)

---

## Inhoudsopgave

- [Authenticatie](#authenticatie)
  - [POST /auth/login](#post-authlogin)
  - [POST /auth/logout](#post-authlogout)
  - [POST /auth/mfa/verify](#post-authmfaverify)
  - [POST /auth/mfa/setup](#post-authmfasetup)
  - [POST /auth/accounts](#post-authaccounts)
  - [POST /auth/password-reset/request](#post-authpassword-resetrequest)
  - [POST /auth/password-reset/confirm](#post-authpassword-resetconfirm)
- [Uurregistraties](#uurregistraties)
  - [POST /internal/work-entries](#post-internalwork-entries)
  - [GET /internal/work-entries](#get-internalwork-entries)
- [Bezwaren](#bezwaren)
  - [POST /internal/objections](#post-internalobjections)
  - [POST /internal/objections/{id}/review](#post-internalobjectionidreview)
  - [GET /internal/objections](#get-internalobjections)
- [ATW-signalen](#atw-signalen)
  - [POST /internal/work-entries/validate-atw](#post-internalwork-entriesvalidate-atw)
  - [GET /internal/atw/signals](#get-internalatw-signals)
- [Rapporten](#rapporten)
  - [GET /internal/reports/work-entries/pdf](#get-internalreportswork-entriespdf)
  - [GET /internal/reports/work-entries/excel](#get-internalreportswork-entriesexcel)
- [E-mail flows](#e-mail-flows)
  - [POST /internal/email/dispatch](#post-internalemaildispatch)
  - [PUT /internal/email/templates/{type}](#put-internalemailtemplatetype)
  - [GET /internal/email/templates/{type}](#get-internalemailtemplatetype)
  - [POST /internal/jobs/monthly-report](#post-internaljobsmonthly-report)
- [Audit](#audit)
  - [GET /internal/audit/export](#get-internalauditexport)
- [Systeem](#systeem)
  - [GET /health](#get-health)
  - [GET /ready](#get-ready)
- [Foutcodes](#foutcodes)

---

## Authenticatie

### POST /auth/login

Inloggen met e-mailadres en wachtwoord.

**Rate limit:** 20 verzoeken per minuut per IP  
**Auth vereist:** Nee

**Request:**

```json
{
  "email": "eigenaar@uw-bedrijf.nl",
  "password": "MinimaalTwaalf1!"
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `email` | string | Ja | Geldig RFC-e-mailadres |
| `password` | string | Ja | Minimaal 12 tekens |

**Response 200:**

```json
{
  "status": "ok",
  "module": "AuthModule",
  "scope": "MUST-AUTH-MFA",
  "user_id": 42,
  "session_token": "abcdef1234...",
  "expires_at": "2026-05-17T14:00:00+00:00",
  "mfa_required": true
}
```

> Gebruik `session_token` als Bearer-token voor vervolgverzoeken.  
> Als `mfa_required: true` is, verifieer daarna via `POST /auth/mfa/verify`.

**Fouten:**
- `422` — Ongeldige inloggegevens of account gedeactiveerd

---

### POST /auth/logout

Huidige sessie intrekken.

**Auth vereist:** Ja (Bearer token)

**Request:** *(geen body vereist)*

**Response 200:**

```json
{
  "status": "ok",
  "module": "AuthModule",
  "scope": "MUST-AUTH-MFA",
  "revoked": true
}
```

---

### POST /auth/mfa/verify

MFA-code verifiëren na inloggen. Accepteert zowel een 6-cijferige TOTP-code als een 10-teken recovery-code.

**Rate limit:** 5 verzoeken per minuut per `user_id` + IP  
**Auth vereist:** Nee (gebruikt `user_id` uit de body)

**Request:**

```json
{
  "user_id": 42,
  "code": "123456"
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `user_id` | integer | Ja | ID van de gebruiker die MFA verifieert |
| `code` | string | Ja | 6-cijferige TOTP of 10-teken recovery-code |

**Response 200:**

```json
{
  "status": "ok",
  "module": "AuthModule",
  "scope": "MUST-AUTH-MFA",
  "verified": true
}
```

**Fouten:**
- `422` — Ongeldige MFA-code
- `429` — Rate limit overschreden (5/min per user+IP)

---

### POST /auth/mfa/setup

MFA instellen of roteren voor de eigen account. Genereert een nieuw TOTP-secret en 8 recovery codes.

**Auth vereist:** Ja (Bearer token)

**Request:**

```json
{
  "user_id": 42,
  "password_confirmation": "UwHuidigWachtwoord12!"
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `user_id` | integer | Ja | Moet overeenkomen met eigen user-ID |
| `password_confirmation` | string | Ja | Huidig wachtwoord ter bevestiging |

**Response 201:**

```json
{
  "status": "ok",
  "module": "AuthModule",
  "scope": "MUST-AUTH-MFA",
  "user_id": 42,
  "issuer": "LaVita Urenregistratie",
  "label": "eigenaar@uw-bedrijf.nl",
  "provisioning_secret_last4": "ABCD",
  "provisioning_secret": "BASE32SECRET...",
  "recovery_codes": [
    "ABCDE12345",
    "FGHIJ67890",
    "..."
  ]
}
```

> **Belangrijk:** Sla de `recovery_codes` éénmalig op. Ze worden nooit opnieuw getoond.  
> Scan `provisioning_secret` in een authenticator-app (Google Authenticator, Authy, etc.).

**Fouten:**
- `403` — Poging om MFA in te stellen voor een ander account
- `422` — Verkeerd wachtwoord

---

### POST /auth/accounts

Nieuw gebruikersaccount aanmaken. Vereist re-authenticatie van de aanmakende gebruiker.

**Auth vereist:** Ja (Bearer token) | **Rollen:** `owner`, `manager`

**Request:**

```json
{
  "password_confirmation": "EigenWachtwoord12!",
  "name": "jan.de.vries",
  "full_name": "Jan de Vries",
  "email": "jan@uw-bedrijf.nl",
  "role": "employee",
  "team_id": 3,
  "is_active": true,
  "employment_start": "2026-06-01",
  "employment_end": null
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `password_confirmation` | string | Ja | Wachtwoord van de aanmakende gebruiker |
| `name` | string | Ja | Gebruikersnaam / login-naam (max 255) |
| `full_name` | string | Nee | Volledige naam |
| `email` | string | Ja | Uniek RFC-e-mailadres (max 254) |
| `role` | string | Ja | `manager`, `employee` of `boekhouder` (owner kan niet worden aangemaakt) |
| `team_id` | integer | Nee | Team-ID |
| `is_active` | boolean | Nee | Standaard: `true` |
| `employment_start` | date (Y-m-d) | Nee | Datum in dienst |
| `employment_end` | date (Y-m-d) | Nee | Datum uit dienst (na employment_start) |

**Response 201:**

```json
{
  "status": "ok",
  "module": "AuthModule",
  "scope": "MUST-AUTH-ACCOUNT-CREATE",
  "account": {
    "id": 123,
    "name": "jan.de.vries",
    "email": "jan@uw-bedrijf.nl",
    "role": "employee"
  }
}
```

**Fouten:**
- `403` — Onvoldoende rechten
- `422` — Verkeerd wachtwoord of validatiefouten (bijv. e-mail al in gebruik)

---

### POST /auth/password-reset/request

Wachtwoord-reset aanvragen. Stuurt een resetlink naar het opgegeven e-mailadres (als het bestaat).

**Rate limit:** 20 verzoeken per minuut per IP  
**Auth vereist:** Nee

**Request:**

```json
{
  "email": "jan@uw-bedrijf.nl"
}
```

**Response 200:**

```json
{
  "ok": true,
  "message": "Als dit e-mailadres bestaat, ontvang je een resetlink."
}
```

> De response is altijd hetzelfde, ongeacht of het e-mailadres bestaat. Dit voorkomt account-enumeratie.

---

### POST /auth/password-reset/confirm

Nieuw wachtwoord instellen met een reset-token.

**Rate limit:** 20 verzoeken per minuut per IP  
**Auth vereist:** Nee

**Request:**

```json
{
  "token": "resettoken-uit-e-mail",
  "password": "NieuwWachtwoord12!"
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `token` | string | Ja | Token uit de resetlink in de e-mail |
| `password` | string | Ja | Nieuw wachtwoord (min 10, max 128 tekens) |

**Response 200:**

```json
{
  "ok": true,
  "message": "Wachtwoord succesvol gewijzigd."
}
```

**Fouten:**
- `422` — Ongeldig of verlopen token

---

## Uurregistraties

### POST /internal/work-entries

Nieuwe uurregistratie aanmaken voor een medewerker.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Request:**

```json
{
  "employee_id": 99,
  "entry_date": "2026-05-16",
  "start_time": "08:00",
  "end_time": "17:00",
  "pause_minutes": 60,
  "type": "WORK",
  "note": "Normale werkdag"
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `employee_id` | integer | Ja | ID van de medewerker |
| `entry_date` | date (Y-m-d) | Ja | Datum van de registratie |
| `start_time` | string (HH:MM) | Ja | Begintijd (24-uurs formaat) |
| `end_time` | string (HH:MM) | Ja | Eindtijd (24-uurs formaat, moet na begintijd) |
| `pause_minutes` | integer | Ja | Pauze in minuten (0–240). Bij dienst > 5,5 uur: min 60 |
| `type` | string | Nee | `WORK`, `SICK`, `HOLIDAY`, `OTHER` (standaard: `WORK`) |
| `note` | string | Nee | Optionele opmerking (max 500 tekens) |

**Response 201:**

```json
{
  "id": 456,
  "employee_id": 99,
  "entry_date": "2026-05-16",
  "start_time": "08:00",
  "end_time": "17:00",
  "pause_minutes": 60,
  "net_minutes": 480,
  "type": "WORK",
  "note": "Normale werkdag"
}
```

**Fouten:**
- `403` — Onvoldoende rechten
- `422` — Validatiefout (bijv. eindtijd voor begintijd, pauze te kort bij lange dienst)

---

### GET /internal/work-entries

Uurregistraties ophalen. Geeft alleen registraties terug van de eigen organisatie.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Query parameters:**

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `employee_id` | integer | Nee | Filter op medewerker-ID |
| `from` | date (Y-m-d) | Nee | Van datum |
| `to` | date (Y-m-d) | Nee | Tot datum |

**Voorbeeld:** `GET /api/internal/work-entries?employee_id=99&from=2026-05-01&to=2026-05-31`

**Response 200:**

```json
{
  "data": [
    {
      "id": 456,
      "employee_id": 99,
      "entry_date": "2026-05-16",
      "start_time": "08:00",
      "end_time": "17:00",
      "pause_minutes": 60,
      "net_minutes": 480,
      "type": "WORK"
    }
  ],
  "count": 1
}
```

---

## Bezwaren

### POST /internal/objections

Bezwaar indienen op een uurregistratie.

**Auth vereist:** Ja | **Rollen:** `employee`

**Request:**

```json
{
  "work_entry_id": 456,
  "motivation": "Mijn start- en eindtijd kloppen niet. Ik ben om 07:30 begonnen."
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `work_entry_id` | integer | Ja | ID van de betwiste uurregistratie |
| `motivation` | string | Ja | Motivatie (min 10, max 2000 tekens) |

**Response 201:**

```json
{
  "id": 789,
  "work_entry_id": 456,
  "status": "OPEN",
  "motivation": "Mijn start- en eindtijd kloppen niet...",
  "submitted_at": "2026-05-16T10:30:00+00:00"
}
```

---

### POST /internal/objections/{id}/review

Bezwaar beoordelen (goedkeuren of afwijzen).

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Request:**

```json
{
  "decision": "APPROVED",
  "manager_response": "Akkoord, tijden zijn gecorrigeerd.",
  "corrected_start_time": "07:30",
  "corrected_end_time": "16:30",
  "corrected_pause_minutes": 60
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `decision` | string | Ja | `APPROVED` of `REJECTED` |
| `manager_response` | string | Nee | Toelichting van de beoordelaar (max 2000) |
| `corrected_start_time` | string (HH:MM) | Nee | Gecorrigeerde begintijd (bij APPROVED) |
| `corrected_end_time` | string (HH:MM) | Nee | Gecorrigeerde eindtijd (bij APPROVED) |
| `corrected_pause_minutes` | integer | Nee | Gecorrigeerde pauze (bij APPROVED) |

**Response 200:**

```json
{
  "id": 789,
  "status": "APPROVED",
  "decision": "APPROVED",
  "manager_response": "Akkoord, tijden zijn gecorrigeerd.",
  "reviewed_at": "2026-05-16T11:00:00+00:00"
}
```

---

### GET /internal/objections

Bezwaren ophalen voor de eigen organisatie.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Query parameters:**

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `status` | string | Nee | Filter: `OPEN`, `APPROVED`, `REJECTED` |

**Response 200:**

```json
{
  "data": [...],
  "count": 5
}
```

---

## ATW-signalen

### POST /internal/work-entries/validate-atw

ATW-controle uitvoeren voor een geplande dienst, zonder de dienst daadwerkelijk op te slaan.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Request:**

```json
{
  "employee_id": 99,
  "entry_date": "2026-05-16",
  "start_time": "06:00",
  "end_time": "22:00",
  "pause_minutes": 60
}
```

**Response 200:**

```json
{
  "signals": [
    {
      "type": "DAILY_LIMIT",
      "severity": "critical",
      "message": "Daglimiet bereikt of overschreden (12 uur).",
      "threshold_minutes": 720,
      "current_minutes": 840
    }
  ],
  "has_critical": true,
  "has_warning": false
}
```

**Signaaltypen:**

| Type | Drempel | Ernst |
|------|---------|-------|
| `DAILY_LIMIT` | Netto ≥ 720 min | critical |
| `WEEKLY_WARNING` | Weektotaal ≥ 2880 min | warning |
| `WEEKLY_LIMIT` | Weektotaal ≥ 3600 min | critical |
| `SIXTEEN_WEEK_AVERAGE` | Gemiddelde > drempel | warning/critical |
| `REST_PERIOD` | Rustperiode < 660 min | critical |

---

### GET /internal/atw/signals

Actieve ATW-signalen ophalen voor een specifieke medewerker.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Query parameters:**

| Parameter | Type | Verplicht | Omschrijving |
|-----------|------|-----------|--------------|
| `user_id` | integer | Ja | Medewerker-ID |

**Response 200:**

```json
{
  "data": [...],
  "count": 2
}
```

---

## Rapporten

### GET /internal/reports/work-entries/pdf

PDF-rapport van uurregistraties genereren en downloaden.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`  
**Response:** `application/pdf` (bestand-download)

**Query parameters:** zie `GET /internal/work-entries` (zelfde filters)

---

### GET /internal/reports/work-entries/excel

Excel-rapport van uurregistraties genereren en downloaden.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`  
**Response:** `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`

**Query parameters:** zie `GET /internal/work-entries` (zelfde filters)

---

## E-mail flows

### POST /internal/email/dispatch

E-mail handmatig dispatchen via de outbox (idempotent).

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Request:**

```json
{
  "recipient": "jan@uw-bedrijf.nl",
  "subject": "Uw uurregistratie is bijgewerkt",
  "body_text": "Beste Jan, ...",
  "body_html": "<p>Beste Jan, ...</p>",
  "type": "work_entry_finalized",
  "user_id": 99,
  "idempotency_key": "entry-456-notify-2026-05-16"
}
```

| Veld | Type | Verplicht | Omschrijving |
|------|------|-----------|--------------|
| `recipient` | email | Ja | Ontvanger |
| `subject` | string | Ja | Onderwerp (max 500) |
| `body_text` | string | Ja | Platte-tekst body |
| `body_html` | string | Ja | HTML-body |
| `type` | string | Nee | E-mailtype voor audit-logging |
| `user_id` | integer | Nee | Gekoppelde gebruiker |
| `idempotency_key` | string | Nee | Voorkomt dubbele verzending (max 128) |

**Response 202:**

```json
{
  "status": "queued",
  "outbox_id": 101
}
```

---

### PUT /internal/email/templates/{type}

E-mailtemplate aanmaken of bijwerken voor een specifiek e-mailtype.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`  
**URL-parameter:** `type` — Naam van het template (bijv. `work_entry_finalized`, `atw_warning`)

**Request:**

```json
{
  "subject_template": "Uurregistratie {{entry_date}} verwerkt",
  "body_text_template": "Beste {{name}}, uw registratie is verwerkt.",
  "body_html_template": "<p>Beste {{name}}, uw registratie is verwerkt.</p>",
  "is_active": true
}
```

**Response 200:**

```json
{
  "status": "ok",
  "template": {
    "type": "work_entry_finalized",
    "subject_template": "Uurregistratie {{entry_date}} verwerkt",
    "is_active": true
  }
}
```

---

### GET /internal/email/templates/{type}

E-mailtemplate ophalen.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Response 200:** zie response van `PUT /internal/email/templates/{type}`

---

### POST /internal/jobs/monthly-report

Maandrapport-job handmatig triggeren.

**Auth vereist:** Ja | **Rollen:** `owner`

**Request:** *(geen body vereist)*

**Response 202:**

```json
{
  "status": "queued",
  "job": "monthly-report"
}
```

---

## Audit

### GET /internal/audit/export

Auditlog exporteren voor de eigen organisatie.

**Auth vereist:** Ja | **Rollen:** `owner`, `manager`

**Query parameters:**

| Parameter | Type | Omschrijving |
|-----------|------|--------------|
| `action` | string | Filter op actie-type |
| `target_type` | string | Filter op doeltype (bijv. `WorkEntry`) |
| `target_id` | integer | Filter op doel-ID |
| `actor_id` | integer | Filter op handelnde gebruiker |
| `start_date` | date (Y-m-d) | Begindatum |
| `end_date` | date (Y-m-d) | Einddatum |

**Response 200:**

```json
{
  "data": [
    {
      "id": 1,
      "action": "work_entry.created",
      "actor_id": 42,
      "target_type": "WorkEntry",
      "target_id": 456,
      "created_at": "2026-05-16T09:00:00+00:00"
    }
  ],
  "count": 1
}
```

---

## Systeem

### GET /health

Liveness-check. Controleert of de applicatie draait en de database bereikbaar is.

**Auth vereist:** Nee

**Response 200 (gezond):**

```json
{
  "status": "ok",
  "service": "lavita-ur-laravel-rebuild",
  "checks": {
    "app": "ok",
    "database": "ok"
  },
  "timestamp": "2026-05-16T12:00:00+00:00"
}
```

**Response 503 (database onbereikbaar):**

```json
{
  "status": "degraded",
  "checks": {
    "app": "ok",
    "database": "down"
  }
}
```

---

### GET /ready

Readiness-check. Controleert of de applicatie klaar is voor verzoeken.

**Auth vereist:** Nee

**Response 200:**

```json
{
  "status": "ready",
  "service": "lavita-ur-laravel-rebuild",
  "timestamp": "2026-05-16T12:00:00+00:00"
}
```

**Response 503:** `{ "status": "not_ready" }`

---

## Foutcodes

| HTTP-code | Betekenis |
|-----------|-----------|
| `200` | Succes |
| `201` | Aangemaakt |
| `202` | Geaccepteerd (async verwerking) |
| `401` | Authenticatie vereist of sessie verlopen |
| `403` | Onvoldoende rechten, MFA vereist, of MFA-rotatie vereist |
| `404` | Niet gevonden |
| `422` | Validatiefout — zie `errors` in response body |
| `429` | Rate limit overschreden — zie `Retry-After` header |
| `500` | Interne serverfout |
| `503` | Service onbeschikbaar (health/ready) |

### MFA-specifieke foutcodes

Bij `403` kan de response een `code`-veld bevatten:

| Code | Betekenis |
|------|-----------|
| `MFA_ROTATION_REQUIRED` | MFA-secret ouder dan 180 dagen — roteer via `POST /auth/mfa/setup` |

### Validatiefouten (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["Het e-mailadres is al in gebruik."],
    "pause_minutes": ["Bij meer dan 5,5 uur werken is minimaal 60 minuten pauze verplicht."]
  }
}
```

---

*Versie: 16 mei 2026*
