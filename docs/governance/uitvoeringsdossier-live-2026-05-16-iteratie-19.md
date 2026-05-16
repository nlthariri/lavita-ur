# Uitvoeringsdossier - Iteratie 19
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: Admin-aanpasbare e-mailtemplates + runtime rendering

---

## 1. Analyse

### 1.1 Probleem
Audit-restpunt: admin-aanpasbare e-mailtemplates ontbraken. E-mailinhoud stond hardcoded in services zonder beheerlaag.

### 1.2 Evidence
- Geen tabel/model voor templates per organisatie.
- Geen API om templates te beheren.
- Dispatch pad kende geen template-resolutie op type.

### 1.3 Doel
- Beheerbare templates per organisatie/type.
- Toepassing van actieve templates tijdens dispatch.
- Rolafscherming op templatebeheer.

---

## 2. Overleg (extern expertpanel)

Panelrollen: engineering, security, QA, compliance, risk, operations, data.

Besluiten:
1. Template-beheer alleen voor owner/manager.
2. Templates tenant-scoped op organization_id.
3. Runtime rendering via placeholders {{key}} met expliciete vars.
4. Ongewijzigde fallback naar aangeleverde subject/body bij afwezige template.

---

## 3. Consensus

Voorstel 2026-05-16-I19:
- Nieuwe tabel email_templates met unieke key (organization_id, type).
- Nieuwe service EmailTemplateService voor upsert/find/apply.
- Nieuwe endpoints GET/PUT /api/internal/email/templates/{type}.
- Integratie in EmailOutboxService::dispatch() zodat templates effectief gebruikt worden.
- Contracttests voor beheer, rendering en rolblokkade boekhouder.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/database/migrations/2026_05_16_120100_create_email_templates_table.php (nieuw)
- laravel-rebuild/app/Models/EmailTemplate.php (nieuw)
- laravel-rebuild/app/Services/EmailTemplateService.php (nieuw)
- laravel-rebuild/app/Services/EmailOutboxService.php
- laravel-rebuild/app/Http/Controllers/Transitie/EmailFlowsModule/EmailFlowsModuleController.php
- laravel-rebuild/routes/api.php
- laravel-rebuild/tests/Feature/EmailFlowsModuleContractTest.php

Kernwijzigingen:
1. Template-opslag per organisatie/type met active-flag en actor-evidence.
2. Beheer-endpoints voor ophalen/upserten templates.
3. Dispatch verwerkt actieve template op basis van type + template_vars.
4. Validatie uitgebreid met template_vars in dispatch endpoint.
5. Boekhouder blijft expliciet geblokkeerd op templatebeheer.

---

## 5. Heroverleg

Post-implementatie review:
- Security: akkoord, tenant-afbakening en role-gating aanwezig.
- QA: akkoord, renderingpad en fallbackgedrag contract-getest.
- Compliance: akkoord, admin-editable template-eis aantoonbaar ingevuld.
- Operations: akkoord, geen scheduler-impact, migratie klein en geïsoleerd.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter='EmailFlowsModuleContractTest|AuthModuleContractTest'
- php artisan test --filter='EmailFlowsModuleContractTest' (na fix template_vars validatie)
- php artisan test

Resultaat:
- Focus set: PASS
- Re-run email module: PASS (21 tests)
- Volledige suite: PASS (120 tests, 417 assertions)

Iteratie 19 status: GO
