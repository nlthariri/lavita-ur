{{--
  Livewire-view — `Settings\OrganizationSettings`

  Formulier voor het bewerken van organisatie-instellingen.
  Alleen de owner-rol heeft toegang.
--}}

<div class="flex flex-col gap-6" data-livewire-component="settings.organization-settings">

    {{-- Bevestigingsmelding --}}
    @if ($confirmation)
        <div
            class="rounded-input border-2 border-brand-green bg-brand-green/10 px-4 py-3 text-body-sm text-ink"
            role="status"
            aria-live="polite"
        >
            {{ $confirmation }}
        </div>
    @endif

    {{-- Sectie 1: Algemeen --}}
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 font-semibold text-ink">Organisatie-instellingen</h1>
                <p class="text-body-sm text-steel">
                    Beheer de instellingen van {{ $organizationName ?: 'je organisatie' }}
                </p>
            </div>
        </x-slot:header>

        <form wire:submit="save" class="flex flex-col gap-8">

            {{-- Algemeen --}}
            <fieldset class="flex flex-col gap-4">
                <legend class="text-heading-3 font-semibold text-ink">Algemeen</legend>

                <x-ui.text-input
                    label="Organisatienaam"
                    name="name"
                    wire:model="name"
                    :required="true"
                    placeholder="Naam van de organisatie"
                    :error="$errors->first('name')"
                />

                <x-ui.text-input
                    label="KVK-nummer"
                    name="kvk_number"
                    wire:model="kvk_number"
                    placeholder="12345678"
                    :error="$errors->first('kvk_number')"
                    help="Optioneel, maximaal 8 tekens."
                />

                <x-ui.text-input
                    label="Loonheffingennummer"
                    name="loonheffingennummer"
                    wire:model="loonheffingennummer"
                    placeholder="123456789L01"
                    :error="$errors->first('loonheffingennummer')"
                    help="Optioneel, maximaal 12 tekens."
                />
            </fieldset>

            {{-- Retentie & Herinneringen --}}
            <fieldset class="flex flex-col gap-4">
                <legend class="text-heading-3 font-semibold text-ink">Retentie & Herinneringen</legend>

                <x-ui.text-input
                    label="Retentieperiode (jaren)"
                    name="retention_years"
                    type="number"
                    wire:model="retention_years"
                    :required="true"
                    :error="$errors->first('retention_years')"
                    help="Aantal jaren dat gegevens bewaard worden (1–99)."
                />

                <x-ui.text-input
                    label="Herinneringsdrempel (dagen)"
                    name="pending_input_threshold_days"
                    type="number"
                    wire:model="pending_input_threshold_days"
                    :required="true"
                    :error="$errors->first('pending_input_threshold_days')"
                    help="Na hoeveel dagen zonder invoer een herinnering wordt verstuurd (1–14)."
                />
            </fieldset>

            {{-- ATW-limieten --}}
            <fieldset class="flex flex-col gap-4">
                <legend class="text-heading-3 font-semibold text-ink">ATW-limieten</legend>
                <p class="text-body-sm text-steel">
                    Alle waarden in minuten. De omrekening naar uren wordt automatisch getoond.
                </p>

                <x-ui.text-input
                    label="Dagelijks maximum (minuten)"
                    name="atw_daily_max_minutes"
                    type="number"
                    wire:model.live="atw_daily_max_minutes"
                    :required="true"
                    :error="$errors->first('atw_daily_max_minutes')"
                    :help="'= ' . $this->minutesToHoursLabel((int) $atw_daily_max_minutes)"
                />

                <x-ui.text-input
                    label="Wekelijks maximum (minuten)"
                    name="atw_weekly_max_minutes"
                    type="number"
                    wire:model.live="atw_weekly_max_minutes"
                    :required="true"
                    :error="$errors->first('atw_weekly_max_minutes')"
                    :help="'= ' . $this->minutesToHoursLabel((int) $atw_weekly_max_minutes)"
                />

                <x-ui.text-input
                    label="Wekelijkse waarschuwingsgrens (minuten)"
                    name="atw_weekly_warning_minutes"
                    type="number"
                    wire:model.live="atw_weekly_warning_minutes"
                    :required="true"
                    :error="$errors->first('atw_weekly_warning_minutes')"
                    :help="'= ' . $this->minutesToHoursLabel((int) $atw_weekly_warning_minutes)"
                />

                <x-ui.text-input
                    label="16-weken gemiddelde maximum (minuten)"
                    name="atw_average_16_week_minutes"
                    type="number"
                    wire:model.live="atw_average_16_week_minutes"
                    :required="true"
                    :error="$errors->first('atw_average_16_week_minutes')"
                    :help="'= ' . $this->minutesToHoursLabel((int) $atw_average_16_week_minutes)"
                />
            </fieldset>

            {{-- Opslaan --}}
            <div class="flex items-center gap-4">
                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">
                    Opslaan
                </x-ui.button>

                <span wire:loading wire:target="save" class="text-body-sm text-steel">
                    Bezig met opslaan…
                </span>
            </div>
        </form>
    </x-ui.card>
</div>
