**OPDRACHTSPECIFICATIE**
Opdrachtgever:
La Vita
Vestigingsnummer: 000051651130
Synagoge-passage 2, 9671EC Winschoten
KVK-nummer: 85616621
info@la-vitatrading.nl
+31629173457
Loonheffingennummer: 313322892L01
Eigenaar: Khder Alabed

**Urenregistratie Webapplicatie**

Ten behoeve van aanbesteding bij IT-dienstverlener

| Opdrachtgever | **\[Bedrijfsnaam\]**                         |
| ------------- | -------------------------------------------- |
| Versie        | **1.1 - herzien na feedbacksessie mei 2025** |
| Datum         | **Mei 2025**                                 |
| Classificatie | **Vertrouwelijk**                            |

Dit document dient als basis voor aanbesteding of offerteverzoek. Vraag leveranciers om per punt expliciet te bevestigen of af te wijken, met onderbouwing. Laat het definitieve contract reviewen door een juridisch adviseur gespecialiseerd in IT-contracten en AVG.

# **1\. Projectdoelstelling**

Opdrachtgever wenst een professionele, Nederlandse-wetgeving-conforme webapplicatie voor de digitale registratie van arbeidstijden. De applicatie vervangt de huidige papieren of spreadsheet-gebaseerde tijdregistratie volledig.

Kern van het systeem: eigenaren en managers voeren de werkuren in voor hun medewerkers. Een ingevoerde registratie is direct vastgesteld - er is geen aparte goedkeuringsstap. Medewerkers kunnen hun eigen urenstaat inzien en hebben de mogelijkheid een gemotiveerd bezwaar in te dienen. Het systeem bewaakt automatisch de geldende arbeidstijdenwetgeving en verstuurt geautomatiseerde e-mailnotificaties.

Ambitie: voldoen aan - en waar mogelijk overtreffen van - alle geldende wet- en regelgeving op het gebied van arbeidsrecht, privacy en digitale toegankelijkheid.

# **2\. Juridisch & wettelijk kader**

De applicatie moet aantoonbaar voldoen aan de volgende wet- en regelgeving:

| **ATW** | Arbeidstijdenwet - Automatische signalering bij overschrijding van maximale werktijden (12 uur/dag, 60 uur/week, gemiddeld 48 uur over 16 weken). Verplichte bewaking minimale rusttijd van 11 uur tussen diensten. Pauzeregistratie verplicht. |
| ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |

| **AVG** | Algemene Verordening Gegevensbescherming - Doelbinding, dataminimalisatie, recht op inzage/correctie/verwijdering. Verwerkersovereenkomst (VWO) met IT-leverancier verplicht. Gegevens mogen niet buiten de EER worden opgeslagen. |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |

| **WOR** | Wet op de ondernemingsraden - Bij >50 medewerkers heeft de ondernemingsraad instemmingsrecht over de inzet van personeelsvolgsystemen (art. 27 WOR). Documenteer dit proces aantoonbaar. |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |

| **Fiscaal** | Belastingdienst bewaarplicht - Urenstaten en gerelateerde administratie moeten minimaal 7 jaar bewaard blijven. Exportfunctie naar gangbare formaten (PDF, Excel) verplicht. |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |

| **ISO** | NEN-ISO/IEC 27001 & NCSC-richtlijnen - Aanbevolen certificering voor informatiebeveiliging. Wachtwoordbeleid conform NCSC. MFA verplicht voor admin en manager. |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |

| **WCAG** | WCAG 2.1 niveau AA - Digitale toegankelijkheid. Alle interfaces moeten screenreader-compatibel en volledig toetsenbord-bedienbaar zijn. |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------- |

# **3\. Gebruikersrollen & rechten**

| **Rol**           | **Bevoegdheden**                                                                                                                                                     | **Beperkingen**                                                  |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| Eigenaar / Admin  | Volledig beheer: alle medewerkers, uren invoeren/bewerken/verwijderen, rapportages, exporteren, e-mailcycli instellen, ATW-limieten configureren, accounts aanmaken. | -                                                                |
| Manager           | Uren invoeren voor eigen team, overzichten eigen afdeling inzien, medewerkers aan eigen team toevoegen, bezwaren beoordelen.                                         | Geen verwijderrecht medewerkers, geen toegang andere afdelingen. |
| Medewerker        | Eigen urenstaat inzien, bezwaar indienen op vastgestelde uren (met motivatie).                                                                                       | Geen invoerrecht. Geen inzage andermans gegevens.                |
| Boekhouder (opt.) | Alleen-lezen toegang tot alle uren en exportfunctie.                                                                                                                 | Geen bewerkrechten.                                              |

# **4\. Functionele eisen**

## **4.1 Urenstaat invoer (kern)**

Eigenaren en managers vullen de uren in aan het einde van de dag of achteraf. De registratie is na opslaan direct vastgesteld - geen aparte goedkeuringsstap nodig.

- Invoervelden: medewerker (keuzelijst), datum, begintijd, eindtijd, pauze.
- Pauze via standaard keuzelijst: geen pauze / 30 minuten (standaard) / 45 minuten / 60 minuten (wettelijk verplicht bij >5,5 uur) / eigen invoer.
- Netto werktijd wordt automatisch berekend en direct getoond bij invoer.
- Koppeling aan project of kostenplaats (keuzelijst, beheerd door admin).
- Terugwerkende kracht invoer mogelijk voor willekeurige datum.
- Herhaalfunctie: vaste roosterpatronen kopieerbaar naar volgende week of aangepaste periode.
- Invoer van ziekte, verlof en feestdagen (NL-kalender automatisch ingeladen).

## **4.2 Bezwaarprocedure medewerker**

De medewerker heeft geen invoerbevoegdheid maar kan altijd bezwaar indienen op vastgestelde uren.

- Eigenaar/manager legt uren vast - direct vastgesteld, zichtbaar voor medewerker.
- Medewerker ziet urenstaat en kan per invoer een bezwaar indienen met verplichte motivatie.
- Systeem verstuurt automatisch een e-mailmelding aan de verantwoordelijk manager/eigenaar.
- Manager beoordeelt bezwaar: akkoord gaan (uren worden gecorrigeerd) of afwijzen (met schriftelijke motivatie).
- Medewerker ontvangt automatische e-mailmelding van de uitkomst.
- Status bezwaar zichtbaar in eigen urenstaat medewerker.

## **4.3 Automatische e-mailcycli (volledig)**

Het systeem beschikt over een volledig geconfigureerde e-mailautomatisering. Alle mails zijn qua tekst aanpasbaar door de admin.

| **Triggermoment**                      | **Ontvanger(s)**             | **Inhoud mail**                                                                    |
| -------------------------------------- | ---------------------------- | ---------------------------------------------------------------------------------- |
| Uren vastgesteld voor medewerker       | Medewerker                   | Overzicht van de ingevoerde dag, link naar urenstaat, instructie bezwaar indienen. |
| Bezwaar ingediend door medewerker      | Manager + Admin              | Naam medewerker, datum, reden bezwaar, directe link naar beoordelen.               |
| Bezwaar beoordeeld (akkoord/afwijzing) | Medewerker                   | Uitkomst bezwaar, eventuele toelichting manager, link naar bijgewerkte urenstaat.  |
| Herinnering openstaande invoer         | Manager (configureerbaar)    | Automatisch na X dagen geen invoer voor team, configureerbaar tijdstip.            |
| ATW-waarschuwing naderende limiet      | Admin + betrokken Manager    | Naam medewerker, huidige weekuren, resterende ruimte tot limiet.                   |
| ATW-overschrijding geconstateerd       | Admin + Manager + Medewerker | Urgente melding: overschreden limiet, type (dag/week/16-weeks), actie vereist.     |
| Nieuw account aangemaakt               | Nieuwe medewerker            | Inloggegevens, tijdelijk wachtwoord, instructielink.                               |
| Maandrapportage (optioneel)            | Admin / Manager              | Automatisch gegenereerde PDF-bijlage met urenstaatoverzicht afgelopen maand.       |
| Wachtwoord vergeten / reset            | Betreffende gebruiker        | Beveiligde resetlink, geldig 24 uur.                                               |

## **4.4 ATW-bewaking (automatisch)**

- Waarschuwing bij ≥12 uur geregistreerd op één dag.
- Waarschuwing bij ≥48 uur geregistreerd in één week.
- Rode vlag bij overschrijding 48 uur gemiddeld (berekend over lopende 16-wekelijkse periode).
- Automatische controle minimale rusttijd: minder dan 11 uur tussen twee diensten triggert een melding.
- ATW-statusdashboard voor admin: actueel overzicht alle medewerkers, kleurcodering per limiettype.
- Waarschuwingen worden proactief getoond bij het invoeren (nog vóór opslaan).

## **4.5 Rapportages & exports**

- Urenstaten per medewerker, team, project en periode - downloadbaar als PDF en Excel.
- Jaaroverzicht per medewerker voor fiscale bewaarplicht (7-jaarsarchief).
- Managementdashboard: realtime weekoverzicht aanwezigheid, openstaande bezwaren, ATW-status.
- Kostenoverzicht (optioneel): uren × uurtarief per project/medewerker.
- Automatische maandrapportage per e-mail (configureerbaar aan/uit per gebruikersrol).

## **4.6 Extra functies (Could)**

- Nederlandse feestdagenkalender automatisch ingeladen per jaar, zichtbaar in weekoverzicht.
- Uurtarief per medewerker of project instellen voor kostprijsberekening.
- Jubileumnotificatie: automatische mail bij 1, 5, 10, 25 jaar dienstverband (configureerbaar).
- Notitieveld per dag-invoer voor intern gebruik door manager.
- Team-/afdelingsoverzicht: manager ziet in één scherm alle medewerkers van zijn team voor de lopende week.

# **5\. Technische architectuur-eisen**

| **Onderwerp**      | **Eis**                                                                                                                                                                                                             |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hosting            | Cloud-hosted, serverlocatie uitsluitend binnen de EER (bij voorkeur Nederland). SLA minimaal 99,9% uptime. Geen persoonsgegevens buiten de EER.                                                                     |
| Authenticatie      | MFA verplicht voor eigenaar en manager. Wachtwoordbeleid conform NCSC-richtlijnen (minimaal 12 tekens, geen verplichting tot periodiek wijzigen). Ondersteuning SSO via Microsoft 365 of Google Workspace (Should). |
| Versleuteling      | TLS 1.3 in transit. AES-256 at rest. Persoonsgegevens versleuteld opgeslagen in database.                                                                                                                           |
| Mobiel             | Volledig responsive webapplicatie, optimaal bruikbaar op smartphone en tablet (PWA aanbevolen). Geen native app vereist in v1.                                                                                      |
| E-mail             | Betrouwbare e-mailverzending via professionele SMTP-service (bijv. SendGrid, Postmark, AWS SES). Volledige e-mailteksten aanpasbaar door admin. Alle mails voorzien van afmeldoptie conform AVG.                    |
| Backups            | Dagelijkse versleutelde backups, minimaal 30 dagen retentie. Hersteltest minimaal éénmaal per jaar aantoonbaar uitgevoerd en gedocumenteerd.                                                                        |
| Broncode           | Broncode eigendom opdrachtgever of escrow-regeling verplicht bij contractduur >2 jaar. Bij faillissement leverancier: toegang tot broncode gegarandeerd binnen 30 dagen.                                            |
| Geen REST API (v1) | Externe API-koppelingen (AFAS, Exact, Loket.nl, etc.) zijn expliciet buiten scope van versie 1. Exportfunctie via PDF/Excel is voldoende.                                                                           |
| Offline modus      | Niet vereist in versie 1. Stabiele internetverbinding is de aanname.                                                                                                                                                |
| Audit trail        | Geen technisch audit trail vereist. Wel wettelijk vereiste bewaartermijn voor urenstaten (7 jaar).                                                                                                                  |
| Toegankelijkheid   | WCAG 2.1 niveau AA. Auditrapport door onafhankelijke partij verplicht bij oplevering.                                                                                                                               |

# **6\. Privacy & AVG-compliance**

- Leverancier treedt op als verwerker - ondertekende verwerkersovereenkomst (VWO) is een harde voorwaarde bij contractondertekening.
- Datalekprocedure vastgelegd: leverancier meldt een datalek binnen 24 uur aan opdrachtgever, zodat opdrachtgever de wettelijke melding bij de Autoriteit Persoonsgegevens (binnen 72 uur) kan doen.
- Recht op verwijdering: bij uitdiensttreding kan een medewerker of de admin een verwijderverzoek indienen. Systeem ondersteunt pseudonimisering na afloop van de fiscale bewaartermijn (7 jaar).
- Privacy by design: geen locatietracking, geen gedragsanalyse buiten de kerntaak tijdregistratie. Geen gebruik van gegevens voor profilering of advertenties.
- Doelbinding: gegevens worden uitsluitend gebruikt voor tijdregistratie en loonadministratie van opdrachtgever. Geen delen met derden zonder toestemming.
- E-mailcommunicatie: alle automatische mails moeten een opt-out bevatten voor niet-essentiële meldingen (bijv. maandrapportages). Verplichte systeemmails (ATW-waarschuwingen, bezwaarnotificaties) zijn hiervan vrijgesteld.

# **7\. Design & gebruikerservaring**

De applicatie hanteert een Cal.com-geïnspireerd designsysteem: wit canvas, zwarte primary CTA-elementen, lichtgrijze kaartoppervlakken en een donkere footer. De interface is herkenbaar professioneel, sober en modern - geen kleurrijke SaaS-marketing-look, maar een werkgereedschap dat vertrouwen uitstraalt.

| **Design token** | **Waarde & toepassing**                                                                    |
| ---------------- | ------------------------------------------------------------------------------------------ |
| Canvas           | #FFFFFF - standaard paginaachtergrond.                                                     |
| Primary CTA      | #111111 (near-black) - alle primaire actieknoppen, h1/h2 koppen.                           |
| Surface card     | #F5F5F5 - lichtgrijze kaarten voor feature-blokken, statistieken.                          |
| Knoppen          | Border-radius 8px, Inter 600, hoogte 40px, near-black achtergrond.                         |
| Kaarten          | Border-radius 12px (content), 16px (hero-mockup), 0.5px border #E5E7EB.                    |
| Typografie       | Display-koppen: Inter 700 met negatieve letter-spacing. Body: Inter 400. Geen mengeling.   |
| Status badges    | Vastgesteld = groen (#DCFCE7/#166534), Bezwaar = amber (#FEF9C3/#854D0E), Concept = grijs. |
| Footer           | #101010 donker - enige donkere zone op elke pagina.                                        |
| Responsive       | Volledig mobile-first. Feature-grids: 3-up desktop, 2-up tablet, 1-up mobiel.              |

Kernschermen die ontworpen en getest moeten worden:

- Inlogscherm (MFA-flow inbegrepen)
- Weekoverzicht (admin/manager): tabel alle medewerkers, week, status per dag
- Invoerenmodal: medewerker kiezen, datum, begin/eindtijd, pauze keuzelijst, project, netto berekening
- Medewerker-urenstaat: eigen weekoverzicht + bezwaarknop per regel
- ATW-dashboard: kleurcodeoverzicht alle medewerkers
- E-mailcycli beheer: alle automatische mails configureren qua tekst en triggers
- Rapportages en exportscherm

# **8\. Prioriteitenmatrix (MoSCoW)**

| **MUST**       | Urenstaat invoer (start/eind/pauze keuzelijst), gebruikersbeheer (rollen + accounts), direct vaststellen bij invoer, bezwaarprocedure medewerker, ATW-bewaking (dag/week/16-weken/rusttijd), volledig e-mailautomatiseringssysteem, AVG-verwerkersovereenkomst, PDF/Excel export, 7-jaarsarchief urenstaten, MFA voor admin/manager, WCAG 2.1 AA, projectkoppeling. |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
|                |
| **SHOULD**     | Verlof & ziekteregistratie, managementdashboard weekoverzicht, eigen urenstaat medewerker inzien, automatische maandrapportage per mail, ATW-statusdashboard, NL feestdagenkalender automatisch ingeladen, notitieveld per invoer, team-/afdelingsoverzicht manager.                                                                                                |
|                |
| **COULD**      | Uurtarief × uren kostprijsoverzicht, SSO via Microsoft 365 / Google Workspace, jubileumnotificaties (1/5/10/25 jaar), PWA (offline-klaar maar zonder offline-modus).                                                                                                                                                                                                |
|                |
| **WON'T (v1)** | Live klok / real-time check-in, audit trail van wijzigingen, externe koppelingen (AFAS, Exact, Loket.nl), REST API, offline modus, native iOS/Android app, biometrische registratie, GPS-klokken.                                                                                                                                                                   |

# **9\. Deliverables & acceptatiecriteria**

De opdracht is compleet opgeleverd wanneer alle onderstaande punten zijn afgerond en gedocumenteerd:

- Werkende webapplicatie conform alle eisen uit dit document, getest op Chrome, Firefox, Edge en Safari (desktop + mobiel).
- AVG-verwerkersovereenkomst (VWO) ondertekend vóór of bij oplevering.
- WCAG 2.1 AA auditrapport opgesteld door een onafhankelijke partij.
- Penetratietest (pentest) rapport opgesteld vóór go-live.
- Technische documentatie: datamodel, infrastructuuroverzicht, beschrijving e-mailsysteem.
- Gebruikershandleidingen in het Nederlands: één voor admin/manager, één voor medewerker.
- Onboardingssessie (online of op locatie) voor beheerders.
- 3 maanden garantieperiode na go-live voor alle Must-functionaliteit.

# **10\. Contractuele aandachtspunten**

- Eigendom broncode en alle data ligt te allen tijde bij opdrachtgever.
- SLA: 99,9% uptime (gemeten per kalendermaand), responstijd kritieke bugs <4 uur, overige bugs <2 werkdagen.
- Data-export bij beëindiging contract: leverancier levert binnen 30 dagen een volledige export in open formaat (CSV/JSON/Excel). Geen data-hostage.
- Transparante prijsstructuur: geen verborgen kosten per extra gebruiker boven contractaantal.
- Escrow broncode verplicht bij contractduur >2 jaar.
- Verwerkersovereenkomst conform AVG, inclusief procedure bij datalek (melding binnen 24 uur aan opdrachtgever).
- Leverancier informeert opdrachtgever minimaal 60 dagen van tevoren over beëindiging van dienst of overname.

Dit document is opgesteld als onderdeel van een aanbestedingsprocedure. Vraag leveranciers om per punt expliciet te bevestigen of gemotiveerd af te wijken. Het definitieve contract dient te worden gereviewed door een juridisch adviseur gespecialiseerd in IT-contracten en de AVG.