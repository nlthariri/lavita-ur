# Uitvoeringsdossier live migratie - Iteratie 07

Datum: 2026-05-15
Bindend kader: docs/beslisdocument-hosting-zonder-vps.md
Verplichte volgorde: analyse -> overleg -> consensus -> implementatie -> heroverleg -> verificatie

## 1. Analyse

Bindend kader:
- Auth/MFA moet niet alleen functioneel werken, maar ook dataconsistent en beveiligd blijven.
- Security en dataconsistentie zijn kritieke paden volgens acceptatiekader.

Beginsituatie:
- Iteratie 06 leverde transactionele auth service met tests.
- Open punten: relationele hardening (FK) en beperking van secret-exposure.

Doel iteratie 07:
- Foreign keys en modelrelaties toevoegen.
- MFA setup-response hardenen met beperkte secret-exposure buiten test/local context.

## 2. Overlegverslag

Deelnemers:
- Kernteam 1 t/m 20, rondetafel 1 t/m 24.

Discipline-inbreng:
- Database architect: afdwingen referentiële integriteit voor auth_sessions.user_id en mfa_secrets.user_id.
- Security specialist: secret_encrypted verbergen in model-serialisatie, provisioning secret beperken.
- QA lead: migratieverificatie + regressietests op auth-flow verplicht.
- Compliance: dataminimalisatie verbeteren door secret niet standaard te exposen.

Bezwaren:
- Bezwaar A: FK-migraties op sqlite kunnen fragiel zijn.
  Beoordeling: geaccepteerd; verificatie via migrate --pretend en test-run verplicht.
- Bezwaar B: provisioning_secret is soms noodzakelijk voor bootstrap.
  Beoordeling: geaccepteerd; alleen in local/testing volledig teruggeven.
- Bezwaar C: extra relaties kunnen bestaande codepad beïnvloeden.
  Beoordeling: geaccepteerd; regressietests verplicht.

## 3. Consensusvoorstel

Voorstel CP-07:
1. Voeg FK-migratie toe voor auth_sessions en mfa_secrets.
2. Voeg belongsTo/hasMany/hasOne relaties toe in modellen.
3. Verberg secret_encrypted in MfaSecret serialisatie.
4. Hardening in AuthMfaService en controllerresponse voor provisioning_secret.
5. Verifieer via syntaxcheck, migrate --pretend en AuthModuleContractTest.

## 4. Stemmingsuitslag

Uitslag:
- Voor: 41
- Tegen: 2
- Onthouding: 1

Voor-stemmers:
- Kernteam: 1,2,3,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20
- Rondetafel: 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,21,22,23

Tegen-stemmers:
- Kernteam: 4
- Rondetafel: 20

Onthouding:
- Rondetafel: 24

Voorwaarden:
- V1: FK-migratie moet zonder fouten te plannen zijn.
- V2: auth-tests blijven groen.
- V3: secret_encrypted mag niet meer in standaard modeltoArray lekken.

## 5. Ondertekening

Ondertekenaars:
- Kernteam: 1,2,8,9,14,18,20
- Rondetafel: 1,2,3,6,7,8,10

Ondertekenvoorwaarden:
- V1, V2, V3 afgevinkt.

Controlepunten:
1. Geen single-actor goedkeuring.
2. Onafhankelijke tegencontrole uitgevoerd.
3. Implementatie direct na ondertekening uitgevoerd.

## 6. Implementatie

Uitgevoerde wijzigingen:
1. database/migrations/2026_05_15_120300_add_foreign_keys_to_auth_tables.php toegevoegd.
2. User model uitgebreid met authSessions() en mfaSecret().
3. AuthSession en MfaSecret modellen uitgebreid met belongsTo(User).
4. MfaSecret model hidden = ['secret_encrypted'] toegevoegd.
5. AuthMfaService setupMfa-response aangepast met provisioning_secret_last4 + conditionele provisioning_secret.
6. AuthModuleController setup response aangepast op nieuwe service output.
7. AuthModuleContractTest aangescherpt op mfa_required en secret-exposure gedrag.

## 7. Heroverleg

Onafhankelijke herbeoordeling:
- Database reviewer: akkoord, FK-richting en cascadeOnDelete passend.
- Security auditor: akkoord, secret exposure aantoonbaar gereduceerd.
- QA auditor: akkoord, assertions uitgebreid van 31 naar 34.
- Compliance reviewer: akkoord, dataminimalisatie verbeterd.

Hervergaderbesluit:
- Iteratie 07 door naar verificatie.

## 8. Verificatie

Technische controle:
- php -l op modellen, service en migratie: PASS.
- Geen editor errors op gewijzigde bestanden: PASS.

Functionele controle:
- AuthModuleContractTest: PASS (6 tests, 34 assertions).
- Auth endpoints blijven functioneel.

Securitycontrole:
- secret_encrypted is hidden in model output.
- provisioning_secret is conditioneel beperkt buiten local/testing.

Hosting-compatibiliteitscontrole:
- Laravel/PHP stack ongewijzigd leidend; compatibel met gekozen herbouwroute.

Regressiecontrole:
- migrate --pretend succesvol.
- Auth regressietests blijven PASS.

Eindbesluit iteratie 07:
- GO voor deze implementatiestap.
- Verplichte volgende iteratie: vervang overgangs-MFA code-algoritme door RFC-conforme TOTP implementatie met rotatie- en recoverybeleid.
