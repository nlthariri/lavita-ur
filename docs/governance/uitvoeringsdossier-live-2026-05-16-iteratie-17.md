# Uitvoeringsdossier - Iteratie 17
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: Reminder openstaande invoer (manager) + operationele scheduling

---

## 1. Analyse

### 1.1 Probleem
De verplichte reminderflow voor openstaande invoer naar managers was niet geautomatiseerd.

### 1.2 Evidence
- Geen command voor team-gebonden reminder op ontbrekende invoer.
- Geen scheduler-entry voor reminder-run.
- Geen bewijsrun in system_job_runs voor deze flow.

### 1.3 Doel
- Dagelijkse geautomatiseerde reminder naar manager bij ontbrekende teaminvoer.
- Job evidence via SystemJobRun.
- Dry-run modus voor veilige operations- en compliance-validatie.

---

## 2. Overleg (extern expertpanel)

Panelrollen (engineering/qa/compliance/risk/operations/data) hebben de implementatievolgorde 1-voor-1 beoordeeld.

Besluiten:
1. Command moet locken tegen overlap.
2. Reminder moet team-aware zijn en alleen actieve medewerkers meenemen.
3. Evidence verplicht via system_job_runs met details.
4. Dry-run moet bewijs schrijven zonder dispatch side-effects.

---

## 3. Consensus

Voorstel 2026-05-16-I17:
- Nieuwe service PendingInputReminderService.
- Nieuwe command reminder:pending-input met lock en options.
- Scheduler toegevoegd in routes/console.php.
- Featuretests voor dispatch en dry-run.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/app/Services/PendingInputReminderService.php
- laravel-rebuild/app/Console/Commands/RunPendingInputReminderCommand.php
- laravel-rebuild/routes/console.php
- laravel-rebuild/tests/Feature/PendingInputReminderCommandTest.php

Kernwijzigingen:
1. Reminder-service detecteert ontbrekende invoer per manager/team op target_date.
2. Dispatch via EmailOutboxService met type reminder_open_entries.
3. Locking via Cache::lock op command-niveau.
4. SystemJobRun wordt gestart/afgesloten met details en rows_affected.
5. Dry-run ondersteunt evidence zonder outbox dispatch.
6. Scheduler draait dagelijks om 02:20.

---

## 5. Heroverleg

Post-implementatie review:
- Operations: akkoord, lock en schedule aanwezig.
- QA: akkoord, dispatch + dry-run tests groen.
- Compliance: akkoord, MUST reminderflow aantoonbaar geautomatiseerd.
- Risk: akkoord, overlap/race risico beperkt.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter=PendingInputReminderCommandTest
- php artisan test --filter='PendingInputReminderCommandTest|WorkEntriesModuleContractTest|ObjectionsModuleContractTest'
- php artisan test

Resultaat:
- PendingInputReminderCommandTest: 2/2 PASS
- Cross-module run: PASS
- Volledige suite: 107 tests PASS, 376 assertions PASS

Iteratie 17 status: GO
