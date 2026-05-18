<?php

namespace App\Services;

use App\Models\EmailTemplate;
use Illuminate\Validation\ValidationException;

class EmailTemplateService
{
    private const ALLOWED_TEMPLATE_TYPES = [
        'account_created',
        'welcome_email',
        'password_reset',
        'monthly_report',
        'work_entry_finalized',
        'work_entry_updated',
        'work_entry_deleted',
        'objection_submitted',
        'objection_reviewed',
        'objection_review',
        'reminder_open_entries',
        'pending_input_reminder',
        'atw_daily_limit',
        'atw_weekly_warning',
        'atw_weekly_limit',
        'atw_warning',
        'atw_critical',
        'atw_sixteen_week_average',
        'atw_rest_period',
        'anniversary',
    ];

    /**
     * Code-defined default templates per type.
     *
     * Worden gebruikt wanneer er voor de organisatie nog geen actieve
     * `email_templates`-record is geseed (bv. nieuwe organisatie tijdens
     * tests). De waarden zijn identiek aan de Nederlandse defaults uit
     * `database/seeders/EmailTemplatesSeeder.php`.
     */
    private const DEFAULT_TEMPLATES = [
        'welcome_email' => [
            'subject' => 'Welkom bij LaVita Urenregistratie',
            'body_text' => "Beste {{ full_name }},\n\n"
                ."Er is een account voor u aangemaakt in LaVita Urenregistratie van {{ organization_name }}.\n\n"
                ."Uw gegevens:\n"
                ."- E-mailadres: {{ email }}\n"
                ."- Rol: {{ role }}\n"
                ."- Team: {{ team_name }}\n\n"
                ."U kunt inloggen via: {{ login_url }}\n\n"
                ."Stel eerst uw wachtwoord in via onderstaande link. Deze link is {{ valid_hours }} uur geldig:\n"
                ."{{ reset_link }}\n\n"
                ."Met vriendelijke groet,\n"
                .'LaVita Urenregistratie',
            'body_html' => '<p>Beste {{ full_name }},</p>'
                .'<p>Er is een account voor u aangemaakt in <strong>LaVita Urenregistratie</strong> van <strong>{{ organization_name }}</strong>.</p>'
                .'<p><strong>Uw gegevens</strong></p>'
                .'<ul>'
                .'<li>E-mailadres: <strong>{{ email }}</strong></li>'
                .'<li>Rol: <strong>{{ role }}</strong></li>'
                .'<li>Team: {{ team_name }}</li>'
                .'</ul>'
                .'<p>U kunt inloggen via <a href="{{ login_url }}">{{ login_url }}</a>.</p>'
                .'<p>Stel eerst uw wachtwoord in via onderstaande link. Deze link is <strong>{{ valid_hours }} uur</strong> geldig:</p>'
                .'<p><a href="{{ reset_link }}">{{ reset_link }}</a></p>'
                .'<p>Met vriendelijke groet,<br>LaVita Urenregistratie</p>',
        ],
        'anniversary' => [
            'subject' => 'Felicitatie: {{ years }} jaar in dienst!',
            'body_text' => "Beste collega,\n\n"
                ."Wij willen {{ full_name }} van harte feliciteren met {{ years }} jaar dienstverband!\n\n"
                ."{{ full_name }} is op {{ employment_start }} begonnen en viert vandaag dit mooie jubileum.\n\n"
                ."Hartelijk gefeliciteerd!\n\n"
                ."Met vriendelijke groet,\n"
                .'LaVita Urenregistratie',
            'body_html' => '<p>Beste collega,</p>'
                .'<p>Wij willen <strong>{{ full_name }}</strong> van harte feliciteren met <strong>{{ years }} jaar</strong> dienstverband!</p>'
                .'<p>{{ full_name }} is op <strong>{{ employment_start }}</strong> begonnen en viert vandaag dit mooie jubileum.</p>'
                .'<p>Hartelijk gefeliciteerd!</p>'
                .'<p>Met vriendelijke groet,<br>LaVita Urenregistratie</p>',
        ],
    ];

    public function upsertTemplate(int $organizationId, string $type, array $payload, int $actorId): EmailTemplate
    {
        $this->assertSupportedType($type);

        return EmailTemplate::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'type' => $type,
            ],
            [
                'subject_template' => (string) $payload['subject_template'],
                'body_text_template' => (string) $payload['body_text_template'],
                'body_html_template' => (string) $payload['body_html_template'],
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'updated_by_actor_id' => $actorId,
            ],
        );
    }

    public function findTemplate(int $organizationId, string $type): ?EmailTemplate
    {
        $this->assertSupportedType($type);

        return EmailTemplate::query()
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->first();
    }

    public function applyTemplate(array $input): array
    {
        $organizationId = (int) ($input['organization_id'] ?? 0);
        $type = (string) ($input['type'] ?? 'custom');

        if ($organizationId <= 0 || $type === '' || $type === 'custom') {
            return $input;
        }

        if (! $this->isSupportedType($type)) {
            return $input;
        }

        // Wanneer een caller geen `template_vars` meestuurt gaan we ervan
        // uit dat `subject`, `body_text` en `body_html` reeds zijn
        // gerenderd (bv. via `render()` direct aangeroepen door
        // `AccountProvisioningService`). In dat geval mag deze service
        // niets meer overschrijven.
        if (! isset($input['template_vars']) || ! is_array($input['template_vars'])) {
            return $input;
        }

        $template = EmailTemplate::query()
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return $input;
        }

        $vars = $input['template_vars'];

        $input['subject'] = $this->renderString((string) $template->subject_template, $vars, escape: false);
        $input['body_text'] = $this->renderString((string) $template->body_text_template, $vars, escape: false);
        $input['body_html'] = $this->renderString((string) $template->body_html_template, $vars, escape: true);

        return $input;
    }

    /**
     * Rendert een template-type naar `subject`, `body_text` en `body_html`
     * voor een specifieke organisatie. Wanneer er nog geen organisatie-
     * specifieke template is geseed, valt de service terug op de
     * code-defined defaults uit `self::DEFAULT_TEMPLATES`.
     *
     * Placeholders worden ondersteund met of zonder spaties:
     * `{{name}}` en `{{ name }}` werken beide. HTML-bodies escapen
     * placeholder-waarden zodat user-input geen markup kan injecteren.
     *
     * @param  array<string, scalar|null>  $vars
     * @return array{subject: string, body_text: string, body_html: string}
     */
    public function render(string $type, array $vars, int $organizationId): array
    {
        $this->assertSupportedType($type);

        $template = EmailTemplate::query()
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        if ($template) {
            $subjectTpl = (string) $template->subject_template;
            $bodyTextTpl = (string) $template->body_text_template;
            $bodyHtmlTpl = (string) $template->body_html_template;
        } elseif (isset(self::DEFAULT_TEMPLATES[$type])) {
            $default = self::DEFAULT_TEMPLATES[$type];
            $subjectTpl = (string) $default['subject'];
            $bodyTextTpl = (string) $default['body_text'];
            $bodyHtmlTpl = (string) $default['body_html'];
        } else {
            throw ValidationException::withMessages([
                'type' => 'Geen template gevonden voor type '.$type.'.',
            ]);
        }

        return [
            'subject' => $this->renderString($subjectTpl, $vars, escape: false),
            'body_text' => $this->renderString($bodyTextTpl, $vars, escape: false),
            'body_html' => $this->renderString($bodyHtmlTpl, $vars, escape: true),
        ];
    }

    /**
     * Vervangt alle `{{ key }}` of `{{key}}` placeholders in `$template`
     * door de corresponderende waarde uit `$vars`. Onbekende
     * placeholders worden vervangen door een lege string. Bij
     * `escape = true` worden waarden HTML-geëscapet.
     *
     * @param  array<string, scalar|null>  $vars
     */
    private function renderString(string $template, array $vars, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            function (array $matches) use ($vars, $escape): string {
                $key = (string) $matches[1];
                $value = $vars[$key] ?? '';
                if (! is_scalar($value) && $value !== null) {
                    return '';
                }
                $valueString = (string) $value;

                return $escape ? e($valueString) : $valueString;
            },
            $template,
        );
    }

    public function isSupportedType(string $type): bool
    {
        return in_array($type, self::ALLOWED_TEMPLATE_TYPES, true);
    }

    private function assertSupportedType(string $type): void
    {
        if (! $this->isSupportedType($type)) {
            throw ValidationException::withMessages([
                'type' => 'Ongeldig e-mailtemplate type.',
            ]);
        }
    }
}
