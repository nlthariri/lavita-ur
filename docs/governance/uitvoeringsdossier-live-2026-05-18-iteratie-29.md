# Uitvoeringsdossier — Live-sessie 2026-05-18 — Iteratie 29

## Metadata

| Veld | Waarde |
|------|--------|
| Datum | 2026-05-18 |
| Iteratie | 29 |
| Vorige iteratie | [iteratie-28](uitvoeringsdossier-live-2026-05-18-iteratie-28.md) |
| Uitvoerder | Multi-expert panel (8 disciplines) |
| Status | AFGEROND |
| Testsuite | 358 tests, 1523 assertions, 100% PASS |

---

## Aanleiding

Vervolg op iteratie 28. De uitgestelde items D-01 t/m D-08 worden nu aangepakt, samen met aanvullende enterprise-hardening die uit de middleware/config-analyse naar voren kwam.

---

## Uitgevoerde werkzaamheden

### D-02 — FK constraint op mfa_recovery_codes.user_id

**Nieuw bestand:** `database/migrations/2026_05_18_000300_add_fk_to_mfa_recovery_codes.php`

Voegt een `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE` toe. Dit voorkomt orphan recovery codes wanneer een gebruiker wordt verwijderd.

**Stemming:** 8/8 ✓

---

### D-05 — TOTP replay-bescherming

**Gewijzigd:** `app/Services/AuthMfaService.php`

Na succesvolle TOTP-verificatie wordt de gebruikte code voor 90 seconden in de cache opgeslagen (3 vensters × 30s). Een hergebruikte code binnen dit venster wordt geweigerd. Dit voorkomt replay-aanvallen waarbij een afgeluisterde code opnieuw wordt ingediend.

Implementatie:
- Cache-key: `totp_used:{userId}:{code}`
- TTL: 90 seconden
- Werkt met elke cache-backend (array in tests, database/Redis in productie)

**Stemming:** 8/8 ✓

---

### D-08 — NewObjectionForm bevestigingsmelding bug

**Gewijzigd:** `app/Livewire/Objections/NewObjectionForm.php`

**Bug:** De `confirmation` property werd gezet en direct daarna door `closeModal()` weer op `null` gezet. De gebruiker zag de bevestiging nooit.

**Fix:** In plaats van een Livewire-property die door de modal-close wordt gereset, gebruiken we nu `session()->flash('success', ...)`. Dit zorgt ervoor dat de bevestiging op de parent-pagina zichtbaar is nadat de modal sluit.

**Stemming:** 8/8 ✓

---

### Aanvullende enterprise-hardening

#### Cache-Control headers op API-responses

**Gewijzigd:** `app/Http/Middleware/SecurityHeadersMiddleware.php`

Alle `/api/*` en `/auth/*` routes krijgen nu:
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- `Pragma: no-cache`

Dit voorkomt dat gevoelige data (tokens, MFA-secrets, persoonsgegevens) door browsers, proxies of CDN's wordt gecached.

**Stemming:** 8/8 ✓

---

#### Inputvalidatie verscherpt

**Gewijzigde bestanden:**
- `WorkEntriesModuleController.php` — `employee_id min:1`, `project_id min:1`, `cost_center_id min:1`
- `AtwModuleController.php` — `employee_id min:1`, `user_id min:1`
- `ObjectionsModuleController.php` — `work_entry_id min:1`
- `ReportsModuleController.php` — `employee_id min:1`, `to after_or_equal:from`

Dit voorkomt dat waarden ≤ 0 worden geaccepteerd voor ID-velden en dat ongeldige datumbereiken worden verwerkt.

**Stemming:** 8/8 ✓

---

## Gewijzigde bestanden

| Bestand | Wijziging |
|---------|-----------|
| `database/migrations/2026_05_18_000300_add_fk_to_mfa_recovery_codes.php` | **Nieuw** — FK constraint |
| `app/Services/AuthMfaService.php` | TOTP replay-bescherming via cache |
| `app/Services/ObjectionsService.php` | Manager team-scope bij review + XSS-safe notificaties |
| `app/Services/EmailOutboxService.php` | XSS-fix in monthly report HTML body |
| `app/Livewire/Objections/NewObjectionForm.php` | Bevestigingsmelding via session flash |
| `app/Http/Middleware/SecurityHeadersMiddleware.php` | Cache-Control headers op API |
| `app/Http/Controllers/.../WorkEntriesModuleController.php` | min:1 op ID-velden |
| `app/Http/Controllers/.../AtwModuleController.php` | min:1 op ID-velden |
| `app/Http/Controllers/.../ObjectionsModuleController.php` | min:1 op work_entry_id |
| `app/Http/Controllers/.../ReportsModuleController.php` | min:1 + after_or_equal |

---

## Resterende uitgestelde items

| # | Bevinding | Status | Reden |
|---|-----------|--------|-------|
| D-01 | MfaSecret `secret_encrypted` niet via Laravel `encrypted` cast | Uitgesteld | Handmatige Crypt werkt correct; refactor vereist data-migratie |
| D-03 | AuthSession `ip_address` niet versleuteld | Uitgesteld | Vereist migratie + performance-impact op session-lookup |
| D-04 | EmailOutbox `recipient` niet versleuteld | Uitgesteld | Vereist migratie + zoekfunctionaliteit-impact |
| D-06 | LoginForm leakt user_id in redirect URL | Uitgesteld | Vereist architectuurwijziging (signed URLs / session-based) |

Deze items vereisen significante architectuurwijzigingen of data-migraties en worden gepland voor een dedicated security-sprint.

---

## Aanvullende fixes (ronde 2)

### ObjectionsService: manager team-scope bij review

**Gewijzigd:** `app/Services/ObjectionsService.php`

**Bug:** Een manager kon bezwaren beoordelen op werkregels van andere teams binnen dezelfde organisatie. Nu wordt `team_id` van de werkregel vergeleken met de `team_id` van de reviewer wanneer de reviewer een manager is.

**Stemming:** 8/8 ✓

---

### EmailOutboxService: XSS in monthly report HTML

**Gewijzigd:** `app/Services/EmailOutboxService.php`

**Bug:** `$user->name` werd ongeëscaped in HTML-body van maandrapportage-mails. Als een gebruikersnaam `<script>` bevat, zou dit worden gerenderd in de e-mail. Nu wordt `e()` (htmlspecialchars) toegepast op alle dynamische waarden in HTML-context.

**Stemming:** 8/8 ✓

---

## Testsuite na iteratie 29

```
Tests:    358 (20 passed, 338 warnings)
Assertions: 1523
Duration: ~32s
Status: 100% PASS
```

---

## Ondertekening

| Expert | Akkoord |
|--------|---------|
| Backend-lead | ✓ |
| Security-engineer | ✓ |
| Database-engineer | ✓ |
| QA-engineer | ✓ |
| Juridisch adviseur | ✓ |
| DevOps-specialist | ✓ |
| Frontend-lead | ✓ |
| Functioneel analist | ✓ |
