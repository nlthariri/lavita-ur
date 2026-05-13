# Validatie En Expert Review Protocol

Doel: volgende sessies focussen op valideren, controleren, direct fixen, direct optimaliseren en direct implementeren waar nodig.

## Kwaliteitsdoel

- Functioneel doel: 100 procent werkend op afgesproken scope.
- Bugdoel: minimaal 95 procent bugvrij op kritieke en hoge impact paden.
- Werkwijze: geen analyse zonder executie; issues direct reproduceren, fixen en her-valideren.

## Verplicht Expert Overleg

Voor elke taak wordt overleg op twee momenten gedaan:

1. Voor de taak (pre-task review)
- Security expert: risico, misbruikpad, datalek en auth/csrf/rate-limit impact.
- Backend expert: domeinlogica, transacties, concurrency, idempotency.
- QA expert: teststrategie, regressierisico, acceptatiecriteria.
- Ops expert: deploybaarheid, monitoring, jobs, rollback.

2. Na de taak (post-task review)
- Peer review op gewijzigde code en gedrag.
- Validatie review op testresultaten, lint, build en runtime checks.
- Rest-risico review met expliciete lijst wat nog open staat.

## Verplicht Validatiepad Per Ronde

1. Baseline
- Verzamel huidige status en laatst bekende failures.
- Definieer duidelijke done-criteria per taak.

2. Implementatie
- Pas alleen noodzakelijke wijzigingen toe.
- Houd security en foutafhandeling fail-safe.

3. Lokale kwaliteitsgate
- Draai altijd:
  - npm run verify:fast
  - npm run verify:full

4. Gerichte controles
- Security checks op muterende routes.
- Concurrency checks op transacties (zoals bezwaar review).
- Data checks op audit/history/outbox integriteit.

5. Post-task expert review
- Bevestig: opgelost, niet opgelost, of gedeeltelijk opgelost.
- Voor elk open punt: impact, eigenaar, eerstvolgende concrete actie.

## Bugbeleid

- P1/P2 bugs: direct fixen in dezelfde ronde.
- P3 bugs: fixen als laag risico en snel oplosbaar; anders expliciet plannen.
- Elke fix moet gevolgd worden door een her-test van het relevante pad.

## Rapportageformat Per Sessie

Gebruik altijd dit korte format:

1. Gedaan
- Welke fixes/optimalisaties zijn doorgevoerd.

2. Gevalideerd
- Welke checks zijn gedraaid en met welke uitkomst.

3. Open risico
- Wat nog niet 100 procent afgedekt is.

4. Volgende directe actie
- Eerste concrete stap voor de volgende ronde.

## Standaard Commando's

- Snelle ronde: npm run verify:fast
- Volledige ronde: npm run verify:full
- Migrate/generate alleen indien relevant voor de wijziging.
