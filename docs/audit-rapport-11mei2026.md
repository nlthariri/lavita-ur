## Algemene volwassenheidsscore per domein (0-100)

1. Architectuur en systeemontwerp: 68  
2. Backend: 72  
3. Frontend: 58  
4. Security: 70  
5. Data en database: 74  
6. Performance en schaalbaarheid: 61  
7. DevOps/SRE en operaties: 66  
8. Teststrategie en kwaliteit: 22  
9. Compliance en governance: 63  
10. Maintainability en team velocity: 64  

## Top 10 kritieke risico’s (op business-impact)

1. Nul geautomatiseerde testdekking over kritieke stromen (auth, uren, bezwaar, ATW).  
2. Ontbrekende audit trail voor hoog-risico mutaties (bezwaarbesluiten, correcties, verwijderacties).  
3. Retentie/pseudonimisering operationeel afhankelijk van handmatige uitvoering.  
4. Rate limiting faalt stil naar in-memory fallback bij Redis-storing.  
5. Geen transactieve outbox/idempotency voor e-mail; kans op dubbele/onjuiste verzending.  
6. Onvoldoende release safety: geen CI quality gates buiten lint/build.  
7. Frontend toegankelijkheidsbasis onvoldoende (interactieve elementen zonder keyboard-semantiek/labels).  
8. Geen aantoonbare centrale observability (gestructureerde logs, tracing, alerts op security-events).  
9. Correctiepad bij bezwaar overschrijft uren zonder historisering (forensische reconstructie onmogelijk).  
10. Performance-onzekerheid: geen loadtest-baseline of capaciteitsgrenzen vastgesteld.

## Go/No-Go advies voor productiebetrouwbaarheid

No-Go voor opschalen of compliance-gevoelige productiebelasting.  
Go met beperkingen voor huidige kleine footprint alleen als minimaal Fase 1 uit hoofdstuk G volledig is afgerond en geaccepteerd.

---

# B. Scope, bronnen en beperkingen

## Wat is geaudit

1. Volledige codebasis in de workspace, inclusief API-routes, services, security, database, scripts en documentatie.
2. Configuratie en deployment-bestanden.
3. Beschikbare outputcontext: lint/build/audit-run succesvol geëindigd (exit code 0).

## Gebruikte input

1. Structuur en code onder src, prisma, scripts, docs.
2. Kernconfiguraties: package.json, ecosystem.config.cjs, next.config.ts, middleware.ts, tsconfig.json.
3. Security/data/auth codepaden:
session handling,
rate limiting,
objection review,
work-entry create,
password reset,
CSRF,
schema.
4. Operations/runbooks:
README,
architectuurdoc,
ops runbook.

## Niet verifieerbaar (Onvoldoende bewijs)

1. Werkelijke productie-infra-instellingen (TLS-terminatie, netwerksegmentatie, Redis hardening, SMTP-providerbeleid).
2. CI/CD-pipelineconfiguratie buiten repository.
3. Restore-testresultaten en RTO/RPO-bewijs.
4. Productie-telemetrie (APM, logs, alert-historie).
5. Juridische documenten (DPA/subprocessor-overeenkomsten, DPIA).

---

# C. Systeemoverzicht

## Huidige architectuur

1. Monolithische Next.js App Router-opzet met server-side route handlers in api.
2. Businesslogica in service-laag onder lib, waaronder:
ATW-engine engine.ts,
urenregistratie service.ts,
bezwaren service.ts.
3. Datalaag via Prisma/MySQL in schema.prisma.
4. Auth via httpOnly sessioncookie met HMAC-signature in session.ts.
5. E-mailafhandeling via SMTP/Nodemailer in service.ts.

## Kritieke paden en afhankelijkheden

1. Authenticatiepad: login + MFA-verificatie + sessie.
login route,
MFA verify,
session service
2. Ureninvoerpad: invoer → ATW-evaluatie → opslag → notificaties.
API route,
service,
ATW engine
3. Bezwaarpad: indienen → review/correctie → herbeoordeling ATW → notificaties.
review route,
objection service
4. Operationeel pad: deploy → migraties → build → PM2 reload → readiness check.
deploy script,
PM2 config,
readiness

## Belangrijkste failure modes

1. Redis-storing degradeert rate limits naar lokale memory, gedrag per instance verschillend.
2. Correctie op bezwaar overschrijft urenrecord zonder immutable history.
3. Handmatige retentiejob niet uitgevoerd binnen beleidsvenster.
4. Geen testnet: regressie in ATW-berekening of autorisatie bereikt productie.
5. Geen centrale detectie op security-events vergroot dwell time bij incidenten.

## Verplichte methode (5 stappen) en uitkomst

### Stap 1: Baseline scan

1. Systeemkaart en afhankelijkheden opgesteld op basis van src, prisma, scripts, docs.
2. Kritieke paden en trust boundaries vastgesteld.

### Stap 2: Deep inspection

1. Hotspots geanalyseerd: auth, objections, work entries, rate limiting, retention, e-mail.
2. Config-risico’s en operationele lacunes vastgesteld in middleware.ts, ecosystem.config.cjs, ops-24-7.md.

### Stap 3: Failure-mode analyse

1. Breekpunten, blast radius en detecteerbaarheid bepaald per hoog-risico stroom.
2. Herstelbaarheid gekoppeld aan bestaand runbook en ontbrekende controles.

### Stap 4: Prioritering

1. Risicoscore per bevinding berekend met:
Risicoscore = (Impact × Kans × Detecteerbaarheid) + Herstelcomplexiteit.
2. Klasse toegepast:
Critical 60+, High 40-59, Medium 20-39, Low <20.

### Stap 5: Optimalisatieplan

1. Gefaseerde roadmap (quick wins → stabilisatie → modernisering) met eigenaarschap en KPI’s in hoofdstuk G.
2. Direct uitvoerbare acceptatiecriteria per issue in hoofdstuk D.

---

# D. Bevindingenregister (volledig)

## F-01

- ID: F-01  
- Domein: Teststrategie en kwaliteit  
- Titel: Geen geautomatiseerde tests in repository  
- Feitelijke observatie: Geen unit/integration/e2e testbestanden aangetroffen.  
- Bewijs: package.json, src  
- Feit: Geen testscript aanwezig in package.json.  
- Hypothese: Regressies in ATW/autorisatie worden pas in productie zichtbaar.  
- Aanname: Team vertrouwt op handmatige controle en build/lint-only poort.  
- Risico-uitleg (business + techniek): Hogere incidentkans, trage releases, compliance-risico bij foutieve uren/ATW-uitkomsten.  
- Impact (1-5): 5  
- Kans (1-5): 5  
- Detecteerbaarheid (1-5): 4  
- Herstelcomplexiteit (1-5): 4  
- Risicoscore + klasse: 104, Critical  
- Root cause: Geen teststrategie en geen quality gate-afdwinging.  
- Aanbevolen oplossing (concreet, stapbaar):
1. Voeg testframework toe (unit + API-integration + e2e smoke).  
2. Start met 15 risicogedreven tests op auth/ATW/objection workflows.  
3. Maak CI-gate: fail bij testfouten.  
- Alternatieven met trade-offs:
1. Alleen e2e smoke: sneller, maar lage foutlokalisatie.  
2. Alleen unit tests: sneller opzetten, maar contractfouten blijven liggen.  
- Effort (S/M/L/XL): L  
- Eigenaar-rol: QA lead + backend/frontend engineers  
- Afhankelijkheden: Testdatabase, CI-config, seeddata  
- Verificatietest: Pipeline faalt op intentionele regressie in ATW en autorisatie.  
- Acceptatiecriteria:
1. Minimaal 80% dekking op kritieke services.  
2. E2E smoke op login, ureninvoer, bezwaar, rapportage.  
3. CI blokkeert merge op falende tests.  
- Risico bij uitstel (30/90/180 dagen):
30: stijgende regressies; 90: hogere incidentdruk; 180: structurele release-onbetrouwbaarheid.

---

## F-02

- ID: F-02  
- Domein: Compliance en governance / Security  
- Titel: Geen audit trail voor sensitieve mutaties  
- Feitelijke observatie: Acties zoals user soft-delete en bezwaarreview worden wel uitgevoerd, maar niet in aparte auditlog vastgelegd met actor/doel/voor- en na-waarde.  
- Bewijs: delete user route, objection service, schema  
- Feit: Geen auditlog-model aanwezig in schema.prisma.  
- Hypothese: Forensische reconstructie en accountability schieten tekort bij geschillen/incidenten.  
- Aanname: E-mail events worden niet gebruikt als juridisch volledige audit trail.  
- Risico-uitleg (business + techniek): Governance-gat, juridisch risico, onvolledige root-cause analyses.  
- Impact: 5  
- Kans: 4  
- Detecteerbaarheid: 4  
- Herstelcomplexiteit: 3  
- Risicoscore + klasse: 83, Critical  
- Root cause: Geen expliciete audit-architectuur in datamodel en services.  
- Aanbevolen oplossing:
1. Introduceer tabel AuditEvent met actor, targetType, targetId, action, before/after hash, timestamp, request metadata.  
2. Schrijf events transactioneel mee in kritieke services/routes.  
3. Voeg read-only audit export voor compliance toe.  
- Alternatieven met trade-offs:
1. Applicatielogs als audit: sneller, maar zwakker qua integriteit/zoekbaarheid.  
2. DB triggers: sterk, maar lastiger domeincontext mee te geven.  
- Effort: M  
- Eigenaar-rol: Security engineer + DBA + backend engineer  
- Afhankelijkheden: Migratie, privacyreview, retentionbeleid auditdata  
- Verificatietest: Elke delete/review/correctie creëert exact één auditrecord met actor en diff.  
- Acceptatiecriteria:
1. 100% coverage op kritieke mutaties.  
2. Audit records niet wijzigbaar via app-rollen.  
3. Exporteerbaar per periode/gebruiker/actie.  
- Risico bij uitstel:
30: beperkte traceability; 90: compliance-escalaties; 180: ernstig juridisch bewijsprobleem.

---

## F-03

- ID: F-03  
- Domein: Data en database / Compliance  
- Titel: Retentie/pseudonimisering is niet technisch afgedwongen  
- Feitelijke observatie: Pseudonimisering bestaat als script en runbooktaak, maar geen geautomatiseerde scheduler in runtimeconfig.  
- Bewijs: pseudonimize script, PM2 config, ops runbook, README  
- Feit: Uitvoering staat beschreven als periodieke handeling.  
- Hypothese: Gemiste run leidt tot overschrijding bewaartermijnen.  
- Aanname: Geen externe scheduler buiten repository actief.  
- Risico-uitleg: Privacy/AVG-risico, auditbevindingen, reputatieschade.  
- Impact: 5  
- Kans: 4  
- Detecteerbaarheid: 3  
- Herstelcomplexiteit: 2  
- Risicoscore + klasse: 62, Critical  
- Root cause: Geen geautomatiseerde policy enforcement + monitor.  
- Aanbevolen oplossing:
1. Maak geautomatiseerde schedule (cron/job runner) met statusrapportage.  
2. Voeg idempotente job-run logging toe (laatste succesvolle run, duur, aantallen).  
3. Alerting bij gemiste/gefalde run.  
- Alternatieven met trade-offs:
1. Handmatig met checklist: laagste implementatie, hoogste menselijke foutkans.  
2. DB-event scheduler: dichter bij data, minder transparant in app-ops.  
- Effort: M  
- Eigenaar-rol: DevOps/SRE + privacy/compliance + backend  
- Afhankelijkheden: Productie scheduler, monitoringkanaal  
- Verificatietest: Simuleer data ouder dan cutoff en verifieer automatische pseudonimisering + job log.  
- Acceptatiecriteria:
1. Run minimaal wekelijks automatisch.  
2. Foutmelding en alert binnen 5 minuten.  
3. Rapport met aantallen mutaties per run.  
- Risico bij uitstel:
30: compliance debt; 90: reëel overtredingsrisico; 180: structureel non-compliant.

---

## F-04

- ID: F-04  
- Domein: Security / DevOps  
- Titel: Rate limiting degradeert stil naar in-memory fallback  
- Feitelijke observatie: Bij Redis-fout schakelt implementatie over op lokale geheugenbuckets zonder hard-fail of expliciete alarmering.  
- Bewijs: rate limit service, login route  
- Feit: Redis errors zetten interne disable-state en fallbackpad actief.  
- Hypothese: In multi-instance kan limiter inconsistent worden en brute-force venster vergroten.  
- Aanname: Geen externe alert op Redis degrade-event.  
- Risico-uitleg: Verlaagde AuthN-hardening tijdens storingen.  
- Impact: 4  
- Kans: 4  
- Detecteerbaarheid: 4  
- Herstelcomplexiteit: 3  
- Risicoscore + klasse: 67, Critical  
- Root cause: Beschikbaarheidsvoorkeur boven security-striktheid zonder detectie.  
- Aanbevolen oplossing:
1. Voeg degrade-telemetrie + alert toe bij fallback activatie.  
2. Maak policy per endpoint: auth-flows fail-closed bij Redis outage.  
3. Voeg per-user + per-IP + per-credential throttles toe.  
- Alternatieven met trade-offs:
1. Altijd fail-closed: sterker security, maar kans op false lockout bij infra-issues.  
2. Huidig gedrag + betere monitoring: minder impact op availability, zwakkere securitypostuur.  
- Effort: M  
- Eigenaar-rol: Security engineer + SRE + backend  
- Afhankelijkheden: Monitoringstack, Redis SLO’s  
- Verificatietest: Schakel Redis uit en verifieer alarm + verwacht endpointgedrag volgens policy.  
- Acceptatiecriteria:
1. Alarm binnen 1 minuut op fallback.  
2. Login/reset endpoints volgen fail-closed policy.  
3. Documenteerde runbook-stap voor herstel.  
- Risico bij uitstel:
30: verhoogd brute-force venster; 90: herhaalbare securitydegradatie; 180: incidentkans hoog.

---

## F-05

- ID: F-05  
- Domein: Backend / Data-integriteit  
- Titel: Bezwaarcorrectie overschrijft werkentry zonder historisering  
- Feitelijke observatie: Bij goedgekeurd bezwaar wordt het bestaande werkentryrecord direct geüpdatet; oude staat verdwijnt.  
- Bewijs: objection service  
- Feit: Update gebeurt in-place binnen transactie.  
- Hypothese: Bij geschil is oorspronkelijke invoer niet aantoonbaar terug te halen.  
- Aanname: Geen DB-level CDC of externe immutable log actief.  
- Risico-uitleg: Juridisch bewijsverlies, beperkte incidentanalyse.  
- Impact: 4  
- Kans: 4  
- Detecteerbaarheid: 4  
- Herstelcomplexiteit: 3  
- Risicoscore + klasse: 67, Critical  
- Root cause: Ontbrekend versioned recordmodel voor urenmutaties.  
- Aanbevolen oplossing:
1. Voeg WorkEntryHistory toe met version, changedBy, changedAt, reason.  
2. Maak work_entries immutable na finalisatie; correctie via nieuwe versie/adjustment.  
3. Toon volledige wijzigingsketen in bezwaarweergave.  
- Alternatieven met trade-offs:
1. Alleen auditevent met before/after JSON: sneller, minder queryvriendelijk voor businessrapportage.  
2. Soft-copy van oude entry in comments: laagste kwaliteit, kwetsbaar.  
- Effort: L  
- Eigenaar-rol: Backend + DBA + product/operations  
- Afhankelijkheden: Datamodelwijziging, UI-aanpassing  
- Verificatietest: Na correctie blijven v1 en v2 raadpleegbaar en consistent in rapportage.  
- Acceptatiecriteria:
1. Geen directe overwrite zonder historyrecord.  
2. Elke correctie traceerbaar op actor/tijd/reden.  
3. Rapportages kunnen actuele en historische waarde tonen.  
- Risico bij uitstel:
30: bewijsgat; 90: geschil-escalatie; 180: structureel complianceprobleem.

---

## F-06

- ID: F-06  
- Domein: DevOps/SRE  
- Titel: Geen aantoonbare centrale observability en security-event monitoring  
- Feitelijke observatie: Runbook noemt checks, maar codebase toont geen geïntegreerde structured logging/APM/SIEM-koppeling.  
- Bewijs: ops runbook, package.json  
- Feit: Geen expliciete logging stack dependency of centrale log configuratie in repository.  
- Hypothese: Detectie en triage van incidenten duren langer dan nodig.  
- Aanname: Externe tooling is niet ingericht of niet afgedwongen.  
- Risico-uitleg: Lagere detecteerbaarheid, hogere MTTR, grotere blast radius.  
- Impact: 4  
- Kans: 4  
- Detecteerbaarheid: 4  
- Herstelcomplexiteit: 3  
- Risicoscore + klasse: 67, Critical  
- Root cause: Observability als operationele instructie, niet als afdwingbare technische implementatie.  
- Aanbevolen oplossing:
1. Introduceer structured logging met request-id/correlation-id.  
2. Voeg security-event stream toe (login failures, MFA failures, privilege actions).  
3. Definieer alerts en SLO’s op health, ready, queue-fouten, auth-anomalieën.  
- Alternatieven met trade-offs:
1. Alleen infrastructuurlogs: laag implementatiewerk, onvoldoende applicatiecontext.  
2. Volledige APM-suite: beste zichtbaarheid, hogere kosten.  
- Effort: M  
- Eigenaar-rol: SRE + security engineer  
- Afhankelijkheden: Logplatform, alertkanalen, runbook-update  
- Verificatietest: Synthetic incident veroorzaakt geautomatiseerde alert + traceerbare requestflow.  
- Acceptatiecriteria:
1. 100% API requests met correlation-id in logs.  
2. Alert op auth-anomalieën binnen 2 minuten.  
3. Dashboard met error rate, latency p95, queue failures.  
- Risico bij uitstel:
30: trage detectie; 90: verhoogde incidentimpact; 180: operationele onbetrouwbaarheid.

---

## F-07

- ID: F-07  
- Domein: Frontend / Accessibility  
- Titel: Interactieve UI-elementen missen semantische toegankelijkheidsborging  
- Feitelijke observatie: Klikbare tabelrijen en forms tonen beperkte labeling/keyboard-semantiek; risico op WCAG-nonconformiteit.  
- Bewijs: mijn uren client, bezwaren client, gebruikers client  
- Feit: Selectie in tabel gebeurt via klik op rij; geen expliciete focus/keyboardpattern zichtbaar.  
- Hypothese: Gebruikers met toetsenbord/screenreader ervaren blokkades.  
- Aanname: Geen afzonderlijke a11y-testset in gebruik.  
- Risico-uitleg: Productiviteitsverlies, toegankelijkheidsrisico, mogelijk aanbestedingsbeperkend.  
- Impact: 3  
- Kans: 4  
- Detecteerbaarheid: 3  
- Herstelcomplexiteit: 2  
- Risicoscore + klasse: 38, Medium  
- Root cause: UX-functionaliteit gebouwd zonder expliciete WCAG acceptance gates.  
- Aanbevolen oplossing:
1. Vervang klikbare rijen door semantische buttons/links met toetsenbordfocus.  
2. Voeg labels/aria-live voor status- en foutmeldingen toe.  
3. Voeg geautomatiseerde a11y checks toe in CI.  
- Alternatieven met trade-offs:
1. Alleen handmatige a11y review: flexibel maar niet schaalbaar.  
2. Alleen lintregels: detecteert niet alle UX-problemen.  
- Effort: M  
- Eigenaar-rol: Frontend + UX/accessibility specialist + QA  
- Afhankelijkheden: Designcomponenten, testtooling  
- Verificatietest: Keyboard-only doorloop succesvol; screenreader leest essentiële context en statuswijzigingen uit.  
- Acceptatiecriteria:
1. Kritieke workflows volledig keyboard-bedienbaar.  
2. Formvelden hebben expliciete labels.  
3. CI a11y-checks blokkeren regressies.  
- Risico bij uitstel:
30: toegankelijkheidsklachten; 90: hogere supportlast; 180: structurele UX-schuld.

---

## F-08

- ID: F-08  
- Domein: Backend / Reliability  
- Titel: E-mailverzending buiten kerntransacties zonder outbox/idempotency  
- Feitelijke observatie: Domeinmutaties en e-mailzendingen zijn niet via transactionele outbox ontkoppeld; failures geven kans op inconsistentie of duplicaten.  
- Bewijs: work entry service, objection service, email service  
- Feit: E-mail events worden wel opgeslagen maar verzending gebeurt direct in requestflow.  
- Hypothese: Timeout/retry-races veroorzaken dubbele e-mails of mismatch met domeinstatus.  
- Aanname: Geen aparte queue worker met exactly-once strategie.  
- Risico-uitleg: Operationele ruis, vertrouwenverlies bij gebruikers, herstelwerk handmatig.  
- Impact: 3  
- Kans: 4  
- Detecteerbaarheid: 3  
- Herstelcomplexiteit: 3  
- Risicoscore + klasse: 39, Medium  
- Root cause: Synchronous side-effects in request lifecycle.  
- Aanbevolen oplossing:
1. Introduceer transactionele outbox tabel en background worker.  
2. Voeg idempotency key per eventtype+entity toe.  
3. Implementeer retry policy met dead-letter.  
- Alternatieven met trade-offs:
1. Best-effort huidige aanpak met Promise.allSettled: eenvoudig, blijft inconsistentierisico houden.  
2. Externe queue service: sterk, maar meer infra-complexiteit.  
- Effort: L  
- Eigenaar-rol: Backend + SRE  
- Afhankelijkheden: Worker runtime, monitoring  
- Verificatietest: Simuleer SMTP-fouten en verifieer dat domeinstatus consistent blijft en duplicates uitblijven.  
- Acceptatiecriteria:
1. Geen directe SMTP-call in request path voor kritieke flows.  
2. Retries aantoonbaar idempotent.  
3. DLQ-monitoring actief.  
- Risico bij uitstel:
30: incidentele inconsistenties; 90: terugkerende communicatiebugs; 180: reputatieschade.

---

## F-09

- ID: F-09  
- Domein: Performance en schaalbaarheid  
- Titel: Geen loadtest-benchmark of capaciteits-SLO’s  
- Feitelijke observatie: PM2 cluster is aanwezig, maar geen loadtestscripts/baselines/targets in repository.  
- Bewijs: ecosystem config, README, ops runbook  
- Feit: Wel schaalparameter WEB_CONCURRENCY, geen gemeten grenswaarden.  
- Hypothese: Latency-spikes bij rapportage of piekuren worden laat ontdekt.  
- Aanname: Productiebelasting stijgt met teamgroei.  
- Risico-uitleg: Onvoorspelbare performance, risico op timeouts en gebruikersfrictie.  
- Impact: 3  
- Kans: 4  
- Detecteerbaarheid: 4  
- Herstelcomplexiteit: 2  
- Risicoscore + klasse: 50, High  
- Root cause: Geen performance engineering-cyclus in releaseproces.  
- Aanbevolen oplossing:
1. Definieer p95 latency en throughput doelen per kritieke endpoint.  
2. Voer periodieke loadtests uit op auth, work-entry, objections, monthly-report.  
3. Leg bottlenecks vast en optimaliseer querypad/caching waar nodig.  
- Alternatieven met trade-offs:
1. Alleen productie-observatie: lage startkosten, reactief en risicovol.  
2. Volledige prestaging: robuust, duurder.  
- Effort: M  
- Eigenaar-rol: SRE + backend + DBA  
- Afhankelijkheden: Testomgeving, representatieve data  
- Verificatietest: Loadrun met afgesproken piekprofiel en pass/fail op SLO’s.  
- Acceptatiecriteria:
1. Gedocumenteerde baseline per endpoint.  
2. Reproduceerbare loadtest in pipeline of releasechecklist.  
3. Capaciteitsrapport elk kwartaal.  
- Risico bij uitstel:
30: onbekende limieten; 90: piekstoringen; 180: schaalblokkade.

---

## F-10

- ID: F-10  
- Domein: Security / Frontend-backend integriteit  
- Titel: CSRF-controle uitsluitend op origin/referer, zonder expliciet tokenmechanisme  
- Feitelijke observatie: Same-origin check is aanwezig, maar er is geen aanvullend synchronizer/double-submit token zichtbaar.  
- Bewijs: csrf service, routes  
- Feit: Controle accepteert origin of referer gelijk aan app-origin.  
- Hypothese: In complexe proxy/browser edge-cases kan bescherming minder robuust zijn.  
- Aanname: sameSite strict cookie blijft in alle clients consistent afdwingen.  
- Risico-uitleg: Verhoogd risico op moeilijk detecteerbare request-forgery-varianten in afwijkende omgevingen.  
- Impact: 3  
- Kans: 3  
- Detecteerbaarheid: 4  
- Herstelcomplexiteit: 2  
- Risicoscore + klasse: 38, Medium  
- Root cause: Keuze voor eenvoudige origin-validatie zonder expliciet anti-CSRF tokenmodel.  
- Aanbevolen oplossing:
1. Voeg CSRF token (double-submit of synchronizer) toe voor state-changing routes.  
2. Log en alarmeer CSRF-failures met request metadata.  
3. Documenteer reverse-proxy header-vereisten.  
- Alternatieven met trade-offs:
1. Huidig model behouden: lage complexiteit, lagere zekerheid.  
2. Alleen custom header check: eenvoudig, maar minder sterk dan token + origin combinatie.  
- Effort: M  
- Eigenaar-rol: Security engineer + backend  
- Afhankelijkheden: Frontend form/fetch updates  
- Verificatietest: Negatieve tests op forged requests slagen consistent in meerdere browser/proxy scenario’s.  
- Acceptatiecriteria:
1. Alle muterende endpoints vereisen geldig CSRF token.  
2. Tests dekken token rotatie en invalidatie.  
3. Falende CSRF checks zijn zichtbaar in monitoring.  
- Risico bij uitstel:
30: latent risico; 90: exploitkans stijgt bij infrawijzigingen; 180: security-auditafwijking.

---

## F-11

- ID: F-11  
- Domein: Frontend / i18n-l10n  
- Titel: Taal hardcoded Nederlands, geen i18n-architectuur  
- Feitelijke observatie: Teksten zijn direct in componenten opgenomen en niet via vertaalresources.  
- Bewijs: dashboard page, mijn uren client, gebruikers client  
- Feit: Geen i18n configuratie of locale resource structuur zichtbaar.  
- Hypothese: Opschaling naar meertalige context vereist dure refactor.  
- Aanname: Productbehoefte blijft voorlopig eentalig.  
- Risico-uitleg: Lage korte-termijn impact, hoge toekomstige veranderkosten.  
- Impact: 2  
- Kans: 3  
- Detecteerbaarheid: 2  
- Herstelcomplexiteit: 3  
- Risicoscore + klasse: 15, Low  
- Root cause: Productfocus op enkel NL-doelgroep zonder internationalisatievoorbereiding.  
- Aanbevolen oplossing:
1. Introduceer message catalog patroon.  
2. Verplaats UI-teksten naar locale files.  
3. Voeg fallback-locale tests toe.  
- Alternatieven met trade-offs:
1. Uitstellen: nu snel, later hogere migratiekosten.  
2. Hybrid aanpak alleen voor kernflows: middenweg.  
- Effort: M  
- Eigenaar-rol: Frontend + product  
- Afhankelijkheden: i18n library keuze  
- Verificatietest: Schakel locale en verifieer volledige UI-stringresolutie.  
- Acceptatiecriteria:
1. Geen hardcoded gebruikerscopy in kerncomponenten.  
2. Nieuwe features volgen i18n patroon.  
- Risico bij uitstel:
30: beperkt; 90: oplopende schuld; 180: dure refactor.

---

## F-12

- ID: F-12  
- Domein: Security / Session management  
- Titel: Sessieduur hardcoded en niet risicogebaseerd per rol  
- Feitelijke observatie: Sessietijd staat vast op 2 uur zonder role-based policy of idle timeout.  
- Bewijs: session service  
- Feit: TTL is vaste constante in code.  
- Hypothese: Privileged sessies blijven langer actief dan gewenst in gedeelde werkplekken.  
- Aanname: Geen aanvullende device controls buiten app.  
- Risico-uitleg: Vergroot kans op misbruik van onbeheerde sessies.  
- Impact: 3  
- Kans: 3  
- Detecteerbaarheid: 3  
- Herstelcomplexiteit: 2  
- Risicoscore + klasse: 29, Medium  
- Root cause: Simpel uniform sessionbeleid zonder risicoprofielen.  
- Aanbevolen oplossing:
1. Configureerbare TTL per rol.  
2. Idle timeout en re-auth voor high-risk acties.  
3. Sessierevocatiepad bij verdachte activiteit.  
- Alternatieven met trade-offs:
1. Kortere globale TTL: eenvoudiger, maar meer gebruikersfrictie.  
2. Alleen high-risk re-auth: goede balans, iets complexer.  
- Effort: S  
- Eigenaar-rol: Security engineer + backend  
- Afhankelijkheden: UX-afstemming  
- Verificatietest: Role-based TTL en idle expiry werken aantoonbaar in integratietests.  
- Acceptatiecriteria:
1. OWNER/MANAGER kortere policy dan EMPLOYEE.  
2. Re-auth verplicht voor delete/review acties.  
- Risico bij uitstel:
30: beperkt verhoogd risico; 90: herhalingskans; 180: security debt.

---

## Bevestigde sterke punten (om over-correctie te voorkomen)

1. Sterke auth-basis met bcrypt, HMAC-signing en MFA-flow:
auth service,
session service,
MFA verify.
2. Goede RBAC-afdwinging op gevoelige routes:
work entries route,
objection review route,
user delete route.
3. ATW-engine functioneel uitgewerkt met meerdere signaaltypes:
ATW engine.
4. Security headers en HTTPS-redirect aanwezig:
middleware,
next config.
5. Readiness/liveness endpoints en deployvolgorde met migraties zijn aanwezig:
ready,
health,
deploy script.

---

# E. Bug- en incidentinspectie

## Reproduceerbare bugs

1. Geen technische bug met directe crash aangetoond in aangeleverde context; lint/build zijn groen.
2. Reproduceerbare kwaliteitsbug: afwezigheid tests maakt regressies structureel ondetecteerbaar (procesbug).

## Waarschijnlijke verborgen bugs

1. Dubbele of gemiste e-mailverzending bij netwerkfouten/retries in requestpath.
2. Onverwachte ATW-uitkomsten rond grensgevallen (tijdzones, weekgrenzen, correcties) zonder regressietests.
3. Toegankelijkheidsbugs bij keyboard-only bediening op selectiemechanismen.

## Regressiegevoelige zones

1. engine.ts  
2. service.ts  
3. service.ts  
4. rate-limit.ts  
5. session.ts  

## Incident-scenario’s met impact en herstelpad

1. Scenario: Redis uitval tijdens brute-force golf.
Impact: verhoogde auth-attack surface.
Herstelpad: fail-closed policy voor auth endpoints + alert + Redis health SLO.
2. Scenario: Bezwaarcorrectie-dispuut met ontbrekende historiek.
Impact: juridisch/compliance conflict.
Herstelpad: history model + audit events + reconstructierapport.
3. Scenario: Retentiejob niet uitgevoerd.
Impact: AVG-overtreding.
Herstelpad: scheduler + monitoring + managementrapport.
4. Scenario: SMTP instabiliteit.
Impact: onvolledige communicatie (reset/report).
Herstelpad: outbox worker, retries, DLQ, dashboard.

---

# F. 360 graden optimalisatieplan

## Security hardening plan

1. Implementeer audit trail met immutable events voor high-risk acties.
2. Versterk rate limiting met expliciete degrade-policy en alarmering.
3. Voeg CSRF tokenmechanisme toe naast origin checks.
4. Introduceer role-based sessiebeleid en step-up re-auth voor kritieke mutaties.

## Performance plan

1. Definieer en meet p95 latency en throughput per kritieke API.
2. Voer loadtests periodiek uit met representatieve datasets.
3. Optimaliseer rapportage- en querypaden op basis van testresultaten.
4. Voeg capaciteitsplanning toe aan releasecyclus.

## Architectuurverbeteringen

1. Maak wijzigingshistorie first-class domeinconcept (work entry versioning).
2. Introduceer transactionele outbox voor side-effects.
3. Scheid operationele jobs explicieter van synchronous API-verkeer.

## Frontend/UX/accessibility verbeteringen

1. Herwerk interactieve patronen naar semantisch toegankelijk gedrag.
2. Voeg consistente fout- en laadstatus patronen toe met aria-live ondersteuning.
3. Definieer UX-a11y Definition of Ready en Definition of Done.

## Backend/data verbeteringen

1. Voeg audit- en history-tabellen toe met migraties.
2. Formaliseer retentieafdwinging met job state logging.
3. Definieer data quality checks voor integriteit van uren/objections.

## DevOps/observability verbeteringen

1. Structured logging met correlation ids.
2. Alerting op auth failures, queue failures, fallback events, readiness degradatie.
3. Regelmatige restore-proeven met bewijsrapport.

## Test- en kwaliteitsstrategie

1. Testpiramide invoeren:
unit voor domeinlogica, integration voor API/security, e2e voor kernflows.
2. CI quality gates:
lint, typecheck, tests, security checks, migration drift check.
3. Regressiepakket op ATW, bezwaarflow, auth, rapportage.

---

# G. Roadmap en executieplan

## Fase 1: quick wins + risicoreductie (0-30 dagen)

### Taken

1. Testfundament opzetten met kritieke smoke/integration set.
2. Alerting op Redis fallback en auth anomalies.
3. Retentiejob automatiseren en monitoren.
4. AuditEvent minimum viable implementatie voor delete/review.

### Eigenaren

1. QA lead, backend, SRE, security engineer, privacy officer.

### Benodigde capaciteit

1. 2 backend FTE-weken
2. 1 QA FTE-week
3. 1 SRE/SecOps FTE-week

### Verwachte risicoreductie

1. 30-40% op top-risico’s.

### KPI’s en meetplan

1. Test pass rate > 95% op kritieke suite.
2. MTTD security events < 5 minuten.
3. Retentiejob success ratio 100% gepland.

## Fase 2: stabilisatie en schaalbaarheid (30-90 dagen)

### Taken

1. WorkEntryHistory + immutable correctieflow.
2. Outbox + mail worker + retry/DLQ.
3. Performance baseline en loadtests.
4. A11y remediation op kernschermen.

### Eigenaren

1. Backend lead, DBA, frontend lead, SRE, QA.

### Benodigde capaciteit

1. 4-6 backend FTE-weken
2. 2 frontend FTE-weken
3. 1 QA/SRE FTE-week

### Verwachte risicoreductie

1. Extra 30% op reliability/compliance/performance risico’s.

### KPI’s en meetplan

1. Duplicate mail incidents naar 0.
2. p95 latency binnen afgesproken drempels.
3. 0 blocker a11y issues op kernflows.

## Fase 3: structurele modernisering (90-180 dagen)

### Taken

1. Volledige CI/CD quality gates en release governance.
2. Security posture volwassen maken (periodieke threat modeling, policy-as-code).
3. i18n architectuurvoorbereiding en maintainability refactors.
4. Kwartaalgewijze DR-oefeningen met formeel rapport.

### Eigenaren

1. CTO/Tech Lead, principal architect, security, SRE, QA, product.

### Benodigde capaciteit

1. 6-8 gecombineerde FTE-weken over disciplines.

### Verwachte risicoreductie

1. Naar mature baseline met voorspelbare releasekwaliteit.

### KPI’s en meetplan

1. Change failure rate < 10%.
2. MTTR < 60 minuten.
3. 100% compliance op audit controls uit Fase 1/2.

---

# H. Team-overdracht

## Actielijst per discipline

1. Principal architect: besluit over immutable urenmodel + outbox architectuur.
2. Backend: audit events, history, idempotency, CSRF token, sessiebeleid.
3. Frontend: WCAG remediatie, consistente error/loading patterns.
4. Security: fail-closed auth-throttle policy, eventdetectie, runbooks.
5. DevOps/SRE: logging, dashboards, alerting, schedulerhardening.
6. DBA/data engineer: migraties voor audit/history, restore-validaties.
7. QA/test automation: kritieke regressiesuite + CI-gates.
8. Privacy/compliance: retentiecontrole, bewijsvoering, auditrapportage.
9. Product/operations: acceptatie van SLA/KPI’s, operationele ritmes.

## Beslispunten voor CTO/Tech Lead

1. Accepteert organisatie No-Go tot Fase 1 completion?
2. Keuze voor audit/history implementatievariant.
3. Prioriteit reliability versus featureontwikkeling komende kwartaal.
4. Budget voor observability en testautomatisering.

## Blokkers en randvoorwaarden

1. Toegang tot productieobservability en infra-instellingen ontbreekt nu.
2. Geen expliciete CI-config in scope.
3. Juridische documentset (DPA/DPIA) niet beschikbaar in auditmateriaal.

## Definition of Done per werkstroom

1. Security DoD:
controls geïmplementeerd, getest, gemonitord en gedocumenteerd.
2. Backend DoD:
migraties, tests, observability en rollback-scenario gevalideerd.
3. Frontend DoD:
WCAG-checks en keyboard/screenreader validatie geslaagd.
4. Ops DoD:
alerts, runbooks en hersteltest aantoonbaar uitgevoerd.
5. Compliance DoD:
auditbewijs exporteerbaar en periodiek reviewbaar.

---

# I. Open dataverzoeken

## Exacte extra gegevens nodig voor hogere zekerheid

1. Productie-architectuurdiagram met netwerkgrenzen, TLS-terminatie, Redis/MySQL exposure.
2. CI/CD-configuratie en laatste pipeline-runs inclusief security gates.
3. Logging/monitoring stackdetails met alertregels en incidenthistorie.
4. Restore-testverslagen inclusief RTO/RPO-resultaten.
5. DPA’s met hosting en SMTP-subprocessor, plus DPIA-status.
6. Productieverkeersprofiel (requests/min, piekvensters, gebruikersgroei).
7. Pen-test of externe security reviewresultaten.

## Verwachte impact van die data op audituitkomst

1. Securityscore kan 5-15 punten omhoog of omlaag afhankelijk van infra-hardening bewijs.
2. DevOps/SRE-score kan 10-20 punten omhoog bij aantoonbare alerting en restore-discipline.
3. Complianceclassificatie van meerdere bevindingen kan dalen van Critical/High naar Medium bij verifieerbare governance-controls.
4. Performance-risico kan herclassificeren na harde loadtestdata en SLO-metingen.

---

Interne kwaliteitscontrole uitgevoerd op dit rapport:

1. Kritieke claims zijn onderbouwd met controleerbaar bewijs of expliciet gelabeld als Onvoldoende bewijs/Hypothese/Aanname.
2. Adviezen zijn concreet, stapbaar en voorzien van verificatie en acceptatiecriteria.
3. Prioritering volgt het opgegeven scoringsmodel en klassen.
4. Uitvoering kan direct starten via hoofdstuk G en H zonder aanvullende interpretatielaag.