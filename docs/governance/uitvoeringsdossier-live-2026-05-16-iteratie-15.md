# Uitvoeringsdossier - Iteratie 15
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: Bezwaar-goedkeuring met verplichte werkregelcorrectie + audit-evidence

---

## 1. Analyse

### 1.1 Probleem
Bij APPROVED bezwaar werd alleen de bezwaarstatus aangepast; de onderliggende werkregel bleef ongewijzigd. Dit botst met de eis dat goedkeuren leidt tot gecorrigeerde uren.

### 1.2 Evidence
- Reviewflow werkte zonder correctiepayload.
- Geen update op WorkEntry bij APPROVED.
- Geen structurele before/after evidence in bezwaarrecord.

### 1.3 Doel
- APPROVED alleen toestaan met expliciete correctiewaarden.
- Werkregel atomair corrigeren in dezelfde transactie.
- Onweerlegbare audit-evidence vastleggen (before/after).

---

## 2. Overleg (extern expertpanel)

Disciplines 1-voor-1 besproken: backend, software architectuur, QA, contract test, risk, compliance, operations, quant/data vertegenwoordiging.

Kernbesluiten:
1. Correctievelden moeten onderdeel van reviewcontract worden.
2. APPROVED zonder correctiepayload is functioneel ongeldig.
3. Correctie + bezwaarstatus + audit moeten in 1 transactionele flow.
4. Testbewijs moet zowel validatiepad als mutatiepad dekken.

---

## 3. Consensus

Voorstel 2026-05-16-I15:
- Nieuwe correctievelden op objections tabel.
- Verplichte corrected_start_time, corrected_end_time, corrected_pause_minutes bij APPROVED.
- WorkEntry update inclusief netto herberekening.
- AuditEvent record met before/after snapshot.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/database/migrations/2026_05_16_110100_add_correction_fields_to_objections_table.php
- laravel-rebuild/app/Models/Objection.php
- laravel-rebuild/app/Http/Controllers/Transitie/ObjectionsModule/ObjectionsModuleController.php
- laravel-rebuild/app/Services/ObjectionsService.php
- laravel-rebuild/tests/Feature/ObjectionsModuleContractTest.php

Kernwijzigingen:
1. Nieuwe opslagvelden voor correcties en snapshots op objections.
2. Review endpoint accepteert corrected_* velden.
3. Service eist correctiepayload bij APPROVED.
4. Service corrigeert WorkEntry en herberekent net_minutes.
5. AuditEvent wordt geschreven met action objection_approved_work_entry_corrected.
6. Contracttests:
- goedkeuren met correctie werkt en muteert work_entry
- goedkeuren zonder correctie geeft 422
- audit evidence aanwezig

---

## 5. Heroverleg

Post-implementatie review:
- Backend: akkoord, transactionele consistentie bevestigd.
- QA: akkoord, negatieve en positieve paden afgedekt.
- Compliance: akkoord, besluit en uitvoering zijn aantoonbaar gekoppeld.
- Risk: akkoord, silent-approval zonder datafix geëlimineerd.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter=ObjectionsModuleContractTest
- php artisan test --filter='ObjectionsModuleContractTest|WorkEntriesModuleContractTest|AtwModuleContractTest|AuthModuleContractTest'
- php artisan test

Resultaat:
- ObjectionsModuleContractTest: 12/12 PASS
- Cross-module run: PASS
- Volledige suite: PASS

Iteratie 15 status: GO
