{{--
  Livewire-view — `Settings\HolidaysManager`

  Beheerpagina voor feestdagen per jaar (alleen owner).
  Toont jaarselector, lijst van feestdagen, genereerknop en formulier
  voor het toevoegen van aangepaste feestdagen.
--}}
@php
    $availableYears = $this->getAvailableYears();
@endphp

<div class="flex flex-col gap-6" data-livewire-component="settings.holidays-manager">

    {{-- Header --}}
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 font-semibold text-ink">Feestdagen</h1>
                <p class="text-body-sm text-steel">
                    Beheer erkende feestdagen per jaar.
                </p>
            </div>
        </x-slot:header>

        {{-- Jaarselector --}}
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-body-sm font-medium text-ink">Jaar:</span>
            @foreach ($availableYears as $year)
                <x-ui.button
                    variant="{{ $selectedYear === $year ? 'primary' : 'secondary' }}"
                    wire:click="selectYear({{ $year }})"
                >
                    {{ $year }}
                </x-ui.button>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Feedback --}}
    @if ($confirmation)
        <div
            class="rounded-input border border-success-fg/20 bg-success-bg px-4 py-3 text-body-sm text-success-fg"
            role="status"
            aria-live="polite"
        >
            {{ $confirmation }}
        </div>
    @endif

    @if ($error)
        <div
            class="rounded-input border border-danger/20 bg-danger-bg px-4 py-3 text-body-sm text-danger-fg"
            role="alert"
            aria-live="polite"
        >
            {{ $error }}
        </div>
    @endif

    {{-- Acties --}}
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1 tablet:flex-row tablet:items-center tablet:justify-between">
                <h2 class="text-heading-3 font-semibold text-ink">
                    Feestdagen {{ $selectedYear }}
                </h2>
                <x-ui.button
                    variant="secondary"
                    wire:click="generateDefaults"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="generateDefaults">Genereer standaard feestdagen</span>
                    <span wire:loading wire:target="generateDefaults">Bezig...</span>
                </x-ui.button>
            </div>
        </x-slot:header>

        {{-- Feestdagenlijst --}}
        @if (empty($holidays))
            <p class="text-body-md text-steel">
                Geen feestdagen gevonden voor {{ $selectedYear }}.
                Klik op "Genereer standaard feestdagen" om de Nederlandse feestdagen toe te voegen.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-body-sm" aria-label="Feestdagen {{ $selectedYear }}">
                    <thead>
                        <tr class="border-b border-hairline text-left">
                            <th class="pb-2 pr-4 font-medium text-steel">Datum</th>
                            <th class="pb-2 pr-4 font-medium text-steel">Naam</th>
                            <th class="pb-2 pr-4 font-medium text-steel">Type</th>
                            <th class="pb-2 font-medium text-steel">
                                <span class="sr-only">Acties</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($holidays as $holiday)
                            <tr class="border-b border-hairline last:border-b-0" wire:key="holiday-{{ $holiday['id'] }}">
                                <td class="py-2.5 pr-4 text-ink">
                                    {{ \Carbon\Carbon::parse($holiday['date'])->format('d-m-Y') }}
                                </td>
                                <td class="py-2.5 pr-4 text-ink">
                                    {{ $holiday['name'] }}
                                </td>
                                <td class="py-2.5 pr-4">
                                    @if ($holiday['is_national'])
                                        <x-ui.status-badge variant="success" icon>Nationaal</x-ui.status-badge>
                                    @else
                                        <x-ui.status-badge variant="concept">Aangepast</x-ui.status-badge>
                                    @endif
                                </td>
                                <td class="py-2.5 text-right">
                                    <x-ui.button
                                        variant="danger"
                                        wire:click="deleteHoliday({{ $holiday['id'] }})"
                                        wire:confirm="Weet je zeker dat je '{{ $holiday['name'] }}' wilt verwijderen?"
                                    >
                                        Verwijderen
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Formulier: feestdag toevoegen --}}
    <x-ui.card>
        <x-slot:header>
            <h2 class="text-heading-3 font-semibold text-ink">Feestdag toevoegen</h2>
        </x-slot:header>

        <form wire:submit="addHoliday" class="flex flex-col gap-4">
            <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                <x-ui.text-input
                    label="Datum"
                    name="newDate"
                    type="date"
                    wire:model="newDate"
                    :required="true"
                    :error="$errors->first('newDate') ?: null"
                />

                <x-ui.text-input
                    label="Naam"
                    name="newName"
                    type="text"
                    wire:model="newName"
                    placeholder="Bijv. Bedrijfsdag"
                    :required="true"
                    :error="$errors->first('newName') ?: null"
                />
            </div>

            <div class="flex items-center gap-2">
                <input
                    type="checkbox"
                    id="newIsNational"
                    wire:model="newIsNational"
                    class="h-4 w-4 rounded border-hairline text-brand-green focus:ring-2 focus:ring-brand-green/20"
                />
                <label for="newIsNational" class="text-body-sm text-ink">
                    Nationale feestdag
                </label>
            </div>

            <div>
                <x-ui.button type="submit" variant="primary">
                    Toevoegen
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
