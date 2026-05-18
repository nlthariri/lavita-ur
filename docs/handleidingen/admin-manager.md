# Handleiding Admin / Manager — LaVita Urenregistratie

**Versie:** 1.0  
**Datum:** 2026-05-16  
**Doelgroep:** Organisatie-eigenaar (owner/admin) en teamleider (manager)  
**Taalniveau:** B1

---

## Inhoudsopgave

1. [Inloggen en MFA-setup](#1-inloggen-en-mfa-setup)
2. [Dashboard](#2-dashboard)
3. [Accountbeheer](#3-accountbeheer)
4. [Urenregistratie — Weekoverzicht](#4-urenregistratie--weekoverzicht)
5. [Uren invoeren en bewerken](#5-uren-invoeren-en-bewerken)
6. [Bezwaarafhandeling](#6-bezwaarafhandeling)
7. [Rapportages en export](#7-rapportages-en-export)
8. [Projectbeheer](#8-projectbeheer)
9. [Kostenplaatsen](#9-kostenplaatsen)
10. [E-mailtemplates beheren](#10-e-mailtemplates-beheren)
11. [ATW-dashboard](#11-atw-dashboard)
12. [Verlof, ziekte en feestdagen](#12-verlof-ziekte-en-feestdagen)
13. [Week kopiëren](#13-week-kopiëren)
14. [Veelgestelde vragen](#14-veelgestelde-vragen)
15. [Support en contact](#15-support-en-contact)

---

## 1. Inloggen en MFA-setup

### Stap 1 — Ga naar de inlogpagina

Open uw browser en ga naar de URL van uw organisatie (bijvoorbeeld `https://uren.lavita.nl/inloggen`).

<!-- Screenshot: inlogscherm met e-mail- en wachtwoordvelden -->
![Inlogscherm](../screenshots/login-scherm.png)

### Stap 2 — Voer uw gegevens in

1. Typ uw **e-mailadres** in het eerste veld.
2. Typ uw **wachtwoord** in het tweede veld.
3. Klik op **Inloggen**.

### Stap 3 — MFA-verificatie (tweestapsverificatie)

Na het inloggen verschijnt een scherm voor tweestapsverificatie.

1. Open uw authenticator-app (bijvoorbeeld Google Authenticator of Microsoft Authenticator).
2. Voer de **6-cijferige code** in die de app toont.
3. Klik op **Verifiëren**.

<!-- Screenshot: MFA-verificatiescherm met invoerveld voor 6-cijferige code -->
![MFA-verificatie](../screenshots/mfa-verificatie.png)

### Eerste keer inloggen — QR-code scannen

Bij uw eerste inlog moet u MFA instellen:

1. Scan de **QR-code** met uw authenticator-app.
2. Bewaar de **8 herstelcodes** op een veilige plek (bijvoorbeeld in een kluis of wachtwoordmanager).
3. Voer de eerste code in om te bevestigen.

<!-- Screenshot: QR-code setup met herstelcodes -->
![MFA-setup](../screenshots/mfa-setup-qr.png)

> **Let op:** MFA is verplicht voor admin- en manageraccounts. Zonder MFA kunt u niet inloggen.

---

## 2. Dashboard

Na het inloggen komt u op het managementdashboard.

<!-- Screenshot: dashboard met kaarten voor aanwezigheid, bezwaren, ATW-status -->
![Dashboard](../screenshots/dashboard-manager.png)

Het dashboard toont:

| Onderdeel | Wat ziet u |
|---|---|
| **Aanwezigheid deze week** | Hoeveel medewerkers uren hebben ingevoerd |
| **Openstaande bezwaren** | Aantal bezwaren dat nog beoordeeld moet worden |
| **ATW-waarschuwingen** | Aantal medewerkers met een ATW-signaal |
| **Snelkoppelingen** | Directe links naar weekoverzicht en rapportages |

---

## 3. Accountbeheer

### Accounts bekijken

1. Klik in het menu op **Accounts**.
2. U ziet een lijst van alle medewerkers in uw organisatie (manager: alleen uw team).
3. Gebruik het **zoekveld** om snel een medewerker te vinden.

<!-- Screenshot: accountlijst met zoekbalk en tabel -->
![Accountlijst](../screenshots/accounts-lijst.png)

### Nieuw account aanmaken

1. Klik op **+ Nieuw account**.
2. Vul de velden in:
   - **Volledige naam** (verplicht)
   - **E-mailadres** (verplicht, uniek)
   - **Rol** — kies uit: medewerker, manager, boekhouder (alleen owner kan admin aanmaken)
   - **Team** — selecteer het team (niet verplicht voor boekhouder)
   - **Startdatum dienstverband** (optioneel, voor jubileumnotificaties)
3. Klik op **Opslaan**.

De medewerker ontvangt automatisch een welkomstmail met een link om een wachtwoord in te stellen.

<!-- Screenshot: formulier nieuw account -->
![Account aanmaken](../screenshots/account-aanmaken.png)

### Account bewerken

1. Klik op de naam van een medewerker in de lijst.
2. Pas de gewenste velden aan.
3. Klik op **Opslaan**.

### Account deactiveren of verwijderen

- **Deactiveren:** Zet de schakelaar "Actief" uit. De medewerker kan niet meer inloggen, maar gegevens blijven bewaard.
- **Verwijderen (alleen owner):** Klik op **Verwijderen**. Het account wordt gepseudonimiseerd conform AVG. Uren blijven 7 jaar bewaard.

> **Let op:** Verwijderen is niet mogelijk als er nog openstaande bezwaren zijn.

---

## 4. Urenregistratie — Weekoverzicht

### Het weekoverzicht openen

1. Klik in het menu op **Uren** → **Weekoverzicht**.
2. U ziet een tabel met:
   - **Rijen:** medewerkers (manager: alleen uw team)
   - **Kolommen:** maandag t/m zondag
   - **Cellen:** statusbadges (vastgesteld, concept, bezwaar, leeg, feestdag)

<!-- Screenshot: weekoverzicht met statusbadges in kleuren -->
![Weekoverzicht](../screenshots/weekoverzicht-admin.png)

### Navigeren tussen weken

- Gebruik de **pijlknoppen** links en rechts van de weekaanduiding.
- Of klik op de **datum** om een specifieke week te kiezen.

### Statusbadges begrijpen

| Badge | Kleur | Betekenis |
|---|---|---|
| Vastgesteld | Groen | Uren zijn definitief |
| Concept | Grijs | Uren zijn nog niet bevestigd |
| Bezwaar | Geel | Medewerker heeft bezwaar ingediend |
| Leeg | Geen badge | Geen uren ingevoerd |
| Feestdag | Grijs met tooltip | Nationale feestdag |

---

## 5. Uren invoeren en bewerken

### Nieuwe uren invoeren

1. Klik op een **lege cel** in het weekoverzicht.
2. Het invoervenster opent met de volgende velden:
   - **Begintijd** (verplicht)
   - **Eindtijd** (verplicht)
   - **Pauze in minuten** (verplicht)
   - **Project** (optioneel)
   - **Kostenplaats** (optioneel)
   - **Opmerking** (optioneel)
3. De **netto-minuten** worden automatisch berekend terwijl u typt.
4. Vóór opslaan controleert het systeem de ATW-regels:
   - **Geel waarschuwingsbericht:** u mag opslaan, maar er is een aandachtspunt.
   - **Rood foutbericht:** u kunt niet opslaan (bijvoorbeeld: meer dan 60 uur per week).
5. Klik op **Opslaan**.

<!-- Screenshot: invoermodal met live netto-minuten en ATW-waarschuwing -->
![Uren invoeren](../screenshots/invoer-modal.png)

### Bestaande uren bewerken

1. Klik op een **gevulde cel** in het weekoverzicht.
2. Pas de velden aan.
3. Klik op **Opslaan**.

> **Let op:** Bewerken is niet mogelijk als er een openstaand bezwaar op de regel staat.

### Uren verwijderen

1. Open een bestaande urenregel.
2. Klik op **Verwijderen** (rode knop onderaan).
3. Bevestig de verwijdering.

De medewerker ontvangt een e-mail over de wijziging of verwijdering.

---

## 6. Bezwaarafhandeling

### Openstaande bezwaren bekijken

1. Klik in het menu op **Bezwaren** of op de teller op het dashboard.
2. U ziet een lijst van alle openstaande bezwaren.

<!-- Screenshot: bezwarenlijst met status en datum -->
![Bezwarenlijst](../screenshots/bezwaren-lijst.png)

### Een bezwaar beoordelen

1. Klik op een bezwaar om het te openen.
2. Lees de motivatie van de medewerker.
3. Schrijf uw eigen **motivatie** (minimaal 10 tekens, maximaal 1000 tekens).
4. Kies:
   - **Akkoord** — het bezwaar wordt geaccepteerd en de werkregel kan worden aangepast.
   - **Afwijzen** — het bezwaar wordt afgewezen en de werkregel blijft ongewijzigd.
5. Klik op **Versturen**.

<!-- Screenshot: bezwaar-beoordelingsformulier met motivatieveld -->
![Bezwaar beoordelen](../screenshots/bezwaar-beoordelen.png)

> **Let op:** De knop "Versturen" is pas klikbaar als uw motivatie minimaal 10 tekens bevat.

---

## 7. Rapportages en export

### Rapportages openen

1. Klik in het menu op **Rapportages**.
2. Stel de filters in:
   - **Medewerker** (optioneel)
   - **Team** (optioneel)
   - **Project** (optioneel)
   - **Kostenplaats** (optioneel)
   - **Periode** (van-datum en tot-datum)

<!-- Screenshot: rapportagefilters met download-knoppen -->
![Rapportages](../screenshots/rapportages-filters.png)

### Exporteren

- Klik op **Download PDF** voor een PDF-rapport.
- Klik op **Download Excel** voor een Excel-bestand.

### Jaaroverzicht (fiscale export)

1. Ga naar het tabblad **Jaaroverzicht**.
2. Selecteer het **jaar** en eventueel een **medewerker**.
3. Klik op **Exporteren** voor een PDF met het volledige jaaroverzicht.

---

## 8. Projectbeheer

> **Alleen beschikbaar voor owner/admin.**

### Projecten bekijken

1. Klik in het menu op **Projecten**.
2. U ziet een lijst van alle projecten in uw organisatie.

<!-- Screenshot: projectenlijst met code, naam en status -->
![Projectenlijst](../screenshots/projecten-lijst.png)

### Nieuw project aanmaken

1. Klik op **+ Nieuw project**.
2. Vul in:
   - **Code** (verplicht, uniek binnen uw organisatie)
   - **Naam** (verplicht)
   - **Omschrijving** (optioneel)
   - **Uurtarief** (optioneel, voor kostprijsberekening)
3. Klik op **Opslaan**.

### Project archiveren

1. Open een project.
2. Klik op **Archiveren**.
3. Het project is niet meer beschikbaar voor nieuwe urenregels, maar bestaande koppelingen blijven bewaard.

---

## 9. Kostenplaatsen

> **Alleen beschikbaar voor owner/admin.**

Het beheer van kostenplaatsen werkt identiek aan projectbeheer:

1. Klik in het menu op **Kostenplaatsen**.
2. Maak nieuwe kostenplaatsen aan met een unieke **code** en **naam**.
3. Archiveer kostenplaatsen die niet meer nodig zijn.

<!-- Screenshot: kostenplaatsenlijst -->
![Kostenplaatsen](../screenshots/kostenplaatsen-lijst.png)

---

## 10. E-mailtemplates beheren

> **Alleen beschikbaar voor owner/admin.**

### Templates bekijken en bewerken

1. Klik in het menu op **Instellingen** → **E-mailtemplates**.
2. U ziet een lijst van alle 11 e-mailtypes:
   - Welkomstmail
   - Wachtwoord-reset
   - Werkregel vastgesteld
   - Werkregel gewijzigd
   - Werkregel verwijderd
   - Bezwaar beoordeeld
   - ATW-waarschuwing
   - ATW-kritiek
   - Herinnering openstaande invoer
   - Maandrapportage
   - Jubileum
3. Klik op een template om deze te bewerken.
4. Gebruik de beschikbare **placeholders** (worden getoond boven het tekstveld).
5. Klik op **Opslaan**.

<!-- Screenshot: e-mailtemplate-editor met placeholders -->
![E-mailtemplates](../screenshots/email-templates-editor.png)

> **Tip:** Test een template door een testmail te versturen naar uzelf voordat u wijzigingen opslaat.

---

## 11. ATW-dashboard

### Het ATW-dashboard openen

1. Klik in het menu op **ATW** of op de ATW-teller op het dashboard.
2. U ziet een overzicht per medewerker met kolommen voor elke ATW-limiet.

<!-- Screenshot: ATW-dashboard met kleurcodes per medewerker -->
![ATW-dashboard](../screenshots/atw-dashboard.png)

### Kleurcodes begrijpen

| Kleur | Betekenis |
|---|---|
| **Groen** | Binnen de norm |
| **Geel** | Waarschuwing (bijvoorbeeld: 48–60 uur per week) |
| **Rood** | Kritiek — limiet bereikt of overschreden |

### Kolommen

| Kolom | ATW-regel |
|---|---|
| Dag | Maximaal 12 uur per dienst |
| Week | Waarschuwing bij 48u, hard maximum 60u |
| 16 weken | Gemiddelde maximaal 48u per week |
| Rust | Minimaal 11 uur rust tussen diensten |
| Pauze | Minimaal 30 minuten bij meer dan 5,5 uur werken |

---

## 12. Verlof, ziekte en feestdagen

### Verlof of ziekte registreren

1. Klik in het menu op **Verlof**.
2. Selecteer het **type**:
   - **Ziekte** (beschikbaar voor alle rollen)
   - **Verlof** (beschikbaar voor alle rollen)
   - **Feestdag** (alleen voor manager en owner)
3. Kies de **datum** of **datumreeks**.
4. Voeg eventueel een **toelichting** toe.
5. Klik op **Opslaan**.

<!-- Screenshot: verlofformulier met type-selectie en datumkiezer -->
![Verlof registreren](../screenshots/verlof-formulier.png)

> **Let op:** Verlof- en ziekteregels tellen niet mee voor de ATW-werktijdberekening.

### Feestdagen in het weekoverzicht

Nationale feestdagen worden automatisch als grijze cel getoond in het weekoverzicht. Beweeg uw muis over de cel om de naam van de feestdag te zien.

---

## 13. Week kopiëren

### Een werkweek kopiëren naar de volgende week

1. Ga naar het **Weekoverzicht**.
2. Klik op **Week kopiëren** (knop rechtsboven).
3. Selecteer:
   - **Medewerker**
   - **Bronweek** (de week die u wilt kopiëren)
   - **Doelweek** (de week waarnaar u wilt kopiëren)
4. Klik op **Kopiëren**.

Het systeem kopieert alle werkregels van type "WORK" naar de doelweek. Regels die een conflict veroorzaken (dubbele invoer of ATW-overtreding) worden overgeslagen en getoond in een overzicht.

---

## 14. Veelgestelde vragen

### Waarom kan ik een werkregel niet bewerken?

Er staat waarschijnlijk een openstaand bezwaar op deze regel. Handel eerst het bezwaar af.

### Waarom krijg ik een rode foutmelding bij het opslaan van uren?

Het systeem controleert de Arbeidstijdenwet. Mogelijke oorzaken:
- Meer dan 12 uur op één dag
- Meer dan 60 uur in één week
- Minder dan 11 uur rust tussen twee diensten
- Minder dan 30 minuten pauze bij meer dan 5,5 uur werken

### Kan ik een verwijderd account herstellen?

Nee. Na verwijdering worden persoonsgegevens gepseudonimiseerd conform de AVG. De uren blijven 7 jaar bewaard voor fiscale doeleinden.

### Hoe wijzig ik mijn eigen wachtwoord?

Ga naar **Instellingen** → **Wachtwoord wijzigen**, of gebruik de link "Wachtwoord vergeten" op het inlogscherm.

### Wat betekent de gele waarschuwing bij het invoeren van uren?

Een gele waarschuwing betekent dat de medewerker tussen 48 en 60 uur per week werkt. U kunt de uren nog opslaan, maar houd de werkdruk in de gaten.

### Hoe exporteer ik gegevens voor de belastingdienst?

Ga naar **Rapportages** → tabblad **Jaaroverzicht**. Selecteer het jaar en klik op **Exporteren** voor een PDF.

### Waarom ontvangt een medewerker geen herinneringsmails?

De medewerker heeft mogelijk de optie "E-mail herinneringen ontvangen" uitgezet in de accountinstellingen. U kunt dit controleren bij het bewerken van het account.

---

## 15. Support en contact

Bij vragen of problemen kunt u contact opnemen met:

- **Interne helpdesk:** Neem contact op met uw systeembeheerder binnen de organisatie.
- **Technische support:** Stuur een e-mail naar het supportadres dat is geconfigureerd door uw organisatie.
- **Documentatie:** Raadpleeg de technische documentatie in `docs/technical/` voor gedetailleerde informatie over het systeem.

### Storingen melden

1. Noteer wat u deed toen het probleem optrad.
2. Noteer de foutmelding (indien zichtbaar).
3. Maak een screenshot.
4. Stuur deze informatie naar uw systeembeheerder.

---

*Einde handleiding Admin / Manager*
