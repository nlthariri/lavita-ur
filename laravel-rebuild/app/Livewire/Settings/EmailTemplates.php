<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Reports\Filters;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Settings\EmailTemplates` (taak 12.4 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.12 → scherm "E-mailcycli beheer" op `/instellingen/email`
 *      waarop owner alle 11 templates kan bekijken en bewerken.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "E-mailcycli beheer" → component `Settings\EmailTemplates`
 *      op `/instellingen/email`.
 *  - tasks.md 12.4.
 *
 * Verantwoordelijkheid:
 *  - Lijst van 11 e-mailtemplate-types met hun huidige subject en status.
 *  - Inline-editor met monospace-font (Geist Mono) voor het bewerken van
 *    subject, body_text en body_html per type.
 *  - Opslaan via de bestaande `EmailTemplateService::upsertTemplate()`
 *    die dezelfde logica uitvoert als
 *    `PUT /api/internal/email/templates/{type}`.
 *
 * Autorisatie:
 *  - Alleen owner mag e-mailtemplates bewerken (req 6.12: "owner alle
 *    templates kan bekijken en bewerken"). Managers mogen lezen maar niet
 *    schrijven — het PUT-endpoint staat dit wel toe, maar req 6.12 spreekt
 *    expliciet over "owner". We staan managers toe om de lijst te bekijken
 *    (consistent met het API-endpoint dat owner+manager toestaat) maar
 *    blokkeren het opslagpad voor niet-owners.
 *  - Employees en boekhouders hebben geen toegang (403).
 *
 * Spec-deviation — directe service-call i.p.v. HTTP PUT:
 *  Het taak-spec-fragment 12.4 specificeert "opslaan via PUT
 *  /api/internal/email/templates/{type}". De feitelijke backend-route is
 *  beveiligd via bearer-token-auth (de `internal.auth`-middleware-groep).
 *  Een Livewire-component op de web-stack heeft die bearer-token niet in
 *  scope. Daarom roepen we `EmailTemplateService::upsertTemplate()` direct
 *  aan — dezelfde codepath die de HTTP-controller intern uitvoert. Zelfde
 *  patroon als {@see Filters} (directe service-call
 *  i.p.v. HTTP-roundtrip).
 */
#[Layout('layouts.app')]
#[Title('E-mailtemplates — LaVita Urenregistratie')]
final class EmailTemplates extends Component
{
    /**
     * De 11 template-types die de UI moet tonen (req 6.12).
     * Volgorde is bewust logisch gegroepeerd: account-gerelateerd,
     * werkregel-gerelateerd, ATW, rapportage, overig.
     */
    public const TEMPLATE_TYPES = [
        'welcome_email',
        'password_reset',
        'work_entry_finalized',
        'work_entry_updated',
        'work_entry_deleted',
        'objection_review',
        'atw_warning',
        'atw_critical',
        'pending_input_reminder',
        'monthly_report',
        'anniversary',
    ];

    /**
     * NL-labels voor de template-types — voor de lijstweergave.
     */
    public const TYPE_LABELS = [
        'welcome_email' => 'Welkomstmail',
        'password_reset' => 'Wachtwoord reset',
        'work_entry_finalized' => 'Werkregel vastgesteld',
        'work_entry_updated' => 'Werkregel bijgewerkt',
        'work_entry_deleted' => 'Werkregel verwijderd',
        'objection_review' => 'Bezwaar beoordeeld',
        'atw_warning' => 'ATW-waarschuwing',
        'atw_critical' => 'ATW-overschrijding',
        'pending_input_reminder' => 'Herinnering openstaande invoer',
        'monthly_report' => 'Maandrapportage',
        'anniversary' => 'Jubileum',
    ];

    /**
     * Het huidig geselecteerde template-type dat in de editor wordt
     * getoond. `null` betekent: geen editor open (alleen de lijst).
     */
    public ?string $selectedType = null;

    /**
     * Editor-velden — worden gevuld bij selectie van een type.
     */
    public string $subjectTemplate = '';

    public string $bodyTextTemplate = '';

    public string $bodyHtmlTemplate = '';

    /**
     * NL-bevestigingsmelding na opslaan (bv. "Template opgeslagen.").
     */
    public ?string $confirmation = null;

    /**
     * NL-foutmelding bij opslaan (bv. "Ongeldig e-mailtemplate type.").
     */
    public ?string $saveError = null;

    /**
     * Naam van de organisatie — voor de header.
     */
    public string $organizationName = '';

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `employee` en `boekhouder` — zij hebben geen
     *     toegang tot template-instellingen.
     *  3. Cache `$organizationName` voor de header.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $role = (string) $user->role;
        if ($role === 'employee' || $role === 'boekhouder') {
            abort(403, 'Geen toegang tot e-mailtemplates.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');
    }

    /**
     * Selecteer een template-type voor bewerking. Laadt de huidige
     * waarden uit de database (of lege strings als er nog geen record
     * bestaat voor dit type binnen de organisatie).
     */
    public function selectType(string $type): void
    {
        $this->confirmation = null;
        $this->saveError = null;
        $this->resetErrorBag();

        if (! in_array($type, self::TEMPLATE_TYPES, true)) {
            $this->addError('type', 'Ongeldig template-type.');

            return;
        }

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $this->selectedType = $type;

        $template = EmailTemplate::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('type', $type)
            ->first();

        if ($template !== null) {
            $this->subjectTemplate = (string) $template->subject_template;
            $this->bodyTextTemplate = (string) $template->body_text_template;
            $this->bodyHtmlTemplate = (string) $template->body_html_template;
        } else {
            $this->subjectTemplate = '';
            $this->bodyTextTemplate = '';
            $this->bodyHtmlTemplate = '';
        }
    }

    /**
     * Sluit de editor — terug naar de lijst.
     */
    public function closeEditor(): void
    {
        $this->selectedType = null;
        $this->subjectTemplate = '';
        $this->bodyTextTemplate = '';
        $this->bodyHtmlTemplate = '';
        $this->confirmation = null;
        $this->saveError = null;
        $this->resetErrorBag();
    }

    /**
     * Sla het huidige template op via `EmailTemplateService::upsertTemplate()`.
     *
     * Validatie:
     *  - Alleen owner mag opslaan.
     *  - `subject_template` is verplicht, max 500 tekens.
     *  - `body_text_template` is verplicht.
     *  - `body_html_template` is verplicht.
     */
    public function saveTemplate(EmailTemplateService $emailTemplateService): void
    {
        $this->confirmation = null;
        $this->saveError = null;
        $this->resetErrorBag();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            $this->saveError = 'Alleen de eigenaar kan e-mailtemplates bewerken.';

            return;
        }

        if ($this->selectedType === null) {
            $this->saveError = 'Selecteer eerst een template-type.';

            return;
        }

        // Validate
        $subjectTrimmed = trim($this->subjectTemplate);
        $bodyTextTrimmed = trim($this->bodyTextTemplate);
        $bodyHtmlTrimmed = trim($this->bodyHtmlTemplate);

        if ($subjectTrimmed === '') {
            $this->addError('subjectTemplate', 'Het onderwerp is verplicht.');

            return;
        }
        if (mb_strlen($subjectTrimmed) > 500) {
            $this->addError('subjectTemplate', 'Het onderwerp mag maximaal 500 tekens bevatten.');

            return;
        }
        if ($bodyTextTrimmed === '') {
            $this->addError('bodyTextTemplate', 'De tekst-body is verplicht.');

            return;
        }
        if ($bodyHtmlTrimmed === '') {
            $this->addError('bodyHtmlTemplate', 'De HTML-body is verplicht.');

            return;
        }

        try {
            $emailTemplateService->upsertTemplate(
                (int) $user->organization_id,
                $this->selectedType,
                [
                    'subject_template' => $subjectTrimmed,
                    'body_text_template' => $bodyTextTrimmed,
                    'body_html_template' => $bodyHtmlTrimmed,
                    'is_active' => true,
                ],
                (int) $user->id,
            );

            $this->confirmation = 'Template opgeslagen.';
        } catch (ValidationException $e) {
            $this->saveError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->saveError = 'Er is een fout opgetreden bij het opslaan.';
        }
    }

    /**
     * Haal de bestaande templates op voor de huidige organisatie.
     * Retourneert een map van `[type => EmailTemplate]` voor de types
     * die al in de database bestaan.
     *
     * @return array<string, EmailTemplate>
     */
    public function getExistingTemplates(): array
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return [];
        }

        $templates = EmailTemplate::query()
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('type', self::TEMPLATE_TYPES)
            ->get();

        $map = [];
        foreach ($templates as $template) {
            $map[(string) $template->type] = $template;
        }

        return $map;
    }

    public function render(): View
    {
        return view('livewire.settings.email-templates');
    }
}
