# Uitvoeringsdossier - Iteratie 16
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: Persistente ATW-violation evidence voor dashboard en compliance

---

## 1. Analyse

### 1.1 Probleem
ATW-signalen werden wel berekend en gemaild, maar niet structureel opgeslagen als violation records voor dashboard en auditdoeleinden.

### 1.2 Evidence
- Signal dispatch aanwezig, maar geen create op atw_violations in dispatchflow.
- Dashboard/monitoring kan daardoor leeg of incompleet zijn.

### 1.3 Doel
Elke ATW-signaal in create-flow moet ook als violation-record worden vastgelegd, tenant-scoped en gekoppeld aan de werkregel.

---

## 2. Overleg (extern expertpanel)

Multidisciplinaire bespreking met expliciete beoordeling per rolcluster (engineering, qa, risk, compliance, architecture, operations).

Kernbesluiten:
1. Persistentie en notificatie moeten parallel lopen, niet los.
2. Violation-record moet minimaal bevatten: type, severity, current/threshold, organization, user, work_entry.
3. Contracttest moet aantonen dat WEEKLY_WARNING zowel mail als DB-record oplevert.

---

## 3. Consensus

Voorstel 2026-05-16-I16:
- Voeg AtwViolation::create toe in dispatchSignalsForCreatedEntry.
- Behoud bestaande recipientlogica en idempotente mailflow.
- Voeg assertie toe in WorkEntriesModuleContractTest.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/app/Services/AtwService.php
- laravel-rebuild/tests/Feature/WorkEntriesModuleContractTest.php

Kernwijzigingen:
1. Per signaal wordt nu atw_violations record aangemaakt.
2. Records bevatten organization_id, user_id, work_entry_id, violation_type, severity, current_minutes, threshold_minutes, details.
3. Contracttest weekly warning controleert nu ook atw_violations persistentie.

---

## 5. Heroverleg

Post-implementatie review:
- Architectuur: akkoord, evidence-layer sluit nu aan op signaling-layer.
- QA: akkoord, testdekkingsverbetering bevestigd.
- Compliance: akkoord, ATW signalen zijn nu aantoonbaar bewaarpad-geschikt.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter=WorkEntriesModuleContractTest
- php artisan test --filter='AtwModuleContractTest|WorkEntriesModuleContractTest|ObjectionsModuleContractTest'
- php artisan test

Resultaat:
- WorkEntriesModuleContractTest: 10/10 PASS
- Cross-module run: PASS
- Volledige suite: 105 tests PASS, 367 assertions PASS

Iteratie 16 status: GO
