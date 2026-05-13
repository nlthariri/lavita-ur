# Architectuur La Vita Urenregistratie

## Scopebasis

Bron van waarheid: opdrachtspecificatie v1.1.

## Gemaakte aannames

- Hostingdoel is Cloud86 met Node.js-runtime (VPS of managed container), omdat alleen FTP niet voldoet voor server-side Next.js.
- Productiedomein draait op subdomein van het bedrijfsdomein, bijvoorbeeld `uren.jouwdomein.nl`.
- Schaaldoel is klein tot middelgroot: 3 naar 10 medewerkers in 3 jaar. Daarom is single-region EER met verticale schaal en eenvoudige horizontale optie passend.
- Geen publieke REST API in v1; interne route handlers worden gebruikt voor frontend-backendverkeer.

## Laag 1: Architectuur

- Applicatie: Next.js App Router met TypeScript.
- Datalaag: MySQL + Prisma (beheerbaar via Cloud86/phpMyAdmin).
- Domeinmodules:
  - ureninvoer en netto-minutenberekening
  - ATW-signalen (dag/week/16-weken/rust)
  - bezwaarproces
  - e-mailtemplates en e-mailevents

## Laag 2: Backend

Aanwezig:
- Prisma schema met rollen, teams, projecten, uren, bezwaren, ATW-schendingen en e-mailtabellen.
- Inputvalidatie met Zod voor urenregistratie.
- Service voor directe vaststelling van uren en opslag van ATW-signalen.
- Interne API endpoint voor werkentry creatie.

## Laag 3: Frontend

Aanwezig:
- Basislandingsscherm in Nederlands.
- Design tokens volgens Cal.com-stijl uit de spec.

Nog te bouwen:
- Inlog + MFA flow.
- Weekoverzicht, invoermodal, medewerker-urenstaat, bezwaarbeoordeling.
- ATW-dashboard, e-mailbeheer, rapportagescherm.

## Laag 4: Security en compliance

Aanwezig:
- Rolrestrictie op server voor urenregistratie (alleen owner/manager).
- Teamrestrictie voor manager.
- Data- en modelstructuur voorbereid op 7-jaarsretentie en ATW-incidentregistratie.

Nog te bouwen:
- Volledige MFA-setupflow in UI met beleidsafdwinging voor owner/manager.
- Wachtwoordbeleid conform NCSC.
- Geautomatiseerde retentie/pseudonimiseringsjobs.
- Juridische documentset (VWO, datalekprocedure, DPIA-onderdelen).

## Laag 5: Ops

Nog te bouwen:
- CI/CD pipeline met lint, build, test, migration check.
- Monitoring en uptime/SLA-dashboard.
- Backup/restore runbook met jaarlijkse hersteltestregistratie.
