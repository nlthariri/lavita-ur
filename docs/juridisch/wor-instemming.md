# WOR-instemming — Urenregistratiesysteem LaVita

**Versie:** 1.0  
**Datum:** 2026-05-16  
**Status:** Concept  
**Taalniveau:** B1

---

## Inhoudsopgave

1. [Inleiding](#1-inleiding)
2. [Wettelijk kader: artikel 27 lid 1 sub l WOR](#2-wettelijk-kader-artikel-27-lid-1-sub-l-wor)
3. [Waarom is instemming nodig?](#3-waarom-is-instemming-nodig)
4. [Checklist instemmingsprocedure](#4-checklist-instemmingsprocedure)
5. [Sjabloon instemmingsverzoek aan de OR](#5-sjabloon-instemmingsverzoek-aan-de-or)
6. [Wijzigingen die opnieuw instemming vergen](#6-wijzigingen-die-opnieuw-instemming-vergen)
7. [Registratie van het besluit](#7-registratie-van-het-besluit)

---

## 1. Inleiding

Dit document beschrijft de instemmingsprocedure op grond van de Wet op de ondernemingsraden (WOR) voor de invoering van het urenregistratiesysteem **LaVita Urenregistratie**.

De WOR geeft de ondernemingsraad (OR) instemmingsrecht bij besluiten over het verwerken van persoonsgegevens van werknemers. Omdat LaVita Urenregistratie persoonsgegevens van medewerkers verwerkt (namen, e-mailadressen, gewerkte uren, verlof, ziekte), is instemming van de OR vereist voordat het systeem in gebruik wordt genomen.

Dit document is bedoeld voor:
- De bestuurder van de organisatie
- De ondernemingsraad
- De functionaris gegevensbescherming (FG), indien aanwezig

---

## 2. Wettelijk kader: artikel 27 lid 1 sub l WOR

### Wat zegt de wet?

Artikel 27 lid 1 sub l van de Wet op de ondernemingsraden bepaalt:

> De ondernemer behoeft de instemming van de ondernemingsraad voor elk door hem voorgenomen besluit tot vaststelling, wijziging of intrekking van een regeling inzake voorzieningen die gericht zijn op of geschikt zijn voor waarneming van of controle op aanwezigheid, gedrag of prestaties van de in de onderneming werkzame personen.

### Wat betekent dit voor LaVita Urenregistratie?

LaVita Urenregistratie is een systeem dat:

- De **aanwezigheid** van medewerkers registreert (gewerkte uren per dag)
- **Prestaties** zichtbaar maakt (netto-uren, projectkoppelingen, rapportages)
- **Gedrag** kan monitoren (ATW-overtredingen, openstaande invoer)

Daarom valt de invoering van dit systeem onder artikel 27 lid 1 sub l WOR en is **instemming van de OR verplicht**.

### Wanneer geldt dit?

De instemmingsplicht geldt voor organisaties met een ondernemingsraad. Op grond van de WOR is een OR verplicht bij ondernemingen met **50 of meer werknemers**.

---

## 3. Waarom is instemming nodig?

De instemming van de OR is nodig omdat:

1. **Het systeem persoonsgegevens verwerkt** — namen, e-mailadressen, gewerkte uren, verlof- en ziektemeldingen.
2. **Het systeem controle mogelijk maakt** — managers kunnen zien wie wanneer heeft gewerkt, wie uren mist, en wie ATW-grenzen nadert.
3. **Het systeem geautomatiseerde besluitvorming bevat** — ATW-validaties blokkeren automatisch werkregels die de wet overtreden.
4. **Het systeem rapportages genereert** — overzichten per medewerker, team en project.

Zonder instemming van de OR mag het systeem niet in gebruik worden genomen. Een besluit zonder instemming is **nietig** (artikel 27 lid 5 WOR).

---

## 4. Checklist instemmingsprocedure

Gebruik deze checklist om de instemmingsprocedure correct te doorlopen:

### (a) Tijdige aanvraag instemming OR

- [ ] Het instemmingsverzoek is **minimaal 4 weken** vóór de geplande invoerdatum aan de OR voorgelegd.
- [ ] Het verzoek is **schriftelijk** ingediend bij de voorzitter van de OR.
- [ ] De OR heeft voldoende tijd gekregen om het verzoek te bespreken (minimaal één OR-vergadering).

### (b) Verstrekte informatie aan OR

De volgende documenten zijn aan de OR verstrekt:

- [ ] **Functioneel ontwerp** — beschrijving van wat het systeem doet (zie `docs/architectuur.md`)
- [ ] **Datamodel** — welke gegevens worden opgeslagen (zie `docs/technical/datamodel.md`)
- [ ] **AVG-verwerkersovereenkomst** — juridische basis voor gegevensverwerking (zie `docs/juridisch/avg-verwerkersovereenkomst.md`)
- [ ] **Privacyverklaring** — hoe medewerkers worden geïnformeerd
- [ ] **Bewaartermijnen** — hoe lang gegevens worden bewaard (7 jaar, daarna pseudonimisering)
- [ ] **Beveiligingsmaatregelen** — TLS 1.3, AES-256 encryptie, MFA, rolgebaseerde toegang
- [ ] **Rechten van medewerkers** — inzage, bezwaar, opt-out herinneringen, data-export
- [ ] **Doel en noodzaak** — waarom het systeem wordt ingevoerd (ATW-naleving, fiscale bewaarplicht)

### (c) Besluit OR

- [ ] De OR heeft het verzoek besproken in een vergadering.
- [ ] De OR heeft eventuele vragen gesteld en antwoord ontvangen.
- [ ] De OR heeft **schriftelijk** instemming verleend.
- [ ] OF: de OR heeft instemming **geweigerd** (zie punt d).

### (d) Afhandeling bij weigering instemming

Als de OR instemming weigert:

1. **Overleg:** De bestuurder treedt in overleg met de OR om bezwaren te bespreken.
2. **Aanpassing:** Indien mogelijk past de bestuurder het voorstel aan op basis van de bezwaren.
3. **Nieuw verzoek:** De bestuurder dient een aangepast instemmingsverzoek in.
4. **Kantonrechter:** Als geen overeenstemming wordt bereikt, kan de bestuurder de kantonrechter verzoeken om vervangende toestemming (artikel 27 lid 4 WOR). De kantonrechter verleent toestemming alleen als:
   - De weigering van de OR onredelijk is, OF
   - Het besluit wordt gevergd door zwaarwegende bedrijfsorganisatorische, economische of sociale redenen.

> **Let op:** Zonder instemming of vervangende toestemming van de kantonrechter mag het systeem **niet** in gebruik worden genomen.

### (e) Registratie schriftelijke instemming

- [ ] Het instemmingsbesluit van de OR is schriftelijk vastgelegd.
- [ ] Het besluit is opgeslagen in `docs/juridisch/wor-besluit-{datum}.md`.
- [ ] Het besluit vermeldt: datum, namen OR-leden, stemverhouding, eventuele voorwaarden.
- [ ] Een kopie is verstrekt aan de bestuurder en de OR.

---

## 5. Sjabloon instemmingsverzoek aan de OR

---

### INSTEMMINGSVERZOEK AAN DE ONDERNEMINGSRAAD

**Betreft:** Invoering urenregistratiesysteem LaVita Urenregistratie  
**Datum:** _[datum invullen]_  
**Van:** _[naam bestuurder]_, bestuurder  
**Aan:** De ondernemingsraad van _[naam organisatie]_

---

Geachte leden van de ondernemingsraad,

Hierbij verzoek ik u om instemming te verlenen voor het voorgenomen besluit tot invoering van het urenregistratiesysteem **LaVita Urenregistratie**, op grond van artikel 27 lid 1 sub l van de Wet op de ondernemingsraden.

#### 1. Aanleiding en doel

De organisatie heeft behoefte aan een digitaal systeem voor het registreren van gewerkte uren. De belangrijkste redenen zijn:

- **Wettelijke verplichting:** De Arbeidstijdenwet (ATW) verplicht werkgevers om een deugdelijke urenregistratie bij te houden.
- **Fiscale bewaarplicht:** De Belastingdienst vereist dat loonadministratie (inclusief uren) 7 jaar wordt bewaard.
- **Efficiëntie:** Het huidige proces (handmatig/Excel) is foutgevoelig en tijdrovend.
- **Transparantie:** Medewerkers krijgen inzage in hun eigen uren en kunnen bezwaar maken.

#### 2. Beschrijving van het systeem

LaVita Urenregistratie is een webapplicatie waarmee:

- Managers en medewerkers gewerkte uren registreren
- Het systeem automatisch controleert of de Arbeidstijdenwet wordt nageleefd
- Medewerkers hun eigen urenstaat kunnen inzien
- Medewerkers bezwaar kunnen maken tegen vastgestelde uren
- Rapportages worden gegenereerd voor de loonadministratie
- Verlof en ziekte worden geregistreerd

#### 3. Welke persoonsgegevens worden verwerkt

| Gegeven | Doel |
|---|---|
| Naam en e-mailadres | Identificatie en communicatie |
| Gewerkte uren (datum, begin, eind, pauze) | Urenregistratie en ATW-controle |
| Verlof- en ziektemeldingen | Planning en administratie |
| Rol en team | Autorisatie en rapportage |
| Startdatum dienstverband | Jubileumnotificaties |

#### 4. Beveiligingsmaatregelen

- Versleuteling van persoonsgegevens (AES-256)
- Beveiligde verbinding (TLS 1.3)
- Tweestapsverificatie (MFA) voor managers en beheerders
- Rolgebaseerde toegang (medewerkers zien alleen eigen gegevens)
- Dagelijkse versleutelde backups
- Audit-trail van alle wijzigingen

#### 5. Rechten van medewerkers

- **Inzage:** Medewerkers kunnen hun eigen uren en gegevens bekijken
- **Bezwaar:** Medewerkers kunnen bezwaar maken tegen vastgestelde uren
- **Opt-out:** Medewerkers kunnen herinneringsmails uitschakelen
- **Data-export:** Medewerkers kunnen een export van hun gegevens opvragen
- **Verwijdering:** Bij uitdiensttreding worden persoonsgegevens gepseudonimiseerd

#### 6. Bewaartermijnen

- Werkregels en bezwaren: 7 jaar (fiscale bewaarplicht)
- Persoonsgegevens na uitdiensttreding: direct gepseudonimiseerd, na 7 jaar volledig geanonimiseerd
- Backups: 30 dagen

#### 7. Bijlagen

Bij dit verzoek zijn de volgende documenten gevoegd:

1. Functioneel ontwerp (`docs/architectuur.md`)
2. Datamodel (`docs/technical/datamodel.md`)
3. AVG-verwerkersovereenkomst (`docs/juridisch/avg-verwerkersovereenkomst.md`)
4. Technische beveiligingsdocumentatie (`docs/technical/infrastructuur.md`)

#### 8. Verzoek

Ik verzoek u om instemming te verlenen voor de invoering van bovengenoemd systeem. Ik ben graag bereid om het voorstel toe te lichten in een OR-vergadering en eventuele vragen te beantwoorden.

Graag ontvang ik uw besluit binnen _[termijn, bijvoorbeeld 4 weken]_.

Met vriendelijke groet,

_[naam bestuurder]_  
_[functie]_  
_[organisatie]_

---

## 6. Wijzigingen die opnieuw instemming vergen

Na de eerste instemming is **opnieuw instemming** van de OR vereist bij de volgende wijzigingen:

### Altijd opnieuw instemming vereist

| Wijziging | Toelichting |
|---|---|
| **Nieuwe profilering of scoring** | Bijvoorbeeld: het toevoegen van productiviteitsscores, prestatie-indicatoren of rankings op basis van gewerkte uren |
| **Nieuwe verstrekking aan derden** | Bijvoorbeeld: het delen van urengegevens met een externe partij (anders dan de huidige sub-verwerkers) |
| **Wijziging bewaartermijn** | Bijvoorbeeld: verlenging van 7 jaar naar 10 jaar, of verkorting |
| **Nieuwe categorie persoonsgegevens** | Bijvoorbeeld: het toevoegen van locatiegegevens, GPS-tracking of biometrische gegevens |
| **Nieuwe vorm van monitoring** | Bijvoorbeeld: het toevoegen van schermopnames, toetsaanslagen of activiteitsmonitoring |
| **Geautomatiseerde besluitvorming met rechtsgevolgen** | Bijvoorbeeld: automatische sancties bij te weinig gewerkte uren |
| **Wijziging doeleinden** | Bijvoorbeeld: het gebruiken van urendata voor beoordelingsgesprekken of ontslagprocedures |

### Geen nieuwe instemming vereist (in principe)

| Wijziging | Toelichting |
|---|---|
| Bugfixes en beveiligingsupdates | Geen wijziging in functionaliteit of gegevensverwerking |
| Visuele aanpassingen (UI) | Geen wijziging in welke gegevens worden verwerkt |
| Toevoegen van een nieuw project of kostenplaats | Bestaande functionaliteit, geen nieuwe gegevensverwerking |
| Wijziging e-mailtemplates | Geen wijziging in welke gegevens worden verwerkt |
| Nieuwe sub-verwerker binnen EU | Mits gemeld aan OR en geen bezwaar (zie verwerkersovereenkomst) |

### Twijfelgevallen

Bij twijfel of een wijziging opnieuw instemming vereist, is het advies om:

1. De OR te informeren over de voorgenomen wijziging.
2. De OR de gelegenheid te geven om aan te geven of zij instemming nodig achten.
3. Bij verschil van mening: juridisch advies inwinnen.

---

## 7. Registratie van het besluit

### Vastlegging

Na het besluit van de OR wordt het volgende vastgelegd:

1. **Bestandsnaam:** `docs/juridisch/wor-besluit-{JJJJ-MM-DD}.md`
2. **Inhoud:**
   - Datum van het besluit
   - Namen van de aanwezige OR-leden
   - Stemverhouding (voor/tegen/onthouding)
   - Eventuele voorwaarden of opmerkingen van de OR
   - Handtekening voorzitter OR
   - Handtekening bestuurder

### Sjabloon besluitregistratie

```markdown
# WOR-besluit — LaVita Urenregistratie

**Datum:** [datum]
**Vergadering:** OR-vergadering [nummer]

## Aanwezige OR-leden

1. [naam] — voorzitter
2. [naam]
3. [naam]
...

## Besluit

De ondernemingsraad verleent hierbij [WEL / GEEN] instemming voor de invoering
van het urenregistratiesysteem LaVita Urenregistratie, zoals beschreven in het
instemmingsverzoek van [datum].

## Stemverhouding

- Voor: [aantal]
- Tegen: [aantal]
- Onthouding: [aantal]

## Voorwaarden (indien van toepassing)

- [voorwaarde 1]
- [voorwaarde 2]

## Ondertekening

Voorzitter OR: _________________ Datum: _________
Bestuurder:    _________________ Datum: _________
```

---

*Einde WOR-instemmingsdocument*
