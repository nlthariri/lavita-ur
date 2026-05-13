import { EmailEventType } from "@prisma/client";

type TemplateSeed = {
  subject: string;
  bodyText: string;
  bodyHtml: string;
};

export const defaultEmailTemplates: Record<EmailEventType, TemplateSeed> = {
  HOURS_REGISTERED: {
    subject: "Uren zijn vastgesteld",
    bodyText:
      "Beste {{naam}},\n\nJe uren van {{datum}} zijn vastgesteld. Bekijk je urenstaat via: {{link}}.\nAls iets niet klopt, dien dan een bezwaar in met motivatie.\n\nAfmelden voor dit type melding: {{unsubscribe_url}}\n\nMet vriendelijke groet,\nLa Vita",
    bodyHtml:
      "<p>Beste {{naam}},</p><p>Je uren van <strong>{{datum}}</strong> zijn vastgesteld.</p><p>Bekijk je urenstaat via <a href='{{link}}'>deze link</a>. Als iets niet klopt, dien dan een bezwaar in met motivatie.</p><p><a href='{{unsubscribe_url}}'>Afmelden voor dit type melding</a></p><p>Met vriendelijke groet,<br/>La Vita</p>",
  },
  OBJECTION_SUBMITTED: {
    subject: "Nieuw bezwaar op urenregistratie",
    bodyText:
      "Er is een nieuw bezwaar ingediend door {{naam}} op {{datum}}.\nReden: {{reden}}\nBeoordelen: {{link}}",
    bodyHtml:
      "<p>Er is een nieuw bezwaar ingediend door <strong>{{naam}}</strong> op {{datum}}.</p><p>Reden: {{reden}}</p><p><a href='{{link}}'>Direct beoordelen</a></p>",
  },
  OBJECTION_RESOLVED: {
    subject: "Uitkomst van je bezwaar",
    bodyText:
      "Beste {{naam}},\n\nJe bezwaar is beoordeeld: {{uitkomst}}.\nToelichting: {{toelichting}}\nBekijk je bijgewerkte urenstaat: {{link}}",
    bodyHtml:
      "<p>Beste {{naam}},</p><p>Je bezwaar is beoordeeld: <strong>{{uitkomst}}</strong>.</p><p>Toelichting: {{toelichting}}</p><p><a href='{{link}}'>Bekijk je bijgewerkte urenstaat</a></p>",
  },
  MISSING_ENTRY_REMINDER: {
    subject: "Herinnering openstaande ureninvoer",
    bodyText:
      "Voor team {{team}} ontbreken nog ureninvoer sinds {{dagen}} dagen. Bekijk overzicht: {{link}}\nAfmelden voor dit type melding: {{unsubscribe_url}}",
    bodyHtml:
      "<p>Voor team <strong>{{team}}</strong> ontbreken nog ureninvoer sinds {{dagen}} dagen.</p><p><a href='{{link}}'>Open overzicht</a></p><p><a href='{{unsubscribe_url}}'>Afmelden voor dit type melding</a></p>",
  },
  ATW_LIMIT_WARNING: {
    subject: "ATW-waarschuwing naderende limiet",
    bodyText:
      "Medewerker {{naam}} nadert een ATW-limiet. Huidige weekuren: {{uren}}. Resterende ruimte: {{ruimte}}.\nAfmelden voor dit type melding: {{unsubscribe_url}}",
    bodyHtml:
      "<p>Medewerker <strong>{{naam}}</strong> nadert een ATW-limiet.</p><p>Huidige weekuren: {{uren}}. Resterende ruimte: {{ruimte}}.</p><p><a href='{{unsubscribe_url}}'>Afmelden voor dit type melding</a></p>",
  },
  ATW_LIMIT_EXCEEDED: {
    subject: "ATW-overschrijding geconstateerd",
    bodyText:
      "Urgent: {{naam}} heeft een ATW-overschrijding op type {{type}}. Actie is vereist.",
    bodyHtml:
      "<p><strong>Urgent</strong>: {{naam}} heeft een ATW-overschrijding op type {{type}}. Actie is vereist.</p>",
  },
  ACCOUNT_CREATED: {
    subject: "Je account is aangemaakt",
    bodyText:
      "Welkom {{naam}}. Je account is aangemaakt. Inloggen: {{link}}. Tijdelijk wachtwoord: {{tijdelijk_wachtwoord}}",
    bodyHtml:
      "<p>Welkom {{naam}}.</p><p>Je account is aangemaakt. <a href='{{link}}'>Inloggen</a>.</p><p>Tijdelijk wachtwoord: <strong>{{tijdelijk_wachtwoord}}</strong></p>",
  },
  MONTHLY_REPORT: {
    subject: "Maandrapportage urenstaten",
    bodyText: "De maandrapportage over {{periode}} is beschikbaar als bijlage.\nAfmelden voor dit type melding: {{unsubscribe_url}}",
    bodyHtml: "<p>De maandrapportage over <strong>{{periode}}</strong> is beschikbaar als bijlage.</p><p><a href='{{unsubscribe_url}}'>Afmelden voor dit type melding</a></p>",
  },
  PASSWORD_RESET: {
    subject: "Wachtwoord reset aanvraag",
    bodyText: "Gebruik deze beveiligde link om je wachtwoord te resetten (geldig 24 uur): {{link}}",
    bodyHtml:
      "<p>Gebruik <a href='{{link}}'>deze beveiligde link</a> om je wachtwoord te resetten (geldig 24 uur).</p>",
  },
};
