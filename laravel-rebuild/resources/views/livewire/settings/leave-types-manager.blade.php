{{--
  Livewire-view — `Settings\LeaveTypesManager`

  Beheerpagina voor verlof-types per organisatie (alleen owner).
  Toont overzicht van verlof-types met CRUD-functionaliteit:
  aanmaken, bewerken en deactiveren (soft).
--}}

<div class="flex flex-col gap-6" data-livewire-component="settings.leave-types-manager">

    {{-- Header --}}
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1 tablet:flex-row tablet:items-center tablet:justify-between">
                <div class="flex flex-col gap-1">
                    <h1 class="text-heading-2 font-semibold text-ink">Verlof-types</h1>
                    <p class="text-body-sm text-steel">
                        Beheer de verlof-types voor uw organisatie.
                    </p>
                </div>
                <x-ui.button
                    variant="primary"
                    wire:click="openForm"
                >
                    Nieuw verlof-type
                </x-ui.button>
            </div>
        </x-slot:header>
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

    {{-- Formulier (aanmaken/bewerken) --}}
    @if ($showForm)
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-heading-3 font-semibold text-ink">
                    {{ $editingId ? 'Verlof-type bewerken' : 'Nieuw verlof-type' }}
                </h2>
            </x-slot:header>

            <form wire:submit="save" class="flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                    <x-ui.text-input
                        label="Code"
                        name="code"
                        type="text"
                        wire:model="code"
                        placeholder="Bijv. VAKANTIE"
                        :required="true"
                        :disabled="$editingId !== null"
                        :error="$errors->first('code') ?: null"
                    />

                    <x-ui.text-input
                        label="Naam"
                        name="name"
                        type="text"
                        wire:model="name"
                        placeholder="Bijv. Vakantieverlof"
                        :required="true"
                        :error="$errors->first('name') ?: null"
                    />
                </div>

                <x-ui.text-input
                    label="Beschrijving"
                    name="description"
                    type="text"
                    wire:model="description"
                    placeholder="Optionele toelichting"
                    :error="$errors->first('description') ?: null"
                />

                <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                    <x-ui.text-input
                        label="Maximum dagen per jaar"
                        name="maxDaysPerYear"
                        type="number"
                        wire:model="maxDaysPerYear"
                        placeholder="Leeg = onbeperkt"
                        :error="$errors->first('maxDaysPerYear') ?: null"
                    />

                    <div class="flex items-end pb-1">
                        <div class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="countsTowardsBalance"
                                wire:model="countsTowardsBalance"
                                class="h-4 w-4 rounded border-hairline text-brand-green focus:ring-2 focus:ring-brand-green/20"
                            />
                            <label for="countsTowardsBalance" class="text-body-sm text-ink">
                                Telt mee voor verlof-saldo
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="save">
                            {{ $editingId ? 'Opslaan' : 'Aanmaken' }}
                        </span>
                        <span wire:loading wire:target="save">Bezig...</span>
                    </x-ui.button>

                    <x-ui.button
                        type="button"
                        variant="secondary"
                        wire:click="cancelForm"
                    >
                        Annuleren
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Overzicht verlof-types --}}
    <x-ui.card>
        <x-slot:header>
            <h2 class="text-heading-3 font-semibold text-ink">Overzicht</h2>
        </x-slot:header>

        @if (empty($this->leaveTypes))
            <p class="text-body-md text-steel">
                Geen verlof-types gevonden. Klik op "Nieuw verlof-type" om er een aan te maken.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-body-sm" aria-label="Verlof-types overzicht">
                    <thead>
                        <tr class="border-b border-hairline text-left">
                            <th class="pb-2 pr-4 font-medium text-steel">Code</th>
                            <th class="pb-2 pr-4 font-medium text-steel">Naam</th>
                            <th class="pb-2 pr-4 font-medium text-steel">Max dagen/jaar</th>
                            <th class="pb-2 pr-4 font-medium text-steel">Telt voor saldo</th>
                            <th class="pb-2 pr-4 font-medium text-steel">Status</th>
                            <th class="pb-2 font-medium text-steel">
                                <span class="sr-only">Acties</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->leaveTypes as $leaveType)
                            <tr
                                class="border-b border-hairline last:border-b-0 {{ !$leaveType['is_active'] ? 'opacity-60' : '' }}"
                                wire:key="leave-type-{{ $leaveType['id'] }}"
                            >
                                <td class="py-2.5 pr-4 font-mono text-ink">
                                    {{ $leaveType['code'] }}
                                </td>
                                <td class="py-2.5 pr-4 text-ink">
                                    {{ $leaveType['name'] }}
                                    @if ($leaveType['description'])
                                        <span class="block text-xs text-steel">{{ Str::limit($leaveType['description'], 60) }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4 text-ink">
                                    {{ $leaveType['max_days_per_year'] ?? '—' }}
                                </td>
                                <td class="py-2.5 pr-4">
                                    @if ($leaveType['counts_towards_balance'])
                                        <x-ui.status-badge variant="success" icon>Ja</x-ui.status-badge>
                                    @else
                                        <x-ui.status-badge variant="concept">Nee</x-ui.status-badge>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4">
                                    @if ($leaveType['is_active'])
                                        <x-ui.status-badge variant="success" icon>Actief</x-ui.status-badge>
                                    @else
                                        <x-ui.status-badge variant="concept">Inactief</x-ui.status-badge>
                                    @endif
                                </td>
                                <td class="py-2.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($leaveType['is_active'])
                                            <x-ui.button
                                                variant="secondary"
                                                wire:click="editLeaveType({{ $leaveType['id'] }})"
                                            >
                                                Bewerken
                                            </x-ui.button>
                                            <x-ui.button
                                                variant="danger"
                                                wire:click="deactivate({{ $leaveType['id'] }})"
                                                wire:confirm="Weet je zeker dat je '{{ $leaveType['name'] }}' wilt deactiveren? Bestaande registraties blijven behouden."
                                            >
                                                Deactiveren
                                            </x-ui.button>
                                        @else
                                            <span class="text-xs text-steel italic">Gedeactiveerd</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
