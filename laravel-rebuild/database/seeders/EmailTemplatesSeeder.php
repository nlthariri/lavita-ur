<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * EmailTemplatesSeeder
 *
 * Seedt de Nederlandse default-versies van de e-mailtemplates per organisatie.
 *
 * Op dit moment wordt uitsluitend de `welcome_email`-template geseed
 * (Requirement 5.1, 5.3, 5.7). Overige templates worden in latere taken
 * toegevoegd.
 *
 * Idempotentie: gebruikt `updateOrCreate` op `(organization_id, type)`
 * zodat herhaald `php artisan db:seed` geen duplicaten produceert.
 *
 * --- Placeholder-conventie ---
 * Alle placeholders volgen het formaat `{{ name }}` met spaties (zie
 * Requirement 5.3). De `EmailTemplateService` zal in een latere taak
 * worden uitgebreid om dit formaat te renderen.
 *
 * --- Welcome-email placeholders (Requirement 5.3) ---
 *  - {{ full_name }}        Volledige naam van de nieuwe medewerker
 *  - {{ email }}            E-mailadres waarmee wordt ingelogd
 *  - {{ role }}             Toegewezen rol (owner / manager / employee / boekhouder)
 *  - {{ organization_name }} Naam van de organisatie
 *  - {{ login_url }}        Inlog-URL ({APP_URL}/inloggen)
 *  - {{ reset_link }}       Wachtwoord-set-link met token (24u geldig)
 *  - {{ valid_hours }}      Geldigheidsduur van de reset-link in uren
 *  - {{ team_name }}        Naam van het team (optioneel; leeg voor boekhouder
 *                            zonder team — Requirement 5.6)
 */
class EmailTemplatesSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Onderwerp van de welkomstmail (Requirement 5.7).
     */
    private const WELCOME_EMAIL_SUBJECT = 'Welkom bij LaVita Urenregistratie';

    /**
     * Plain-text body van de welkomstmail.
     *
     * Bevat alle placeholders uit Requirement 5.3. De `team_name`-placeholder
     * staat tussen voorwaardelijke markeringen die door de renderer worden
     * verwijderd wanneer de waarde leeg is (Requirement 5.6).
     */
    private const WELCOME_EMAIL_BODY_TEXT = <<<'TXT'
Beste {{ full_name }},

Er is een account voor u aangemaakt in LaVita Urenregistratie van {{ organization_name }}.

Uw gegevens:
- E-mailadres: {{ email }}
- Rol: {{ role }}
- Team: {{ team_name }}

U kunt inloggen via: {{ login_url }}

Stel eerst uw wachtwoord in via onderstaande link. Deze link is {{ valid_hours }} uur geldig:
{{ reset_link }}

Met vriendelijke groet,
LaVita Urenregistratie
TXT;

    /**
     * HTML-body van de welkomstmail.
     */
    private const WELCOME_EMAIL_BODY_HTML = <<<'HTML'
<p>Beste {{ full_name }},</p>
<p>Er is een account voor u aangemaakt in <strong>LaVita Urenregistratie</strong> van <strong>{{ organization_name }}</strong>.</p>
<p><strong>Uw gegevens</strong></p>
<ul>
    <li>E-mailadres: <strong>{{ email }}</strong></li>
    <li>Rol: <strong>{{ role }}</strong></li>
    <li>Team: {{ team_name }}</li>
</ul>
<p>U kunt inloggen via <a href="{{ login_url }}">{{ login_url }}</a>.</p>
<p>Stel eerst uw wachtwoord in via onderstaande link. Deze link is <strong>{{ valid_hours }} uur</strong> geldig:</p>
<p><a href="{{ reset_link }}">{{ reset_link }}</a></p>
<p>Met vriendelijke groet,<br>LaVita Urenregistratie</p>
HTML;

    /**
     * Seed de welcome_email-template voor iedere organisatie.
     */
    public function run(): void
    {
        Organization::query()->each(function (Organization $organization): void {
            EmailTemplate::updateOrCreate(
                [
                    'organization_id' => (int) $organization->id,
                    'type' => 'welcome_email',
                ],
                [
                    'subject_template' => self::WELCOME_EMAIL_SUBJECT,
                    'body_text_template' => self::WELCOME_EMAIL_BODY_TEXT,
                    'body_html_template' => self::WELCOME_EMAIL_BODY_HTML,
                    'is_active' => true,
                    'updated_by_actor_id' => null,
                ],
            );
        });
    }
}
