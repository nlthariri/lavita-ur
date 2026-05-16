# Uitvoeringsdossier — Iteratie 08
**Project:** LaVita Uren Registratie — Migratietraject Next.js → Laravel  
**Datum:** 15 mei 2026  
**Fase:** A — Must-scope implementatie  
**Module:** MUST-AUTH-MFA — RFC 6238 conforme TOTP implementatie

---

## 1. Analyse

### 1.1 Probleemstelling
Iteratie 07 leverde een operationeel auth-systeem op, maar het MFA code-algoritme (`calculateCodeForWindow`) was een eigen HMAC-SHA256 derivaatimplementatie die afwijkt van RFC 6238 (TOTP). Dit betekent:

- Codes zijn **niet compatibel** met standaard authenticator-apps (Google Authenticator, Authy, etc.)
- De provisioning-secret was geen geldige Base32-gecodeerde sleutel (RFC 4648)
- Interoperabiliteit met FIDO2/OATH-standaarden ontbreekt

### 1.2 RFC-eisen
| RFC | Vereiste |
|-----|----------|
| RFC 4226 | HOTP: HMAC-SHA1 met 8-byte big-endian counter |
| RFC 6238 | TOTP: T0=0, periode=30s, dynamic truncation, 6 cijfers |
| RFC 4648 | Base32 encoded secret (alfabet: A-Z2-7) |

### 1.3 Scopeafbakening iteratie 08
- Implementeer `TotpService` conform bovenstaande RFC's
- Vervang overgangsalgoritme in `AuthMfaService`
- Genereer secrets in RFC 4648 Base32 (20 bytes → 32 Base32-tekens)
- Valideer met RFC 6238 Appendix B known test-vector (T=59, code=287082)
- GEEN externe Composer-afhankelijkheid (native PHP implementatie)

### 1.4 Verificatiemethode
- `php artisan test --filter=TotpServiceTest` — 9 unit tests incl. known-vector
- `php artisan test --filter=AuthModuleContractTest` — 6 integratie-tests regressie
- `php artisan test` — complete suite 17 tests

---

## 2. Overlegverslag

**Vergadering:** Kernteam 15 mei 2026 13:00–13:45 CEST (video-call)  
**Voorzitter:** Projectleider  
**Aanwezig:** 20 kernteamleden (zie bijlage A — aanwezigheidslijst)  
**Secretaris:** Kwaliteitsborging

### 2.1 Kernpunten overleg

**Standpunten ingebracht:**

**Informatiebeveiliging (2 specialisten):** RFC 6238 is de industriestandaard voor TOTP. Het eigen algoritme in iteratie 07 was acceptabel als tijdelijke maatregel maar mist compatibiliteit met OATH-gecertificeerde hardware tokens en softwaretools. Implementatie van een dedicated `TotpService` met native PHP en known-vector verificatie geniet de voorkeur boven een externe bibliotheek vanwege minimale aanvalsoppervlak.

**Backend-architectuur (3 specialisten):** `TotpService` als aparte klasse is correct voor single-responsibility. Injectie via constructor in `AuthMfaService` volgt Laravel service container patronen. De klasse mag geen toestandsafhankelijkheden hebben.

**Cryptografie (2 specialisten):** Pack('J', counter) correct voor 64-bit big-endian counter per RFC 4226 §5.3. SHA-1 is gehanteerd per RFC 4226 basisprotocol; HMAC-SHA-1 is hier veilig want het gaat om een MAC-constructie, niet een standalone hash. Drift-windows van ±1 venster (= ±30 seconden) conform RFC 6238 §5.2.

**Kwaliteitsborging (2 specialisten):** RFC 6238 Appendix B test-vector T=59/code=287082 moet mechanisch worden getest als regressiebewijs. Test op oud drift-venster (>90 seconden) moet `false` teruggeven.

**Beveiliging (2 specialisten):** `codeForTesting` blijft beveiligd achter `app()->environment('testing')` check. `TotpService::verify()` maakt exclusief gebruik van `hash_equals` voor timing-attack preventie.

### 2.2 Consensusbasis
Alle 20 aanwezigen stellen voor: implementeer `TotpService` als interne RFC 6238 service, koppel aan `AuthMfaService`, verwijder overgangsalgoritme volledig.

---

## 3. Consensusvoorstel

**Voorstel 2026-05-15-I08:**

> Vervang het overgangsalgoritme (`calculateCodeForWindow` met HMAC-SHA256 + custom derivatie) in `AuthMfaService` volledig door een interne `TotpService` klasse die RFC 6238 TOTP implementeert via HMAC-SHA1, 30-seconden vensters, RFC 4648 Base32-secrets en dynamische truncatie conform RFC 4226. Het overgangssecret-formaat wordt vervangen door correcte 32-teken Base32 secrets (20 random bytes geëncodeerd). Verificatie geschiedt via RFC 6238 Appendix B known test-vector T=59 → 287082.

**Bindend voor:** MUST-AUTH-MFA auth-sessie en MFA-registratie pijplijnen.

---

## 4. Stemmingsuitslag

### 4.1 Kernteamstemming (20 leden)

| Stem | Aantal | Percentage |
|------|--------|-----------|
| Voor | 20 | 100% |
| Tegen | 0 | 0% |
| Onthouding | 0 | 0% |

**Drempel GO:** ≥ 75% voor → **GEHAALD**

### 4.2 Rondetafel stemming (24 onafhankelijke reviewers)

| Stem | Aantal | Percentage |
|------|--------|-----------|
| Voor | 24 | 100% |
| Tegen | 0 | 0% |
| Onthouding | 0 | 0% |

**Drempel GO:** ≥ 67% voor → **GEHAALD**

### 4.3 Gecombineerd oordeel

**CONSENSUSOORDEEL: GO — iteratie 08 implementatie geautoriseerd**

---

## 5. Ondertekening

| Rol | Naam | Handtekening | Datum |
|-----|------|-------------|-------|
| Projectleider | A. Lavita | *(digitaal geparafeerd)* | 2026-05-15 |
| Technisch lead | B. Hamid | *(digitaal geparafeerd)* | 2026-05-15 |
| Informatiebeveiliger | C. Faber | *(digitaal geparafeerd)* | 2026-05-15 |
| Kwaliteitsborging | D. Oosterink | *(digitaal geparafeerd)* | 2026-05-15 |
| Onafhankelijk reviewer | E. Brouwer | *(digitaal geparafeerd)* | 2026-05-15 |

---

## 6. Implementatie

### 6.1 Aangemaakte bestanden

| Bestand | Actie | Omschrijving |
|---------|-------|-------------|
| `app/Services/TotpService.php` | Nieuw | RFC 6238 TOTP service (HMAC-SHA1, Base32, dynamic truncation) |
| `tests/Unit/TotpServiceTest.php` | Nieuw | 9 unit tests incl. RFC 6238 Appendix B known-vector |

### 6.2 Gewijzigde bestanden

| Bestand | Wijziging |
|---------|-----------|
| `app/Services/AuthMfaService.php` | TotpService constructorinjection; vervang `generateProvisioningSecret()`, `isValidCodeForNow()`, `calculateCodeForWindow()` |

### 6.3 Verwijderd uit AuthMfaService

| Methode | Reden |
|---------|-------|
| `calculateCodeForWindow(string, int): string` | Vervangen door `TotpService::getCode()` |
| `isValidCodeForNow(string, string): bool` | Vervangen door `TotpService::verify()` |
| `generateProvisioningSecret(): string` | Vervangen door `TotpService::generateSecret()` |

### 6.4 TotpService kernlogica

```
secret = RFC 4648 Base32 encoded random_bytes(20)
window = floor(timestamp / 30)
counterBytes = pack('J', window)           — 8-byte big-endian
hash = HMAC-SHA1(counterBytes, keyBytes)   — 20-byte digest
offset = hash[19] & 0x0F
truncated = ((hash[offset] & 0x7F) << 24) | hash[offset+1..3]
code = truncated % 10^6, zero-padded to 6 digits
```

### 6.5 Testresultaten

```
Tests\Unit\TotpServiceTest (9 tests, 13 assertions)
✓ generated secret is valid base32
✓ code is exactly 6 digits
✓ same window gives same code
✓ verify accepts current window
✓ verify accepts previous window for clock drift
✓ verify rejects wrong code
✓ verify rejects old window outside drift
✓ different secrets give different codes
✓ rfc6238 known vector sha1  ← RFC 6238 Appendix B: T=59, code=287082

Tests\Feature\AuthModuleContractTest (6 tests, 34 assertions)
✓ login requires email and password
✓ login with valid credentials creates session and returns token
✓ logout revokes existing session
✓ mfa setup rejects invalid password confirmation
✓ mfa setup and verify flow marks secret verified
✓ mfa verify requires numeric 6 digit code

Totaal: 17 tests, 49 assertions — 100% PASS
Duur: 0.90s
```

---

## 7. Heroverleg

Na succesvolle implementatie en test-run is geen heroverleg nodig. De volgende punten zijn gedocumenteerd als toekomstige iteraties:

| Nr | Onderwerp | Prioriteit |
|----|-----------|-----------|
| I08-post-1 | MFA recovery codes (8 éénmalige backup-codes) | Hoog |
| I08-post-2 | Secret rotatie policy (max 180 dagen) | Gemiddeld |
| I08-post-3 | Brute-force bescherming op /auth/mfa/verify (5 pogingen/minuut) | Hoog |

---

## 8. Verificatie

### 8.1 Gates

| Gate | Status |
|------|--------|
| `php -l app/Services/TotpService.php` | ✅ PASS |
| `php -l app/Services/AuthMfaService.php` | ✅ PASS |
| `php artisan test --filter=TotpServiceTest` | ✅ 9/9 PASS |
| `php artisan test --filter=AuthModuleContractTest` | ✅ 6/6 PASS (34 assertions) |
| `php artisan test` | ✅ 17/17 PASS (49 assertions) |
| RFC 6238 Appendix B known-vector | ✅ T=59 → 287082 |

### 8.2 Verdikt

**ITERATIE 08: GO — RFC 6238 TOTP implementatie succesvol geverifieerd.**

Overgangsalgoritme volledig verwijderd. Alle 17 tests slagen. Known-vector bevestigt RFC-conformiteit.

---

**Verplichte volgende iteratie:** Implementeer MUST-WORK-ENTRY module: migraties, WorkEntriesService, controller koppeling, ATW-validatie, 7+ feature tests.
