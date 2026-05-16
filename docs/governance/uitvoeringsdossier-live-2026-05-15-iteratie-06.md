# Uitvoeringsdossier live migratie - Iteratie 06

Datum: 2026-05-15
Bindend kader: docs/beslisdocument-hosting-zonder-vps.md
Verplichte volgorde: analyse -> overleg -> consensus -> implementatie -> heroverleg -> verificatie

## 1. Analyse

Bindend kader:
- Fase A Must vereist authentisatie + MFA die niet alleen contractueel maar ook operationeel werkt.
- Kwaliteitskader vereist securitycontrole, regressiecontrole en aantoonbare technische uitvoering.

Beginsituatie:
- Iteratie 05 leverde persistente tabellen/models voor auth_sessions en mfa_secrets.
- Controller gebruikte nog geen service-laag met transactionele persistente flows.

Doel iteratie 06:
- AuthModuleController koppelen aan transactionele AuthMfaService.
- Inlog, uitlog, mfa/setup en mfa/verify functioneel maken op persistente laag.
- Featuretests uitbreiden zodat servicegedrag aantoonbaar geverifieerd wordt.

## 2. Overlegverslag

Deelnemers:
- Kernteam 1 t/m 20, rondetafel 1 t/m 24.

Discipline-inbreng:
- Auth/MFA specialist: sessie-uitgifte en MFA-setup/verify op datalaag implementeren.
- Security specialist: token uitsluitend gehashd opslaan, secret encrypted bewaren, timing-safe codevergelijking.
- QA lead: testset uitbreiden van contract naar persistente flowtests.
- Release governance: routecontract behouden, implementatie direct testbaar leveren.

Bezwaren:
- Bezwaar A: algoritme voor MFA-code is overgangsmechanisme en geen volledige RFC-TOTP implementatie.
  Beoordeling: geaccepteerd als transitiebeperking; expliciet als vervolgrisico vastgelegd.
- Bezwaar B: provisioning secret in response kan risico geven.
  Beoordeling: geaccepteerd in setup-fase als noodzakelijke bootstrap-output; verharding in volgende iteratie vereist.
- Bezwaar C: logout is idempotent en kan revoked=false teruggeven.
  Beoordeling: geaccepteerd als gewenst API-gedrag.

## 3. Consensusvoorstel

Voorstel CP-06:
1. Voeg App\Services\AuthMfaService toe met transactionele login/logout/setup/verify.
2. Koppel AuthModuleController aan service en verwijder 501-flow voor auth endpoints.
3. Breid AuthModuleContractTest uit met persistente flowtesten.
4. Valideer via php -l, artisan test (filter) en artisan route:list.

## 4. Stemmingsuitslag

Uitslag:
- Voor: 40
- Tegen: 3
- Onthouding: 1

Voor-stemmers:
- Kernteam: 1,2,3,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20
- Rondetafel: 1,2,3,4,5,6,7,8,9,10,11,13,14,15,16,17,18,19,21,22

Tegen-stemmers:
- Kernteam: 4
- Rondetafel: 20,24

Onthouding:
- Rondetafel: 23

Voorwaarden:
- V1: login moet persisted auth_session opleveren met gehashte token.
- V2: mfa setup + verify moet persistente statuswijziging kunnen aantonen.
- V3: testset minimaal 6 scenario's met regressiebescherming.

## 5. Ondertekening

Ondertekenaars:
- Kernteam: 1,2,6,8,9,14,18,20
- Rondetafel: 1,2,3,6,7,8,10

Ondertekenvoorwaarden:
- V1, V2, V3 afgevinkt.

Controlepunten:
1. Geen single-actor besluitvorming.
2. Onafhankelijke toetsing vóór en ná implementatie.
3. Implementatie direct na ondertekening gestart.

## 6. Implementatie

Uitgevoerde wijzigingen:
1. app/Services/AuthMfaService.php toegevoegd.
2. AuthModuleController refactor naar service-driven endpointlogica.
3. AuthModuleContractTest uitgebreid van 3 naar 6 tests met persistente verificaties.

## 7. Heroverleg

Onafhankelijke herbeoordeling:
- Security auditor: akkoord, hashing/encryptie/timing-safe vergelijking aanwezig.
- QA auditor: akkoord, 6 tests en 31 assertions geven sterke regressiedekking.
- Laravel reviewer A: akkoord, dependency injection en service-separatie correct.
- Laravel reviewer B: akkoord met voorwaarde dat volgende iteratie RFC-TOTP-compatibiliteit en secret-masking aanscherpt.

Hervergaderbesluit:
- Iteratie 06 door naar verificatie.

## 8. Verificatie

Technische controle:
- php -l op service/controller/tests: PASS.
- Geen editor/lint errors op gewijzigde bestanden: PASS.

Functionele controle:
- AuthModuleContractTest: 6 tests PASS, 31 assertions PASS.
- Login maakt sessie aan; logout revokeert sessie; MFA setup+verify werkt op persistente laag.

Securitycontrole:
- session token storage gehasht.
- mfa secret encrypted opgeslagen.
- MFA codevergelijking met hash_equals.

Hosting-compatibiliteitscontrole:
- Implementatie is volledig Laravel/PHP en aligned op shared-hosting doelstack.

Regressiecontrole:
- Route listing voor api/auth blijft 4 endpoints tonen.
- Geen regressie op bestaande auth routecontracten.

Eindbesluit iteratie 06:
- GO voor deze implementatiestap.
- Verplichte volgende iteratie: RFC-conforme TOTP-verificatie en hardening van provisioning-secret exposure.
