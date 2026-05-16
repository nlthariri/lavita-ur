# Uitvoeringsdossier live migratie - Iteratie 03

Datum: 2026-05-15
Bindend kader: docs/beslisdocument-hosting-zonder-vps.md
Verplichte volgorde: analyse -> overleg -> consensus -> implementatie -> heroverleg -> verificatie

## 1. Analyse

Bindend kader toegepast:
- Herbouw naar Laravel + MySQL is verplichte route.
- Shared-hosting compatibiliteit zonder Node.js-runtime blijft hard acceptatiecriterium.
- Na functionele mapping (iteratie 02) is volgende verplichte stap: concrete implementatie van een eerste Laravel-skelet.

Beginsituatie iteratie 03:
- Mapping GO: docs/governance/functionele-mapping-check.md.
- Nog geen fysieke Laravel codebase binnen repository voor Fase A endpoints.

Doel iteratie 03:
- Realiseren van eerste Laravel-skelet met route- en controllerstubs voor alle Fase A Must-routes.
- Inbouwen van geautomatiseerde skelet-check met GO/NO-GO.

## 2. Overlegverslag

Deelnemers:
- Kernteam 1 t/m 20 actief.
- Rondetafel 1 t/m 24 actief als onafhankelijke tegencontrole.

Discipline-input (samengevat):
- Migratie-architectuur: vereist dat skelet direct herleidbaar is naar mapping-ID's.
- Laravel-herbouw: vereist echte Laravel projectstructuur, geen pseudo-mappen.
- Security: vereist expliciet 501-stubgedrag tot domeinlogica is geïmplementeerd.
- QA: vereist gate die controllers + routes + bootstrapconfig valideert.
- Shared-hosting auditor: vereist dat artefacten compatibel blijven met PHP/Laravel stack.

Ingebrachte bezwaren:
- Bezwaar A: "Alleen scaffolding zonder route-registratie is onvoldoende".
  Beoordeling: geaccepteerd, API-routing in bootstrap verplicht gemaakt.
- Bezwaar B: "Automatische methodenaamgeneratie kan invalide PHP opleveren".
  Beoordeling: geaccepteerd, fix-verplichting opgenomen bij verificatiefalen.
- Bezwaar C: "Geen runtimecheck binnen Laravel-framework betekent onvoldoende bewijs".
  Beoordeling: geaccepteerd, php artisan route:list als verplichte verificatiestap.

## 3. Consensusvoorstel

Voorstel CP-03:
1. Maak laravel-rebuild project aan met Composer.
2. Genereer routes/controllers uit docs/governance/functionele-mapping-fase-a.json.
3. Voeg skelet-gate toe met GO/NO-GO rapportage.
4. Voer route-registratieverificatie in Laravel uit.

## 4. Stemmingsuitslag

Uitslag:
- Voor: 39
- Tegen: 4
- Onthouding: 1

Voor-stemmers:
- Kernteam: 1,2,3,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20
- Rondetafel: 1,2,3,4,5,6,7,8,9,10,11,13,14,15,16,17,18,19,21,22

Tegen-stemmers:
- Kernteam: 4
- Rondetafel: 12,20,24

Onthouding:
- Rondetafel: 23

Voorwaarden:
- V1: route- en controllergeneratie moet alle 15 Fase A routes dekken.
- V2: verificatie moet framework-level route listing tonen.
- V3: parsefouten zijn blocker en vereisen directe hersteliteratie binnen dezelfde sessie.

## 5. Ondertekening

Ondertekenaars:
- Kernteam: 1,2,14,18,19,20
- Rondetafel: 1,2,3,5,7,8

Ondertekenvoorwaarden:
- V1, V2, V3 integraal geaccepteerd.

Controlepunten afgevinkt:
1. Geen enkele wijziging door één actor goedgekeurd.
2. Onafhankelijke toetsing door rondetafel uitgevoerd vóór en ná implementatie.
3. Implementatie direct na ondertekening gestart.

## 6. Implementatie

Uitgevoerde stappen:
1. Laravel project aangemaakt in laravel-rebuild.
2. API-routing geactiveerd in laravel-rebuild/bootstrap/app.php.
3. Generator toegevoegd: scripts/generate-laravel-skeleton-from-mapping.mjs.
4. Validator toegevoegd: scripts/validate-laravel-skeleton.mjs.
5. package.json scripts toegevoegd:
   - generate:laravel-skelet
   - gate:laravel-skelet
6. Skelet gegenereerd: 6 controllers, 15 routes.
7. Verificatiebug gevonden (parse error in methodenaamgeneratie).
8. Bug direct gefixt in generator + validator (segment-splitting/camelcase).
9. Skelet opnieuw gegenereerd en opnieuw gevalideerd (GO).

## 7. Heroverleg

Onafhankelijke herbeoordeling na implementatie:
- Security auditor: akkoord, stubs geven 501 i.p.v. schijnbare success-responses.
- Laravel reviewer A: akkoord, project is echt Laravel en routes worden geladen.
- Laravel reviewer B: initieel bezwaar wegens parsefout, na fix akkoord onder voorwaarde van regressiecontrole.
- QA auditor: akkoord, gate + artisan output leveren reproduceerbaar bewijs.
- Hosting auditor: akkoord, implementatie is PHP-first en past bij shared-hosting-richting.

Hervergaderbesluit:
- Iteratie 03 doorgelaten naar verificatie na succesvolle rerun van alle checks.

## 8. Verificatie

Technische controle:
- Composer installatie geslaagd; Laravel Framework 13.9.0 draait.
- Skeletgenerator draait succesvol.
- Skeletgate geeft GO.

Functionele controle:
- Alle Must-scope routes zijn aanwezig (15 routes).
- Alle bijbehorende controllermethoden bestaan.

Securitycontrole:
- Controllers retourneren expliciet 501 zolang businesslogica ontbreekt.
- Geen credentials hardcoded in nieuwe scripts.

Hosting-compatibiliteitscontrole:
- Implementatiepad is Laravel/PHP-gebaseerd en daarmee aligned op shared hosting zonder Node-runtime.

Regressiecontrole:
- Parsefout ontdekt en hersteld in dezelfde iteratie.
- Eindsituatie: php artisan route:list --path=api toont 15 routes zonder parsefout.

Eindbesluit iteratie 03:
- GO voor deze implementatiestap: eerste Laravel-skelet is formeel voorbereid, goedgekeurd, geïmplementeerd en geverifieerd.
- Verplichte volgende iteratie: domeinimplementatie van eerste kritieke module (MUST-AUTH-MFA) met dezelfde governancevolgorde.
