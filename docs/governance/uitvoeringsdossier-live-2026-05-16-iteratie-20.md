# Uitvoeringsdossier - Iteratie 20
Project: LaVita Uren Registratie - Migratietraject Node.js/Next.js -> Laravel
Datum: 16 mei 2026
Fase: Live execution hardening
Module: ATW 16-wekenvenster correctie (formule-hardening)

---

## 1. Analyse

### 1.1 Probleem
Open audit-risico: 16-weken ATW-gemiddelde gebruikte een tijdstempel-lookback op dag/tijdniveau, waardoor randdiensten aan het begin/einde van het venster deels fout konden vallen.

### 1.2 Evidence
- Bestaande logica: `subWeeks(16)` met eindgrens op voorgestelde eindtijd.
- Mogelijke impact: off-by-window bij shifts op vensterranden.

### 1.3 Doel
- Bereken gemiddelde over 16 volledige ISO-weken inclusief de huidige week.
- Queryvenster in AtwService laten aansluiten op dezelfde definitie.
- Unit-evidence toevoegen voor vensterrandgedrag.

---

## 2. Overleg (extern expertpanel)

Panelrollen: legal/ATW, engineering, QA, compliance, risk.

Besluiten:
1. 16-wekenvenster moet week-gebaseerd (maandag-zondag) zijn, geen willekeurige tijdstempel-slice.
2. Current week telt mee in het venster.
3. Querylaag en engine moeten identieke vensterdefinitie hanteren.

---

## 3. Consensus

Voorstel 2026-05-16-I20:
- AtwEngine: venster = startOfWeek(Monday)-15 weken t/m endOfWeek(Sunday).
- AtwService: ophalen existing shifts op dezelfde grenzen.
- Unit test: boundary-proof voor SIXTEEN_WEEK_AVERAGE.

Stemming:
- Voor: 31
- Tegen: 0
- Onthouding: 0

Consensusoordeel: GO

---

## 4. Implementatie

Gewijzigde bestanden:
- laravel-rebuild/app/Services/AtwEngine.php
- laravel-rebuild/app/Services/AtwService.php
- laravel-rebuild/tests/Unit/AtwEngineTest.php

Kernwijzigingen:
1. 16-wekenberekening nu op volledige weekgrenzen.
2. Queryfilter in AtwService op `start_at` venstergrenzen i.p.v. mixed start/end slice.
3. Nieuwe unit test verifieert dat vensterrand correct telt en buiten-vensterdata niet onterecht drempel triggert.

---

## 5. Heroverleg

Post-implementatie review:
- Legal/ATW: akkoord met weekvensterbenadering.
- QA: akkoord, unit + feature regressies groen.
- Compliance: akkoord, resterend auditpunt afgedekt met test-evidence.

Besluit: GO naar verificatie.

---

## 6. Verificatie

Uitgevoerde commandobewijzen:
- php artisan test --filter='AtwEngineTest|AtwModuleContractTest|WorkEntriesModuleContractTest'
- php artisan test

Resultaat:
- Focus set: PASS (26 tests, 89 assertions)
- Volledige suite: PASS (121 tests, 418 assertions)

Iteratie 20 status: GO
