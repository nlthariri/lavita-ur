# Uitvoeringsdossier - Iteratie 14
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: ATW alerting bij ureninvoer

---

## 1. Analyse

### 1.1 Probleemstelling
Bij opslaan van werkregels werden ATW signalen wel berekend via validate endpoint, maar niet automatisch gecommuniceerd als operationele alerts richting verantwoordelijken.

### 1.2 Live bewijs
- WorkEntries flow had wel work_entry_finalized mail.
- Geen automatische dispatch van ATW warning/critical signalen vanuit create flow.

### 1.3 Doel
Bij creatie van een werkregel direct enterprise-alerting uitvoeren:
- owners en relevante managers krijgen signalen
- employee krijgt critical signalen
- idempotente outbox-events per work entry en signaaltype

---

## 2. Overlegverslag extern expertpanel

Multidisciplinair overleg uitgevoerd met expliciete stemronde na codeanalyse.

Besproken punten:
1. Alerting moet direct gekoppeld aan create-flow, niet afhankelijk van losse handmatige endpointcalls.
2. Recipients moeten tenant-safe en team-aware zijn.
3. Idempotency keys zijn verplicht om dubbele signalen te voorkomen.
4. Contracttest moet aantonen dat weekly warning minimaal owner en manager bereikt.

---

## 3. Consensus

Voorstel 2026-05-16-I14:
- Integreer ATW validatie in WorkEntriesService create flow.
- Voeg AtwService method toe die signalen omzet naar outbox dispatch.
- Borg recipient-scope en idempotency.
- Voeg contracttest toe voor weekly warning alert dispatch.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/app/Services/WorkEntriesService.php
- laravel-rebuild/app/Services/AtwService.php
- laravel-rebuild/tests/Feature/WorkEntriesModuleContractTest.php

Kernwijzigingen:
1. WorkEntriesService vraagt vooraf ATW validatie op met actor-context.
2. Na succesvolle werkregelcreatie worden signalen via AtwService naar outbox gedispatched.
3. Recipientbeleid:
- owners + teammanagers voor signalen
- employee toegevoegd bij critical signalen
4. Idempotency key patroon: atw-signal-workEntryId-signalType-recipientId.
5. Nieuwe contracttest valideert weekly warning notificatie naar owner en manager.

---

## 5. Heroverleg

Post-implementatie heroverleg:
- Architectuur: akkoord, service split helder en uitbreidbaar.
- QA: akkoord, regressie en nieuw gedrag getest.
- Compliance: akkoord, operationele signalering verbeterd.
- Operations: akkoord, outboxpatroon consistent met bestaande mailflows.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter=WorkEntriesModuleContractTest
- php artisan test --filter='AtwModuleContractTest|WorkEntriesModuleContractTest|AuthModuleContractTest|ObjectionsModuleContractTest|ReportsModuleContractTest'
- php artisan test

Resultaat:
- WorkEntriesModuleContractTest: 10/10 PASS
- Cross-module contractrun: PASS
- Volledige suite: 104 tests PASS, 362 assertions PASS

Iteratie 14 status: GO
