<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Settings\OrganizationSettings`
 *
 * Formulier voor het bewerken van organisatie-instellingen.
 * Alleen de owner-rol heeft toegang tot deze pagina.
 *
 * Secties:
 *  1. Algemeen — naam, KVK-nummer, loonheffingennummer
 *  2. Retentie & Herinneringen — retentiejaren, drempel openstaande invoer
 *  3. ATW-limieten — dagelijks max, wekelijks max, waarschuwing, 16-weken gemiddelde
 */
#[Layout('layouts.app')]
#[Title('Organisatie-instellingen — LaVita Urenregistratie')]
final class OrganizationSettings extends Component
{
    // Algemeen
    public string $name = '';

    public string $kvk_number = '';

    public string $loonheffingennummer = '';

    // Retentie & Herinneringen
    public int $retention_years = 7;

    public int $pending_input_threshold_days = 3;

    // ATW-limieten
    public int $atw_daily_max_minutes = 720;

    public int $atw_weekly_max_minutes = 3600;

    public int $atw_weekly_warning_minutes = 3000;

    public int $atw_average_16_week_minutes = 2880;

    /**
     * NL-bevestigingsmelding na opslaan.
     */
    public ?string $confirmation = null;

    /**
     * Naam van de organisatie — voor de header.
     */
    public string $organizationName = '';

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            abort(403, 'Alleen de eigenaar heeft toegang tot organisatie-instellingen.');
        }

        /** @var Organization|null $organization */
        $organization = $user->organization;

        if ($organization === null) {
            abort(403, 'Geen organisatie gekoppeld.');
        }

        $this->organizationName = (string) $organization->name;

        // Vul formuliervelden met huidige waarden
        $this->name = (string) $organization->name;
        $this->kvk_number = (string) ($organization->kvk_number ?? '');
        $this->loonheffingennummer = (string) ($organization->loonheffingennummer ?? '');
        $this->retention_years = (int) ($organization->retention_years ?? 7);
        $this->pending_input_threshold_days = (int) ($organization->pending_input_threshold_days ?? 3);
        $this->atw_daily_max_minutes = (int) ($organization->atw_daily_max_minutes ?? 720);
        $this->atw_weekly_max_minutes = (int) ($organization->atw_weekly_max_minutes ?? 3600);
        $this->atw_weekly_warning_minutes = (int) ($organization->atw_weekly_warning_minutes ?? 3000);
        $this->atw_average_16_week_minutes = (int) ($organization->atw_average_16_week_minutes ?? 2880);
    }

    /**
     * Validatieregels met NL-foutmeldingen.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kvk_number' => ['nullable', 'string', 'max:8'],
            'loonheffingennummer' => ['nullable', 'string', 'max:12'],
            'retention_years' => ['required', 'integer', 'min:1', 'max:99'],
            'pending_input_threshold_days' => ['required', 'integer', 'min:1', 'max:14'],
            'atw_daily_max_minutes' => ['required', 'integer', 'min:1'],
            'atw_weekly_max_minutes' => ['required', 'integer', 'min:1'],
            'atw_weekly_warning_minutes' => ['required', 'integer', 'min:1'],
            'atw_average_16_week_minutes' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * NL-foutmeldingen voor validatie.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'De organisatienaam is verplicht.',
            'name.max' => 'De organisatienaam mag maximaal 255 tekens bevatten.',
            'kvk_number.max' => 'Het KVK-nummer mag maximaal 8 tekens bevatten.',
            'loonheffingennummer.max' => 'Het loonheffingennummer mag maximaal 12 tekens bevatten.',
            'retention_years.required' => 'Het aantal retentiejaren is verplicht.',
            'retention_years.integer' => 'Het aantal retentiejaren moet een geheel getal zijn.',
            'retention_years.min' => 'Het aantal retentiejaren moet minimaal 1 zijn.',
            'retention_years.max' => 'Het aantal retentiejaren mag maximaal 99 zijn.',
            'pending_input_threshold_days.required' => 'De herinneringsdrempel is verplicht.',
            'pending_input_threshold_days.integer' => 'De herinneringsdrempel moet een geheel getal zijn.',
            'pending_input_threshold_days.min' => 'De herinneringsdrempel moet minimaal 1 dag zijn.',
            'pending_input_threshold_days.max' => 'De herinneringsdrempel mag maximaal 14 dagen zijn.',
            'atw_daily_max_minutes.required' => 'Het dagelijks ATW-maximum is verplicht.',
            'atw_daily_max_minutes.integer' => 'Het dagelijks ATW-maximum moet een geheel getal zijn.',
            'atw_daily_max_minutes.min' => 'Het dagelijks ATW-maximum moet minimaal 1 minuut zijn.',
            'atw_weekly_max_minutes.required' => 'Het wekelijks ATW-maximum is verplicht.',
            'atw_weekly_max_minutes.integer' => 'Het wekelijks ATW-maximum moet een geheel getal zijn.',
            'atw_weekly_max_minutes.min' => 'Het wekelijks ATW-maximum moet minimaal 1 minuut zijn.',
            'atw_weekly_warning_minutes.required' => 'De ATW-waarschuwingsgrens is verplicht.',
            'atw_weekly_warning_minutes.integer' => 'De ATW-waarschuwingsgrens moet een geheel getal zijn.',
            'atw_weekly_warning_minutes.min' => 'De ATW-waarschuwingsgrens moet minimaal 1 minuut zijn.',
            'atw_average_16_week_minutes.required' => 'Het 16-weken gemiddelde is verplicht.',
            'atw_average_16_week_minutes.integer' => 'Het 16-weken gemiddelde moet een geheel getal zijn.',
            'atw_average_16_week_minutes.min' => 'Het 16-weken gemiddelde moet minimaal 1 minuut zijn.',
        ];
    }

    /**
     * Sla de organisatie-instellingen op.
     */
    public function save(): void
    {
        $this->confirmation = null;
        $this->resetErrorBag();

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            abort(403, 'Alleen de eigenaar kan organisatie-instellingen bewerken.');
        }

        $this->validate();

        /** @var Organization $organization */
        $organization = $user->organization;

        $organization->update([
            'name' => trim($this->name),
            'kvk_number' => trim($this->kvk_number) !== '' ? trim($this->kvk_number) : null,
            'loonheffingennummer' => trim($this->loonheffingennummer) !== '' ? trim($this->loonheffingennummer) : null,
            'retention_years' => $this->retention_years,
            'pending_input_threshold_days' => $this->pending_input_threshold_days,
            'atw_daily_max_minutes' => $this->atw_daily_max_minutes,
            'atw_weekly_max_minutes' => $this->atw_weekly_max_minutes,
            'atw_weekly_warning_minutes' => $this->atw_weekly_warning_minutes,
            'atw_average_16_week_minutes' => $this->atw_average_16_week_minutes,
        ]);

        $this->organizationName = trim($this->name);
        $this->confirmation = 'Organisatie-instellingen opgeslagen.';
    }

    /**
     * Helper: converteer minuten naar leesbare uren-tekst.
     */
    public function minutesToHoursLabel(int $minutes): string
    {
        $hours = $minutes / 60;

        if ($hours === floor($hours)) {
            return ((int) $hours) . ' uur';
        }

        return number_format($hours, 1, ',', '.') . ' uur';
    }

    public function render(): View
    {
        return view('livewire.settings.organization-settings');
    }
}
