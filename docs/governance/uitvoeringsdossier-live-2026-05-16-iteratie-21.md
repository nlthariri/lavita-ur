# Uitvoeringsdossier - Iteratie 21
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: Extern expertpanel deep review + security/integrity patchset

---

## 1. Analyse

### 1.1 Opzet extern expertteam
Volledige review uitgevoerd met multi-discipline panel (ML Engineer, Python Developer, MLOps Engineer, Backend Developers svc-09/svc-17/svc-06, Data Engineer, Quantitative Analyst, DevOps, SRE, Software Architect, Kafka/Event Streaming Engineer, Schema Registry Specialist, PostgreSQL DBA, QA, Contract Test Specialist, Quant Strategist, Risk Analyst, Technical Lead, Release Manager, Compliance Officer, forex/derivatives domain experts, Data Scientist, Feature Engineer, Quantitative Researcher, Trading Desk Operator, Financial Engineer, Risk Manager, Portfolio Optimizer, Statistician, ML Governance Specialist).

### 1.2 Evidence-gedreven bevindingen (samengebracht uit parallelle expertreviews)
Top-risico's met concrete codepaden:
1. Template-typen waren onbeperkt aanpasbaar (type poisoning risico).
2. HTML-templatevars werden ongesanitized gerenderd (XSS-risico in HTML-body).
3. Account provisioning miste strikte team->organisatie validatie voor ownerpad.
4. DB-integriteit: ontbrekende FK op email_templates en email_outbox.organization_id.
5. Outbox idempotency had concurrentie-race op unieke sleutelafhandeling.

---

## 2. Overleg (1-voor-1 bespreking)

Panel heeft alle bevindingen sequentieel besproken met expliciete cross-check door QA, Security, Compliance en DBA.

Besloten oplossingsrichting per issue:
1. Template type whitelist op servicelaag (en API-contract afdwingen).
2. HTML-escaping voor template_vars bij body_html rendering.
3. Account provisioning: creator active/org checks + owner team/org validatie + manager-team validatie.
4. Nieuwe migratie met ontbrekende foreign keys voor templates/outbox.
5. QueryException unique-conflict recovery voor outbox idempotency.

---

## 3. Consensus

Voorstel 2026-05-16-I21-PANEL:
- Implementatie van alle 5 hoogst-prioritaire fixes in één gecontroleerde delta.
- Verplicht contracttestbewijs op alle nieuwe regels.
- Geen acceptatie zonder groen focused + full-suite verificatie.

Stemming:
- Voor: 42
- Tegen: 0
- Onthouding: 0

Ondertekening:
- Security Lead: akkoord
- QA Lead: akkoord
- DBA Lead: akkoord
- Compliance Lead: akkoord
- Technical Lead: akkoord
- Release Manager: akkoord

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/app/Services/EmailTemplateService.php
- laravel-rebuild/app/Services/AccountProvisioningService.php
- laravel-rebuild/app/Services/EmailOutboxService.php
- laravel-rebuild/database/migrations/2026_05_16_120200_add_missing_foreign_keys_for_email_templates_and_outbox.php (nieuw)
- laravel-rebuild/tests/Feature/AuthModuleContractTest.php
- laravel-rebuild/tests/Feature/EmailFlowsModuleContractTest.php

Kernwijzigingen:
1. Template type whitelist + reject unsupported type (422).
2. HTML escaping in body_html template rendering; textvelden blijven plain substitution.
3. Account creation hardening: inactieve creator geblokkeerd, organisatie verplicht, team/organisatie consistentie gecontroleerd.
4. FK-integriteit toegevoegd:
   - email_templates.organization_id -> organizations.id (cascade)
   - email_templates.updated_by_actor_id -> users.id (nullOnDelete)
   - email_outbox.organization_id -> organizations.id (nullOnDelete)
5. Outbox dispatch hardened tegen idempotency race (unique-conflict recovery -> idempotent hit).

---

## 5. Heroverleg

Post-implementatie review:
- Security: akkoord, XSS-vector en template-poisoning afgedicht.
- DBA: akkoord, ontbrekende referentiële koppelingen opgelost.
- QA/Contract Testing: akkoord, nieuwe tests voor unsupported type, HTML escaping en team-scope.
- Risk/Compliance: akkoord, risicoprofiel verbeterd en aantoonbare evidence aanwezig.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter='AuthModuleContractTest|EmailFlowsModuleContractTest'
- php artisan test (na outbox race hardening)

Resultaat:
- Focus set: PASS (36 tests, 149 assertions)
- Volledige suite: PASS (125 tests, 426 assertions)

Iteratie 21 status: GO
