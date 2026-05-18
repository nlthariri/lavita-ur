# Verwerkersovereenkomst conform artikel 28 lid 3 AVG

**Versie:** 1.0  
**Datum:** 2026-05-16  
**Status:** Concept — ter ondertekening

---

## Inhoudsopgave

1. [Partijen](#1-partijen)
2. [Onderwerp en duur](#2-onderwerp-en-duur)
3. [Aard en doel van de verwerking](#3-aard-en-doel-van-de-verwerking)
4. [Type persoonsgegevens](#4-type-persoonsgegevens)
5. [Categorieën betrokkenen](#5-categorieën-betrokkenen)
6. [Rechten en plichten verwerkingsverantwoordelijke](#6-rechten-en-plichten-verwerkingsverantwoordelijke)
7. [Verplichtingen verwerker](#7-verplichtingen-verwerker)
8. [Sub-verwerkers](#8-sub-verwerkers)
9. [Doorgifte buiten de EER](#9-doorgifte-buiten-de-eer)
10. [Beveiliging](#10-beveiliging)
11. [Datalek-meldplicht](#11-datalek-meldplicht)
12. [Bewaartermijnen en verwijdering](#12-bewaartermijnen-en-verwijdering)
13. [Rechten van betrokkenen](#13-rechten-van-betrokkenen)
14. [Audit-recht](#14-audit-recht)
15. [Aansprakelijkheid](#15-aansprakelijkheid)
16. [Looptijd en beëindiging](#16-looptijd-en-beëindiging)
17. [Ondertekening](#17-ondertekening)

---

## 1. Partijen

**Verwerkingsverantwoordelijke:**

| Veld | Gegevens |
|---|---|
| Organisatie | _[Naam opdrachtgever]_ |
| Adres | _[Adres opdrachtgever]_ |
| KvK-nummer | _[KvK-nummer]_ |
| Contactpersoon | _[Naam contactpersoon]_ |
| E-mail | _[E-mailadres]_ |

Hierna te noemen: **"Verantwoordelijke"**

**Verwerker:**

| Veld | Gegevens |
|---|---|
| Organisatie | _[Naam leverancier / ontwikkelaar]_ |
| Adres | _[Adres leverancier]_ |
| KvK-nummer | _[KvK-nummer]_ |
| Contactpersoon | _[Naam contactpersoon]_ |
| E-mail | _[E-mailadres]_ |

Hierna te noemen: **"Verwerker"**

---

## 2. Onderwerp en duur

### Onderwerp

Deze verwerkersovereenkomst heeft betrekking op de verwerking van persoonsgegevens door de Verwerker ten behoeve van de Verantwoordelijke in het kader van het systeem **LaVita Urenregistratie** (hierna: "het Systeem").

### Duur

Deze overeenkomst treedt in werking op de datum van ondertekening en loopt zolang de Verwerker persoonsgegevens verwerkt ten behoeve van de Verantwoordelijke. De overeenkomst eindigt automatisch wanneer de Verwerker geen persoonsgegevens meer onder zich heeft.

---

## 3. Aard en doel van de verwerking

### Aard van de verwerking

De Verwerker verwerkt persoonsgegevens door middel van:

- Opslag in een MySQL-database (versleuteld, zie sectie 10)
- Weergave in een webapplicatie (via TLS 1.3 beveiligde verbinding)
- Verzending van e-mailnotificaties (via SMTP-relay)
- Genereren van rapportages (PDF/Excel)
- Geautomatiseerde berekeningen (ATW-controles, netto-uren)
- Dagelijkse versleutelde backups

### Doel van de verwerking

De verwerking heeft als doel:

- Het registreren en beheren van gewerkte uren van medewerkers
- Het naleven van de Arbeidstijdenwet (ATW)
- Het genereren van rapportages voor loon- en fiscale administratie
- Het faciliteren van een bezwaarprocedure op vastgestelde uren
- Het versturen van operationele e-mailnotificaties aan medewerkers en managers

---

## 4. Type persoonsgegevens

De volgende categorieën persoonsgegevens worden verwerkt:

| Categorie | Gegevens | Versleuteld at-rest |
|---|---|---|
| Identificatie | Volledige naam, e-mailadres | Ja (AES-256-CBC) |
| Contact | Telefoonnummer (optioneel) | Ja (AES-256-CBC) |
| Arbeidsrelatie | Rol, team, startdatum dienstverband, organisatie | Nee (niet-gevoelig) |
| Werkuren | Datum, begin-/eindtijd, pauze, netto-minuten, type (werk/verlof/ziekte) | Nee |
| Projectkoppeling | Project-ID, kostenplaats-ID per werkregel | Nee |
| Bezwaren | Motivatie, status, beoordelingsmotivatie | Nee |
| ATW-signalen | Type overtreding, minuten, drempelwaarden | Nee |
| Audit-trail | Acties uitgevoerd door of op de betrokkene | Nee |
| Authenticatie | Wachtwoord-hash (bcrypt), MFA-secret (versleuteld), sessietokens | Ja (deels) |
| E-mailverkeer | Verzonden mails (type, onderwerp, ontvanger, tijdstip) | Nee |

> **Bijzondere persoonsgegevens:** Er worden geen bijzondere persoonsgegevens verwerkt in de zin van artikel 9 AVG. Ziekmeldingen bevatten geen medische informatie; alleen het feit dat een medewerker afwezig is wegens ziekte wordt geregistreerd.

---

## 5. Categorieën betrokkenen

De persoonsgegevens hebben betrekking op de volgende categorieën betrokkenen:

- **Medewerkers** (employees) van de Verantwoordelijke
- **Managers** (teamleiders) van de Verantwoordelijke
- **Boekhouders** met leestoegang tot het systeem
- **Beheerders** (owners/admins) van de organisatie

---

## 6. Rechten en plichten verwerkingsverantwoordelijke

De Verantwoordelijke:

1. Garandeert dat de verwerking van persoonsgegevens een rechtmatige grondslag heeft (artikel 6 AVG), te weten:
   - Uitvoering van de arbeidsovereenkomst (art. 6 lid 1 sub b)
   - Wettelijke verplichting — fiscale bewaarplicht 7 jaar (art. 6 lid 1 sub c)
   - Gerechtvaardigd belang — bedrijfsvoering en ATW-naleving (art. 6 lid 1 sub f)
2. Informeert betrokkenen over de verwerking via een privacyverklaring.
3. Is verantwoordelijk voor het verkrijgen van WOR-instemming (zie `docs/juridisch/wor-instemming.md`).
4. Geeft de Verwerker uitsluitend schriftelijke instructies voor de verwerking.
5. Meldt datalekken aan de Autoriteit Persoonsgegevens binnen 72 uur na kennisname.

---

## 7. Verplichtingen verwerker

De Verwerker:

1. Verwerkt persoonsgegevens uitsluitend op basis van schriftelijke instructies van de Verantwoordelijke, tenzij een wettelijke verplichting anders vereist.
2. Waarborgt dat personen die toegang hebben tot de persoonsgegevens gebonden zijn aan geheimhouding.
3. Treft passende technische en organisatorische maatregelen (zie sectie 10).
4. Schakelt geen sub-verwerker in zonder voorafgaande schriftelijke toestemming van de Verantwoordelijke (zie sectie 8).
5. Verleent bijstand aan de Verantwoordelijke bij het nakomen van verzoeken van betrokkenen (inzage, rectificatie, verwijdering, overdraagbaarheid).
6. Verleent bijstand bij het uitvoeren van een gegevensbeschermingseffectbeoordeling (DPIA) indien nodig.
7. Stelt na beëindiging van de verwerkingsdiensten alle persoonsgegevens ter beschikking van de Verantwoordelijke en verwijdert bestaande kopieën, tenzij bewaring wettelijk verplicht is.
8. Stelt alle informatie beschikbaar die nodig is om naleving aan te tonen en maakt audits mogelijk (zie sectie 14).

---

## 8. Sub-verwerkers

### Goedgekeurde sub-verwerkers

De Verantwoordelijke geeft hierbij toestemming voor de inzet van de volgende sub-verwerkers:

| Sub-verwerker | Dienst | Locatie | Verwerking |
|---|---|---|---|
| **Cloud86 B.V. (TransIP/team.blue)** | Hosting (Plesk-server) | Nederland (EU) | Opslag database en applicatie, dagelijkse backups |
| **SMTP-relay provider** _[naam invullen]_ | E-mailverzending | EU | Verzending van transactionele e-mails aan betrokkenen |

### Wijziging sub-verwerkers

1. De Verwerker informeert de Verantwoordelijke minimaal **30 dagen** vooraf over voorgenomen wijzigingen in sub-verwerkers.
2. De Verantwoordelijke kan binnen 14 dagen schriftelijk bezwaar maken.
3. Bij bezwaar treedt de Verwerker in overleg. Indien geen overeenstemming wordt bereikt, heeft de Verantwoordelijke het recht de overeenkomst op te zeggen.
4. De Verwerker sluit met elke sub-verwerker een verwerkersovereenkomst die minimaal dezelfde verplichtingen bevat als deze overeenkomst.

---

## 9. Doorgifte buiten de EER

De Verwerker verwerkt geen persoonsgegevens buiten de Europese Economische Ruimte (EER). Alle servers, backups en sub-verwerkers bevinden zich binnen de EU.

Indien doorgifte buiten de EER in de toekomst noodzakelijk wordt, zal de Verwerker:
1. De Verantwoordelijke vooraf informeren.
2. Passende waarborgen treffen (Standard Contractual Clauses of adequaatheidsbesluit).
3. Schriftelijke toestemming verkrijgen van de Verantwoordelijke.

---

## 10. Beveiliging

De Verwerker treft de volgende technische en organisatorische maatregelen:

### Transport (in transit)

| Maatregel | Specificatie |
|---|---|
| Protocol | TLS 1.3 |
| Cipher suites | TLS_AES_256_GCM_SHA384, TLS_CHACHA20_POLY1305_SHA256, TLS_AES_128_GCM_SHA256 |
| HSTS | max-age=31536000; includeSubDomains; preload |
| HTTP-redirect | 308 Permanent Redirect naar HTTPS |
| OCSP Stapling | Ingeschakeld |

### Opslag (at-rest)

| Maatregel | Specificatie |
|---|---|
| Database-encryptie (applicatieniveau) | AES-256-CBC via Laravel encrypted cast op naam, e-mail, telefoon |
| Database-encryptie (schijfniveau) | LUKS full-disk encryption op MySQL data-directory |
| Backup-encryptie | AES-256-CBC met apart wachtwoord (niet in codebase) |
| Sleutelbeheer | APP_KEY in .env (chmod 600), rotatie elke 12 maanden |
| E-mail lookup | SHA-256 deterministische hash (email_index_hash) |

### Toegangsbeheersing

| Maatregel | Specificatie |
|---|---|
| Authenticatie | E-mail + wachtwoord (bcrypt, min. 12 tekens) |
| MFA | TOTP verplicht voor admin en manager |
| Sessies | Bearer-token, automatische verloop |
| Rolgebaseerde autorisatie | 4 rollen: owner, manager, employee, boekhouder |
| Read-only middleware | Boekhouder kan geen schrijfacties uitvoeren |

### Monitoring en logging

| Maatregel | Specificatie |
|---|---|
| Audit-trail | Alle mutaties worden gelogd in audit_events (append-only) |
| E-mail evidence | Onweerlegbaar bewijs van verzonden mails |
| Backup-integriteit | Dagelijkse SHA-256 verificatie |
| Alerting | E-mail bij backup-falen of integriteitsfouten |

---

## 11. Datalek-meldplicht

### Definitie

Een datalek is een inbreuk op de beveiliging die leidt tot vernietiging, verlies, wijziging, ongeoorloofde verstrekking van of ongeoorloofde toegang tot persoonsgegevens.

### Procedure

1. De Verwerker meldt een (vermoedelijk) datalek **binnen 24 uur** na ontdekking aan de Verantwoordelijke.
2. De melding bevat minimaal:
   - Aard van het datalek
   - Categorieën en geschat aantal betrokkenen
   - Categorieën en geschat aantal persoonsgegevensrecords
   - Waarschijnlijke gevolgen
   - Genomen en voorgestelde maatregelen
3. De Verantwoordelijke is verantwoordelijk voor melding aan de Autoriteit Persoonsgegevens (binnen 72 uur) en eventueel aan betrokkenen.
4. De Verwerker verleent alle medewerking bij het onderzoek en de afhandeling.
5. De Verwerker houdt een register bij van alle datalekken, inclusief feiten, gevolgen en genomen maatregelen.

---

## 12. Bewaartermijnen en verwijdering

### Bewaartermijnen

| Gegevenstype | Bewaartermijn | Grondslag |
|---|---|---|
| Werkregels (work_entries) | 7 jaar na registratie | Fiscale bewaarplicht (art. 52 AWR) |
| Bezwaren (objections) | 7 jaar na registratie | Fiscale bewaarplicht |
| Audit-events | 7 jaar na aanmaak | Fiscale bewaarplicht + verantwoordingsplicht AVG |
| Persoonsgegevens actieve medewerkers | Duur dienstverband + 7 jaar | Fiscale bewaarplicht |
| Persoonsgegevens na uitdiensttreding | Pseudonimisering bij verwijdering; volledige anonimisering na 7 jaar | AVG art. 17 + fiscale bewaarplicht |
| Backups | 30 dagen | Operationeel |
| E-mail outbox logs | 7 jaar | Fiscale bewaarplicht |

### Pseudonimisering

Bij verwijdering van een account (uitdiensttreding) worden persoonsgegevens direct gepseudonimiseerd:

- `name` → `user-{id}`
- `full_name` → `null`
- `email` → `user-{id}@redacted.lavita.local`
- `phone` → `null`

Werkregels, bezwaren en audit-events blijven gekoppeld aan het gepseudonimiseerde account (de `employee_id` FK blijft intact). Na 7 jaar worden ook `employment_start` en `employment_end` gewist en worden `actor_id`-velden in audit-events genulled.

### Verwijdering na beëindiging overeenkomst

Na beëindiging van deze overeenkomst:

1. De Verwerker levert alle persoonsgegevens aan de Verantwoordelijke (in JSON-formaat via de data-export functie).
2. De Verwerker verwijdert alle kopieën binnen 30 dagen na levering.
3. De Verwerker bevestigt schriftelijk dat alle gegevens zijn verwijderd.
4. Uitzondering: gegevens die op grond van wettelijke bewaarplicht langer bewaard moeten worden.

---

## 13. Rechten van betrokkenen

De Verwerker ondersteunt de Verantwoordelijke bij het afhandelen van verzoeken van betrokkenen:

| Recht | Implementatie in het Systeem |
|---|---|
| **Inzage** (art. 15) | `GET /api/internal/accounts/{id}/data-export` — volledige export van alle gegevens |
| **Rectificatie** (art. 16) | Accountgegevens aanpasbaar via accountbeheer |
| **Verwijdering** (art. 17) | `DELETE /api/internal/accounts/{id}` — pseudonimisering met behoud van uren |
| **Beperking** (art. 18) | Account deactiveren (is_active = false) |
| **Overdraagbaarheid** (art. 20) | Data-export in machineleesbaar JSON-formaat |
| **Bezwaar** (art. 21) | Opt-out voor niet-essentiële e-mails (email_reminders_opt_in) |

De Verwerker reageert binnen **5 werkdagen** op verzoeken van de Verantwoordelijke met betrekking tot rechten van betrokkenen.

---

## 14. Audit-recht

1. De Verantwoordelijke heeft het recht om maximaal **één keer per jaar** een audit uit te (laten) voeren op de naleving van deze overeenkomst.
2. De Verantwoordelijke kondigt een audit minimaal **4 weken** van tevoren schriftelijk aan.
3. De Verwerker verleent alle redelijke medewerking, waaronder:
   - Toegang tot relevante systemen en documentatie
   - Gesprekken met betrokken medewerkers
   - Inzage in sub-verwerkersovereenkomsten
   - Inzage in het datalekregister
4. De kosten van de audit zijn voor rekening van de Verantwoordelijke, tenzij de audit tekortkomingen aan het licht brengt die aan de Verwerker te wijten zijn.
5. De Verwerker mag een audit weigeren of beperken indien bedrijfsgeheimen of gegevens van andere klanten in het geding komen, mits de Verwerker een redelijk alternatief biedt (bijvoorbeeld een onafhankelijk audit-rapport).

---

## 15. Aansprakelijkheid

1. De Verwerker is aansprakelijk voor schade die voortvloeit uit verwerking die niet voldoet aan de verplichtingen uit deze overeenkomst of de AVG.
2. De aansprakelijkheid van de Verwerker is beperkt tot het bedrag dat in de 12 maanden voorafgaand aan het schadeveroorzakende feit door de Verantwoordelijke aan de Verwerker is betaald, met een maximum van **€ _[bedrag invullen]_**.
3. Deze beperking geldt niet bij opzet of grove nalatigheid.

---

## 16. Looptijd en beëindiging

1. Deze overeenkomst treedt in werking op de datum van ondertekening.
2. De overeenkomst loopt zolang de Verwerker persoonsgegevens verwerkt.
3. Bij beëindiging van de hoofdovereenkomst (dienstverleningsovereenkomst) eindigt ook deze verwerkersovereenkomst, met inachtneming van de verplichtingen uit sectie 12 (bewaartermijnen en verwijdering).
4. Elk der partijen kan deze overeenkomst opzeggen met een opzegtermijn van **3 maanden**.

---

## 17. Ondertekening

Door ondertekening verklaren partijen akkoord te gaan met de inhoud van deze verwerkersovereenkomst.

### Verwerkingsverantwoordelijke

| Veld | |
|---|---|
| Naam | _________________________________ |
| Functie | _________________________________ |
| Datum | _________________________________ |
| Handtekening | _________________________________ |
| Plaats | _________________________________ |

### Verwerker

| Veld | |
|---|---|
| Naam | _________________________________ |
| Functie | _________________________________ |
| Datum | _________________________________ |
| Handtekening | _________________________________ |
| Plaats | _________________________________ |

---

## Bijlage A — Technische beveiligingsmaatregelen (samenvatting)

| Domein | Maatregel |
|---|---|
| Encryptie in transit | TLS 1.3, HSTS, OCSP Stapling |
| Encryptie at-rest (applicatie) | AES-256-CBC (Laravel encrypted cast) |
| Encryptie at-rest (schijf) | LUKS full-disk encryption |
| Encryptie backups | AES-256-CBC met apart wachtwoord |
| Authenticatie | Bcrypt wachtwoord-hash + TOTP MFA |
| Autorisatie | Rolgebaseerd (4 rollen) + team-scope |
| Logging | Append-only audit_events + e-mail evidence trail |
| Pseudonimisering | Automatisch bij accountverwijdering |
| Bewaartermijn | 7 jaar, daarna volledige anonimisering |
| Backup-integriteit | Dagelijkse SHA-256 verificatie + alerting |

---

*Einde verwerkersovereenkomst*
