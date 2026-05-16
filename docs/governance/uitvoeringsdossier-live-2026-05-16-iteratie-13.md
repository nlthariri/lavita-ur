# Uitvoeringsdossier - Iteratie 13
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: ATW endpoint autorisatie en data-isolatie

---

## 1. Analyse

### 1.1 Probleemstelling
ATW endpoints accepteerden target user input zonder strikte scopecontrole op basis van de geauthenticeerde actor.

### 1.2 Live bewijs
- Validate endpoint stuurde alleen employee_id door, zonder actor-scope check.
- Signals endpoint accepteerde user_id zonder actor-scope check.
- Risico: employee kon ATW data van andere employee opvragen; cross-org probing via id mogelijk.

### 1.3 Besluitcriteria
- Zelfde securityhardheid als work entries en objections modules.
- Geen rol mag buiten eigen toegestane scope lezen.
- Consistente foutmeldingen en contracttests verplicht.

---

## 2. Overlegverslag extern expertpanel

Rollen 1-voor-1 gereviewd en geconsolideerd: backend, architectuur, QA, contract testing, compliance, risk, devops, sre, data/quant disciplines.

Besproken punten:
1. Actor-context moet verplicht in AtwService methodes.
2. Scope-regels:
- owner: eigen organisatie
- manager: eigen team binnen organisatie
- employee: alleen zichzelf
3. Beide ATW endpoints moeten dezelfde scope engine gebruiken.
4. Contracttests moeten negatieve paden aantonen, niet alleen happy path.

---

## 3. Consensus

Voorstel 2026-05-16-I13:
- AtwService uitgebreid met centrale scope guard.
- Controllerwiring aangepast met requester context.
- Nieuwe negatieve tests voor cross-user en cross-org toegang.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/app/Services/AtwService.php
- laravel-rebuild/app/Http/Controllers/Transitie/AtwModule/AtwModuleController.php
- laravel-rebuild/tests/Feature/AtwModuleContractTest.php

Kernwijzigingen:
1. validateProposedShift accepteert nu requesterId en valideert scope.
2. getSignalsForUser accepteert nu targetUserId + requesterId en valideert scope.
3. Centrale scopeguard in AtwService voor org/team/self regels.
4. Controller geeft nu request user id door aan service.
5. Nieuwe contracttests:
- employee mag geen andere employee valideren
- owner mag geen andere organisatie valideren
- employee mag geen signalen van andere user ophalen

---

## 5. Heroverleg

Na implementatie herbeoordeling uitgevoerd:
- Security: akkoord, privilege-escalatie pad gesloten.
- Compliance: akkoord, tenant-isolatie verbeterd.
- QA: akkoord, negatieve contracttests aanwezig.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter=AtwModuleContractTest
- php artisan test --filter='AtwModuleContractTest|WorkEntriesModuleContractTest|AuthModuleContractTest|ObjectionsModuleContractTest|ReportsModuleContractTest'
- php artisan test

Resultaat:
- AtwModuleContractTest: 8/8 PASS
- Cross-module contractrun: PASS
- Volledige suite: PASS

Iteratie 13 status: GO
