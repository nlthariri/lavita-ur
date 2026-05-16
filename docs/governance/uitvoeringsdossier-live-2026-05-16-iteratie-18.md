# Uitvoeringsdossier - Iteratie 18
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: Account-aanmaak triggerflow + boekhouder rolafbakening

---

## 1. Analyse

### 1.1 Probleem
Resterende MUST-gaten na iteratie 17:
1. Geen interne account-aanmaakflow met geautomatiseerde onboarding e-mail.
2. Boekhouder-rol was op meerdere muterende paden alleen impliciet afgeschermd.

### 1.2 Evidence
- Geen route/handler aanwezig voor intern account-aanmaken.
- Geen outbox-type account_created vanuit create-flow.
- Muterende endpoints gaven niet overal expliciet 403 voor boekhouder.

### 1.3 Doel
- Account provisioning endpoint met outbox-trigger "Nieuw account aangemaakt".
- Boekhouder expliciet read-only: rapport/export toegestaan, muterende en e-maildispatch acties geblokkeerd.

---

## 2. Overleg (extern expertpanel)

Panelrollen: engineering, security, QA, compliance, risk, operations, data-governance.

Besluiten:
1. Account-aanmaak alleen voor owner/manager.
2. Manager mag alleen employee-account binnen eigen team creëren.
3. Onboarding via reset-link (24h), geen plaintext wachtwoord-distributie.
4. Boekhouder krijgt expliciete 403 op muterende endpoints voor eenduidige auditeerbaarheid.

---

## 3. Consensus

Voorstel 2026-05-16-I18:
- Nieuwe service AccountProvisioningService.
- Nieuw endpoint POST /api/auth/accounts.
- Outbox dispatch type account_created met idempotency key account-created-{user_id}.
- Expliciete 403-gates voor boekhouder op muterende werkregel- en bezwaaracties.
- Contracttests voor onboardingflow en boekhouder read-only policy.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/app/Services/AccountProvisioningService.php (nieuw)
- laravel-rebuild/app/Http/Controllers/Transitie/AuthModule/AuthModuleController.php
- laravel-rebuild/routes/api.php
- laravel-rebuild/app/Http/Controllers/Transitie/WorkEntriesModule/WorkEntriesModuleController.php
- laravel-rebuild/app/Http/Controllers/Transitie/ObjectionsModule/ObjectionsModuleController.php
- laravel-rebuild/tests/Feature/AuthModuleContractTest.php
- laravel-rebuild/tests/Feature/EmailFlowsModuleContractTest.php
- laravel-rebuild/tests/Feature/ReportsModuleContractTest.php
- laravel-rebuild/tests/Feature/WorkEntriesModuleContractTest.php
- laravel-rebuild/tests/Feature/ObjectionsModuleContractTest.php

Kernwijzigingen:
1. Nieuwe account provisioning flow met organisatie-borging en role-policy.
2. Onboarding e-mail in outbox met resetlink via PasswordResetService::createToken().
3. API-contract voor account-aanmaak met response-scope MUST-AUTH-ACCOUNT-CREATE.
4. Boekhouder expliciet geblokkeerd op muterende endpoints met 403 + vaste melding.
5. Rapport-export voor boekhouder expliciet afgedekt met positieve contracttests.

---

## 5. Heroverleg

Post-implementatie review:
- Security: akkoord, provisioning geen tenant spoofing en manager-scope afgedwongen.
- QA: akkoord, nieuwe en bestaande contracttests groen.
- Compliance: akkoord, expliciete read-only handhaving boekhouder aantoonbaar.
- Operations: akkoord, geen scheduler-impact en geen breaking route-wijzigingen.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter='AuthModuleContractTest|EmailFlowsModuleContractTest|WorkEntriesModuleContractTest|ObjectionsModuleContractTest|ReportsModuleContractTest'
- php artisan test

Resultaat:
- Gerichte regressieset: PASS (62 tests, 228 assertions)
- Volledige suite: PASS (117 tests, 402 assertions)

Iteratie 18 status: GO
