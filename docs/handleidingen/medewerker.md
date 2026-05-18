# Handleiding Medewerker — LaVita Urenregistratie

**Versie:** 1.0  
**Datum:** 2026-05-16  
**Doelgroep:** Medewerker (employee)  
**Taalniveau:** B1

---

## Inhoudsopgave

1. [Inloggen en MFA-setup](#1-inloggen-en-mfa-setup)
2. [Uw urenstaat bekijken](#2-uw-urenstaat-bekijken)
3. [Bezwaar indienen](#3-bezwaar-indienen)
4. [Verlof en ziekte registreren](#4-verlof-en-ziekte-registreren)
5. [Uw gegevens exporteren (AVG)](#5-uw-gegevens-exporteren-avg)
6. [Wachtwoord wijzigen of resetten](#6-wachtwoord-wijzigen-of-resetten)
7. [E-mailvoorkeuren instellen](#7-e-mailvoorkeuren-instellen)
8. [Veelgestelde vragen](#8-veelgestelde-vragen)
9. [Support en contact](#9-support-en-contact)

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

Als MFA is ingeschakeld voor uw account:

1. Open uw authenticator-app (bijvoorbeeld Google Authenticator of Microsoft Authenticator).
2. Voer de **6-cijferige code** in die de app toont.
3. Klik op **Verifiëren**.

<!-- Screenshot: MFA-verificatiescherm -->
![MFA-verificatie](../screenshots/mfa-verificatie.png)

### Eerste keer inloggen

Bij uw eerste inlog ontvangt u een welkomstmail met een link om uw wachtwoord in te stellen.

1. Klik op de link in de welkomstmail.
2. Kies een **sterk wachtwoord** (minimaal 12 tekens, met hoofdletters, kleine letters, cijfers en symbolen).
3. Als MFA verplicht is: scan de **QR-code** met uw authenticator-app.
4. Bewaar de **8 herstelcodes** op een veilige plek.

<!-- Screenshot: QR-code setup -->
![MFA-setup](../screenshots/mfa-setup-qr.png)

> **Tip:** Bewaar uw herstelcodes in een wachtwoordmanager of op papier in een kluis. U heeft ze nodig als u uw telefoon verliest.

---

## 2. Uw urenstaat bekijken

### De urenstaat openen

1. Klik in het menu op **Mijn uren** (of ga naar `/uren/mijn-week`).
2. U ziet uw eigen werkweek met per dag:
   - **Begintijd** en **eindtijd**
   - **Pauze**
   - **Netto uren**
   - **Status** (vastgesteld, concept, bezwaar)

<!-- Screenshot: medewerker-urenstaat met weekoverzicht -->
![Mijn urenstaat](../screenshots/mijn-week.png)

### Navigeren tussen weken

- Gebruik de **pijlknoppen** om naar vorige of volgende weken te gaan.
- Feestdagen worden als grijze cel getoond met de naam van de feestdag.

### Statusbadges begrijpen

| Badge | Kleur | Betekenis |
|---|---|---|
| Vastgesteld | Groen | Uw uren zijn definitief vastgesteld door uw manager |
| Concept | Grijs | Uren zijn nog niet definitief |
| Bezwaar | Geel | U heeft bezwaar ingediend op deze regel |

---

## 3. Bezwaar indienen

Als u het niet eens bent met een vastgestelde werkregel, kunt u bezwaar indienen.

### Stap voor stap

1. Ga naar **Mijn uren**.
2. Zoek de werkregel waartegen u bezwaar wilt maken.
3. Klik op de knop **Bezwaar** naast de regel.
4. Schrijf uw **motivatie** (leg uit waarom u het niet eens bent).
5. Klik op **Indienen**.

<!-- Screenshot: bezwaarformulier vanuit medewerker-urenstaat -->
![Bezwaar indienen](../screenshots/bezwaar-indienen.png)

### Wat gebeurt er daarna?

- Uw manager ontvangt een melding over het bezwaar.
- De manager beoordeelt uw bezwaar en schrijft een motivatie.
- U ontvangt een e-mail met het besluit (akkoord of afgewezen).
- De status van de werkregel verandert naar "Akkoord" of "Afgewezen".

> **Let op:** U kunt alleen bezwaar indienen op werkregels met de status "Vastgesteld".

---

## 4. Verlof en ziekte registreren

### Verlof of ziekte melden

1. Klik in het menu op **Verlof** (of ga naar `/verlof`).
2. Selecteer het **type**:
   - **Ziekte** — als u ziek bent
   - **Verlof** — als u vrij neemt
3. Kies de **datum** of **datumreeks**.
4. Schrijf een **toelichting** (verplicht voor medewerkers).
5. Klik op **Opslaan**.

<!-- Screenshot: verlofformulier voor medewerker -->
![Verlof melden](../screenshots/verlof-medewerker.png)

> **Let op:** Als medewerker kunt u geen "Feestdag" registreren. Dit doet uw manager.

### Verlof- en ziekteregels in uw urenstaat

Verlof- en ziekteregels verschijnen in uw urenstaat, maar tellen niet mee voor de ATW-werktijdberekening.

---

## 5. Uw gegevens exporteren (AVG)

Op grond van de AVG (privacywet) heeft u het recht om uw persoonsgegevens in te zien.

### Gegevens opvragen

1. Neem contact op met uw manager of systeembeheerder.
2. Zij kunnen via het systeem een export maken van al uw gegevens:
   - Persoonlijke informatie
   - Alle werkregels
   - Bezwaren
   - ATW-meldingen
   - Audit-logboek

De export wordt als JSON-bestand aangeleverd.

> **Tip:** U kunt ook zelf een export aanvragen als uw organisatie dit heeft ingeschakeld. Neem contact op met uw beheerder voor meer informatie.

### Recht op verwijdering

Bij uitdiensttreding kunt u verzoeken om verwijdering van uw persoonsgegevens. Uw naam en e-mailadres worden dan gepseudonimiseerd. Uw uren blijven 7 jaar bewaard voor fiscale doeleinden (dit is wettelijk verplicht).

---

## 6. Wachtwoord wijzigen of resetten

### Wachtwoord vergeten

1. Ga naar het inlogscherm.
2. Klik op **Wachtwoord vergeten**.
3. Voer uw e-mailadres in.
4. U ontvangt een e-mail met een resetlink (24 uur geldig).
5. Klik op de link en kies een nieuw wachtwoord.

<!-- Screenshot: wachtwoord-vergeten-scherm -->
![Wachtwoord vergeten](../screenshots/wachtwoord-vergeten.png)

### Eisen aan uw wachtwoord

Uw wachtwoord moet voldoen aan:
- Minimaal **12 tekens**
- Minstens één **hoofdletter**
- Minstens één **kleine letter**
- Minstens één **cijfer**
- Minstens één **symbool** (bijvoorbeeld: !@#$%)

Een sterkte-indicator toont of uw wachtwoord sterk genoeg is.

---

## 7. E-mailvoorkeuren instellen

U kunt zelf bepalen of u herinneringsmails wilt ontvangen.

### Herinneringen uit- of inschakelen

1. Ga naar uw **accountinstellingen** (via uw profielmenu).
2. Zoek de optie **E-mail herinneringen ontvangen**.
3. Zet de schakelaar **aan** of **uit**.
4. Klik op **Opslaan**.

> **Let op:** Belangrijke mails (welkomstmail, wachtwoord-reset, werkregel vastgesteld) ontvangt u altijd, ook als u herinneringen heeft uitgezet.

---

## 8. Veelgestelde vragen

### Ik kan niet inloggen. Wat moet ik doen?

- Controleer of u het juiste e-mailadres gebruikt.
- Controleer of uw wachtwoord correct is (let op hoofdletters).
- Als u uw wachtwoord bent vergeten, gebruik dan de link "Wachtwoord vergeten".
- Als u uw telefoon (voor MFA) bent kwijtgeraakt, gebruik dan een van uw 8 herstelcodes.
- Neem contact op met uw manager als het probleem aanhoudt.

### Waarom zie ik een gele badge bij mijn uren?

Een gele badge betekent dat u bezwaar heeft ingediend op die werkregel. Uw manager moet het bezwaar nog beoordelen.

### Kan ik mijn eigen uren aanpassen?

Nee. Als medewerker kunt u uw uren niet zelf wijzigen. Als u het niet eens bent met een werkregel, kunt u bezwaar indienen.

### Wat is een feestdag in het weekoverzicht?

Grijze cellen in het weekoverzicht zijn nationale feestdagen. Op deze dagen hoeft u normaal gesproken niet te werken. Uw manager kan wel uren registreren als u toch heeft gewerkt.

### Hoe lang worden mijn gegevens bewaard?

- Uw uren worden **7 jaar** bewaard (wettelijke fiscale bewaarplicht).
- Na 7 jaar worden uw persoonsgegevens automatisch gepseudonimiseerd.
- Uw naam en e-mailadres worden dan vervangen door een code.

### Wat is MFA en waarom moet ik het gebruiken?

MFA (Multi-Factor Authenticatie) is een extra beveiligingslaag. Naast uw wachtwoord heeft u een code nodig van uw telefoon. Dit beschermt uw account als iemand uw wachtwoord ontdekt.

### Ik heb mijn telefoon verloren. Hoe log ik in?

Gebruik een van uw **8 herstelcodes** die u bij de MFA-setup heeft ontvangen. Neem daarna contact op met uw manager om MFA opnieuw in te stellen.

---

## 9. Support en contact

Bij vragen of problemen kunt u contact opnemen met:

- **Uw manager:** Voor vragen over uren, verlof of bezwaren.
- **Systeembeheerder:** Voor technische problemen (inloggen, MFA, foutmeldingen).
- **Documentatie:** Raadpleeg deze handleiding of vraag uw manager om hulp.

### Een probleem melden

1. Noteer wat u deed toen het probleem optrad.
2. Noteer de foutmelding (indien zichtbaar).
3. Maak een screenshot als dat mogelijk is.
4. Stuur deze informatie naar uw manager of systeembeheerder.

---

*Einde handleiding Medewerker*
