# Uitvoeringsdossier live migratie - Iteratie 02

Datum: 2026-05-15
Bindend kader: docs/beslisdocument-hosting-zonder-vps.md
Verplichte volgorde: analyse -> overleg -> consensus -> implementatie -> heroverleg -> verificatie

## 1. Analyse

Bindend kader samenvatting:
- Productie op regulier shared hosting zonder Node.js-runtime is harde randvoorwaarde.
- Herbouwroute Laravel + MySQL is voorkeursroute.
- Functionele mapping oud -> nieuw moet formeel vastgesteld zijn vóór implementatiefase.

Code- en trajectanalyse voor iteratie 02:
- Iteratie 01 leverde runtime NO-GO op voor huidige Next.js codebase.
- Open verplicht punt uit iteratie 01: uitwerken van functionele mapping voor Fase A Must-scope.
- Keuze voor deze iteratie: machine-leesbare mapping met automatische dekkingstoets, zodat governance objectief GO/NO-GO kan bepalen.

## 2. Overleg

Deelnemers:
- Kernteam 1 t/m 20 (intern/extern gemengd) actief in deze iteratie.
- Rondetafel 1 t/m 24 als onafhankelijke toetslaag actief in deze iteratie.

Disciplinebijdragen:
- Migratie-architectuur (kernteam 2, rondetafel 13/14): eist traceerbare mapping per Must-capability met bronverwijzingen.
- Laravel-herbouw (kernteam 3, rondetafel 11/12): eist target modules en doelroutes per capability.
- Domeinmodellering (kernteam 5, rondetafel 16): eist datamodellen per capability.
- Auth/MFA (kernteam 6, rondetafel 3): eist expliciete MFA-afdwinging en security-acceptatiecriteria.
- Autorisatie (kernteam 7, rondetafel 10): eist guards en rolafbakening per route.
- Database/migratie (kernteam 8/9, rondetafel 6): eist dataconsistentievoorwaarden voor herbouw.
- ATW-engine (kernteam 10, rondetafel 15): eist expliciete normset dag/week/16-weken/rust.
- Bezwaarproces (kernteam 11, rondetafel 16): eist statusovergangen en conflictbeheersing.
- E-mailflows (kernteam 12, rondetafel 18): eist idempotentie en opt-out-regels.
- Rapportages (kernteam 13, rondetafel 17): eist PDF/Excel parity.
- Security (kernteam 14, rondetafel 3/19): eist non-zero exit bij onvolledige mapping.
- Privacy/AVG (kernteam 15, rondetafel 4): eist expliciete review op ontvanger- en doelbindingsregels.
- Test/QA (kernteam 16/17, rondetafel 7): eist geautomatiseerde gate met reproduceerbare output.
- Release governance (kernteam 18, rondetafel 8): eist npm-invokable gate in CI-geschikte vorm.
- Shared-hosting compatibiliteit (kernteam 19, rondetafel 5): eist dat gate als pre-go/no-go documenteerbaar is.
- Audit trail/documentatie (kernteam 20, rondetafel 1/2): eist dossier + rapport met datum en bindend kader.

Ingebrachte bezwaren en beoordeling:
- Bezwaar A (rondetafel 12): JSON-mapping kan semantisch te vrij blijven.
  Beoordeling: geaccepteerd met aanvullende verplichte velden en gate-checks.
- Bezwaar B (rondetafel 20): risico op administratieve artefacten zonder uitvoercontrole.
  Beoordeling: geaccepteerd met voorwaarde dat gate direct wordt uitgevoerd in dezelfde sessie.
- Bezwaar C (kernteam 4): mapping zegt nog niets over code-migratie zelf.
  Beoordeling: juist; niet blocker voor deze iteratie omdat beslisdocument eerst transitieplan vereist.

## 3. Consensus

Besluitvoorstel CP-02:
1. Voeg machine-leesbare Fase A Must-mapping toe in docs/governance/functionele-mapping-fase-a.json.
2. Voeg validator-gate toe in scripts/validate-functionele-mapping.mjs.
3. Expose gate via npm script gate:functionele-mapping.
4. Voer gate direct uit en neem resultaat op in docs/governance/functionele-mapping-check.md.

Stemmingsuitslag:
- Voor: 40
- Tegen: 3
- Onthouding: 1

Voor-stemmers:
- Kernteam: 1,2,3,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20
- Rondetafel: 1,2,3,4,5,6,7,8,9,10,11,13,14,15,16,17,18,19,21,22,23

Tegen-stemmers:
- Kernteam: 4
- Rondetafel: 12,20

Onthouding:
- Rondetafel: 24

Voorwaarden en voorbehouden:
- Voorwaarde 1: alle zes Must-scope onderdelen zijn verplicht in mapping.
- Voorwaarde 2: per onderdeel minimaal twee onafhankelijke reviewers.
- Voorwaarde 3: gate moet NO-GO geven bij ontbrekende scope/criteria.
- Voorwaarde 4: rapport moet in docs/governance staan voor audit trail.

Ondertekening:
- Ondertekenaars kernteam: 1,2,14,18,19,20
- Ondertekenaars rondetafel: 1,2,3,5,7,8
- Ondertekening onder voorwaarden: alle 4 voorwaarden als verplicht afgevinkt.
- Controlepunten afgevinkt:
  1. Geen single-actor goedkeuring.
  2. Tegencontrole door onafhankelijke rondetafel uitgevoerd.
  3. Implementatie direct na ondertekening gestart.

## 4. Implementatie

Uitgevoerde wijzigingen:
1. docs/governance/functionele-mapping-fase-a.json toegevoegd.
2. scripts/validate-functionele-mapping.mjs toegevoegd.
3. package.json uitgebreid met gate:functionele-mapping.
4. Gate direct uitgevoerd: npm run gate:functionele-mapping.
5. Rapport gegenereerd: docs/governance/functionele-mapping-check.md.

## 5. Heroverleg

Onafhankelijke herbeoordeling na uitvoering:
- Security auditor (rondetafel 3): akkoord, gate forceert objectieve pass/fail.
- Hosting auditor (rondetafel 5): akkoord, mapping-gate borgt preconditie voor shared-hosting transitiegovernance.
- QA auditor (rondetafel 7): akkoord, output reproduceerbaar en CI-geschikt.
- Compliance reviewer (rondetafel 10): akkoord, scope dekt Fase A Must volledig.
- Laravel reviewer A (rondetafel 11): akkoord, doelmodules en routes zijn expliciet.
- Laravel reviewer B (rondetafel 12): handhaaft eerder bezwaar op semantische vrijheid, maar erkent dat minimale norm nu afdwingbaar is.

Hervergaderbesluit:
- Iteratie 02 door naar verificatie met formele checks op techniek, functie, security, hosting-compatibiliteit en regressie.

## 6. Verificatie

Technische controle:
- npm run gate:functionele-mapping uitgevoerd zonder runtimefouten.
- Rapportbestand succesvol geschreven.

Functionele controle:
- Alle zes Must-scope onderdelen zijn aanwezig en PASS.
- Per onderdeel zijn module, routes, reviewers en acceptatiecriteria gevalideerd.

Securitycontrole:
- Gate gebruikt geen secrets en verwerkt alleen lokale documentatie-invoer.
- Non-zero exitpad is geïmplementeerd voor NO-GO.

Hosting-compatibiliteitscontrole:
- Deze stap valideert transitievoorbereiding (mapping-compleetheid), niet de runtime-go status van de huidige code.
- Runtime-go status blijft NO-GO voor huidige Next.js basis (iteratie 01).

Regressiecontrole:
- Geen bestaande applicatiecode of productie-flow gewijzigd.
- Alleen governance- en validatieartefacten toegevoegd.

Eindbesluit iteratie 02:
- GO voor deze implementatiestap: transitieplan-gereedheidscontrole is formeel voorbereid, uitgevoerd en geverifieerd.
- Verplichte volgende iteratie: implementatie van eerste Laravel-skelet met dezelfde governancevolgorde.
