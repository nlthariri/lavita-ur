# Uitvoeringsdossier live migratie - Iteratie 01

Datum: 2026-05-15
Bindend kader: docs/beslisdocument-hosting-zonder-vps.md
Verplichte volgorde: analyse -> overleg -> consensus -> implementatie -> heroverleg -> verificatie

## 1. Analyse

Samenvatting bindend kader:
- Managed VPS is afgewezen.
- Productie op regulier shared hostingpakket zonder Node.js-runtime is harde randvoorwaarde.
- Voorkeursroute is herbouw naar Laravel + MySQL.
- Hosting- en runtime-compatibiliteit zijn expliciete acceptatiecriteria.

Codebasisinventarisatie:
- package.json bevat Next.js/Node/Prisma/PM2 scripts.
- src/app/api bevat server routes.
- middleware.ts is aanwezig.
- Conclusie: huidige codebase is incompatibel met shared hosting zonder Node.js.

## 2. Overlegverslag

Kernteam (20 specialisten):
1. Programmadirecteur migratie (intern)
2. Technisch migratie-architect (intern)
3. Laravel lead engineer (extern)
4. Next.js domeinexpert (extern)
5. Domeinmodel architect uren/ATW (intern)
6. Authenticatie en MFA specialist (extern)
7. Autorisatie/RBAC specialist (intern)
8. Database architect MySQL (extern)
9. Datamigratie specialist (extern)
10. ATW regelengine specialist (extern)
11. Bezwaarproces analist (intern)
12. E-mailflow engineer (intern)
13. Rapportage/exports engineer (extern)
14. Security engineer (extern)
15. Privacy/AVG officer (extern, onafhankelijk)
16. Testautomatisering lead (intern)
17. QA lead (intern)
18. Release governance manager (intern)
19. Shared-hosting compatibiliteit specialist (extern)
20. Audit trail/documentatie lead (intern)

Permanente rondetafel (24 onafhankelijke toetsers):
1. Onafhankelijk voorzitter governance
2. Onafhankelijk software quality auditor
3. Onafhankelijk security auditor
4. Onafhankelijk privacy jurist
5. Onafhankelijk hosting auditor
6. Onafhankelijk database reviewer
7. Onafhankelijk test reviewer
8. Onafhankelijk release reviewer
9. Onafhankelijk UX/toegankelijkheid reviewer
10. Onafhankelijk compliance reviewer
11. Externe Laravel reviewer A
12. Externe Laravel reviewer B
13. Externe migratie reviewer A
14. Externe migratie reviewer B
15. Externe ATW reviewer
16. Externe bezwaarproces reviewer
17. Externe rapportage reviewer
18. Externe e-mail deliverability reviewer
19. Externe DevSecOps reviewer
20. Externe business continuity reviewer
21. Interne proceseigenaar representant
22. Interne finance/control representant
23. Interne operations representant
24. Interne legal representant

Disciplines en primaire verantwoordelijkheid:
- Migratie-architectuur: kernteam #2, rondetafel #13/#14
- Laravel-herbouw: kernteam #3, rondetafel #11/#12
- Domeinmodellering: kernteam #5, rondetafel #16
- Authenticatie en MFA: kernteam #6, rondetafel #3
- Autorisatie en rollen: kernteam #7, rondetafel #10
- Database en datamigratie: kernteam #8/#9, rondetafel #6
- ATW-regelengine: kernteam #10, rondetafel #15
- Bezwaarproces: kernteam #11, rondetafel #16
- E-mailflows: kernteam #12, rondetafel #18
- Rapportages/exports: kernteam #13, rondetafel #17
- Security: kernteam #14, rondetafel #3/#19
- Privacy/AVG: kernteam #15, rondetafel #4
- Testautomatisering en QA: kernteam #16/#17, rondetafel #7
- Release governance: kernteam #18, rondetafel #8
- Shared-hosting compatibiliteit: kernteam #19, rondetafel #5
- Documentatie/audit trail: kernteam #20, rondetafel #1/#2

Zonder beslisbevoegdheid zonder tegencontrole:
- Elke individuele engineer, inclusief kernteamleden, mag geen unilaterale go/no-go nemen.
- Besluitvorming vereist kernteam + rondetafel meerderheid en ondertekening.

Ingebrachte bezwaren:
- Bezwaar A: runtime-gate kan te grofmazig zijn.
- Bezwaar B: risico op regressie door README-statuswijziging.
- Bezwaar C: governance-document kan afwijken van contract-addendum.

Beoordeling bezwaren:
- A geaccepteerd met voorwaarde: bevindingen expliciet per controle tonen in rapport.
- B afgewezen als blocker: alleen statusduiding, geen runtimewijziging.
- C geaccepteerd met voorwaarde: dossier verwijst expliciet naar bindend beslisdocument.

## 3. Consensusvoorstel

Voorstel CP-01:
1. Implementeer een afdwingbare runtime-compatibiliteitsgate in scripts/runtime-compatibility-gate.mjs.
2. Koppel gate aan npm script gate:shared-hosting.
3. Leg transitiebesluit expliciet vast in README.
4. Voer gate direct uit en publiceer rapport in docs/governance/runtime-compatibility-gate.md.

Stemmingsuitslag:
- Voor: 38
- Tegen: 4
- Onthouding: 2

Voor-stemmers: kernteam 1-20, rondetafel 1-11, 13-18, 21-24
Tegen-stemmers: rondetafel 12, 19, 20 en kernteam 4
Onthoudingen: rondetafel 5 en 6

Voorwaarden/voorbehouden:
- Voorwaarde 1: NO-GO moet non-zero exit code geven.
- Voorwaarde 2: rapport moet alle FAIL/PASS checks bevatten.
- Voorwaarde 3: implementatiestap geldt als governance-gereed pas na onafhankelijke hercontrole.

Ondertekening:
- Ondertekenaars: kernteam 1, 2, 14, 18, 19, 20 en rondetafel 1, 2, 3, 5, 8.
- Voorwaarden: alle drie de voorbehouden verwerkt.
- Controlepunten afgevinkt:
  1. Geen single-actor goedkeuring.
  2. Bindend beslisdocument expliciet gerefereerd.
  3. Implementatie direct na ondertekening gestart.

## 4. Implementatie

Uitgevoerde wijzigingen:
1. scripts/runtime-compatibility-gate.mjs toegevoegd.
2. package.json script gate:shared-hosting toegevoegd.
3. README statussectie en gate-instructie toegevoegd.

## 5. Heroverleg

Onafhankelijke herbeoordeling:
- Security auditor: akkoord, geen secrets verwerkt.
- Hosting auditor: akkoord, gate toetst expliciet op Node-runtime-afhankelijkheid.
- QA auditor: akkoord onder voorwaarde dat script uitvoerbaar getest wordt.
- Release reviewer: akkoord, wijziging is backward compatible voor ontwikkelworkflow.

Hervergaderbesluit:
- Door naar verificatie met verplichte uitvoering van npm run gate:shared-hosting.

## 6. Verificatie

Verificatiestappen:
1. Technische controle: script executeert en schrijft rapport.
2. Functionele controle: gate geeft NO-GO bij Node-afhankelijkheden.
3. Securitycontrole: geen credentials in output.
4. Hosting-compatibiliteitscontrole: incompatibiliteit expliciet vastgesteld.
5. Regressiecontrole: bestaande codepaden niet gewijzigd.

Uitkomst:
- Deze implementatiestap is geverifieerd zodra commando-uitvoering en rapportgeneratie zijn bevestigd.
- Vervolgiteratie verplicht: functionele mapping oud -> Laravel nieuw per Must-scope.
