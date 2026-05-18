{{--
  Livewire-view — `Hours\LeaveOverview`

  Overzichtspagina voor verlof-/ziektemeldingen.
  Toont een tabel met status, filters en goedkeur-/afwijsacties
  voor managers en owners.

  Status-logica:
   - In afwachting: is_finalized = false AND deleted_at IS NULL → concept badge
   - Goedgekeurd:   is_finalized = true AND deleted_at IS NULL → success badge
   - Afgewezen:     deleted_at IS NOT NULL                     → danger badge
--}}
<div class="flex flex-col gap-4" data-livewire-component="hours.leave-overview">
    @php
        $authUser = \Illuminate\Support\Facades\Auth::user();
        $isManagerOrOwner = in_array($userRole, ['owner', 'manager'], true);

        $typeLabels = [
            'SICK' => 'Ziek',
            'LEAVE' => 'Verlof',
            'HOLIDAY' => 'Feestdag',
        ];
    @endphp

    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                <h1 class="text-heading-2 font-semibold text-ink">
                    Verlofoverzicht
                </h1>
                <a
                    href="/verlof"
                    class="inline-flex items-center gap-1 text-body-sm text-steel no-underline hover:text-ink"
                >
                    ← Verlof registreren
                </a>
            </div>
        </x-slot:header>

        {{-- Filters --}}
        <div class="mb-4 flex flex-col gap-3 rounded-input border border-hairline bg-surface p-3">
            <p class="text-body-sm font-medium text-ink">Filters</p>
            <div class="grid grid-cols-1 gap-3 tablet:grid-cols-4">
                {{-- Status-filter --}}
                <div class="flex flex-col gap-1">
                    <label for="filter-status" class="text-body-sm text-steel">Status</label>
                    <select
                        id="filter-status"
                        wire:model.live="filterStatus"
                        class="block h-9 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">Alle</option>
                        <option value="pending">In afwachting</option>
                        <option value="approved">Goedgekeurd</option>
                        <option value="rejected">Afgewezen</option>
                    </select>
                </div>

                {{-- Type-filter --}}
                <div class="flex flex-col gap-1">
                    <label for="filter-type" class="text-body-sm text-steel">Type</label>
                    <select
                        id="filter-type"
                        wire:model.live="filterType"
                        class="block h-9 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">Alle</option>
                        <option value="SICK">Ziek</option>
                        <option value="LEAVE">Verlof</option>
                        <option value="HOLIDAY">Feestdag</option>
                    </select>
                </div>

                {{-- Datum van --}}
                <div class="flex flex-col gap-1">
                    <label for="filter-date-from" class="text-body-sm text-steel">Vanaf</label>
                    <input
                        id="filter-date-from"
                        type="date"
                        wire:model.live="filterDateFrom"
                        class="block h-9 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                </div>

                {{-- Datum tot --}}
                <div class="flex flex-col gap-1">
                    <label for="filter-date-to" class="text-body-sm text-steel">Tot</label>
                    <input
                        id="filter-date-to"
                        type="date"
                        wire:model.live="filterDateTo"
                        class="block h-9 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                </div>
            </div>

            @if ($filterStatus !== '' || $filterType !== '' || $filterDateFrom !== '' || $filterDateTo !== '')
                <div class="flex">
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        wire:click="resetFilters"
                        class="text-body-sm"
                    >Filters wissen</x-ui.button>
                </div>
            @endif
        </div>

        {{-- Bevestigingsbanner --}}
        @if ($confirmation !== null && $confirmation !== '')
            <p
                role="status"
                aria-live="polite"
                class="mb-4 rounded-input border border-success/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
            >{{ $confirmation }}</p>
        @endif

        {{-- Tabel --}}
        @if ($entries->isEmpty())
            <p class="py-8 text-center text-body-sm text-steel">
                Geen verlofmeldingen gevonden.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-body-sm">
                    <thead>
                        <tr class="border-b border-hairline">
                            <th class="px-3 py-2 font-medium text-steel">Medewerker</th>
                            <th class="px-3 py-2 font-medium text-steel">Type</th>
                            <th class="px-3 py-2 font-medium text-steel">Datum</th>
                            <th class="px-3 py-2 font-medium text-steel">Status</th>
                            <th class="px-3 py-2 font-medium text-steel">Motivatie</th>
                            @if ($isManagerOrOwner)
                                <th class="px-3 py-2 font-medium text-steel">Acties</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            @php
                                // Status bepalen
                                $isRejected = $entry->deleted_at !== null;
                                $isApproved = ! $isRejected && (bool) $entry->is_finalized;
                                $isPending = ! $isRejected && ! (bool) $entry->is_finalized;

                                $statusVariant = $isRejected ? 'danger' : ($isApproved ? 'success' : 'concept');
                                $statusLabel = $isRejected ? 'Afgewezen' : ($isApproved ? 'Goedgekeurd' : 'In afwachting');
                            @endphp
                            <tr class="border-b border-hairline last:border-b-0" wire:key="entry-{{ $entry->id }}">
                                <td class="px-3 py-2 text-ink">
                                    {{ $entry->employee?->full_name ?? $entry->employee?->name ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-ink">
                                    {{ $typeLabels[$entry->type] ?? $entry->type }}
                                </td>
                                <td class="px-3 py-2 text-ink whitespace-nowrap">
                                    {{ $entry->entry_date?->format('d-m-Y') ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.status-badge :variant="$statusVariant" icon>
                                        {{ $statusLabel }}
                                    </x-ui.status-badge>
                                </td>
                                <td class="max-w-[200px] truncate px-3 py-2 text-steel" title="{{ $entry->note ?? '' }}">
                                    {{ $entry->note ?? '—' }}
                                </td>
                                @if ($isManagerOrOwner)
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            @if ($isPending)
                                                <x-ui.button
                                                    type="button"
                                                    variant="primary"
                                                    wire:click="approve({{ $entry->id }})"
                                                    wire:loading.attr="disabled"
                                                    class="!px-3 !py-1 text-body-sm"
                                                >Goedkeuren</x-ui.button>
                                                <x-ui.button
                                                    type="button"
                                                    variant="danger"
                                                    wire:click="reject({{ $entry->id }})"
                                                    wire:loading.attr="disabled"
                                                    class="!px-3 !py-1 text-body-sm"
                                                >Afwijzen</x-ui.button>
                                            @elseif ($isApproved)
                                                <span class="text-body-sm text-steel">—</span>
                                            @elseif ($isRejected)
                                                <x-ui.button
                                                    type="button"
                                                    variant="secondary"
                                                    wire:click="approve({{ $entry->id }})"
                                                    wire:loading.attr="disabled"
                                                    class="!px-3 !py-1 text-body-sm"
                                                >Herstellen</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Paginering --}}
            @if ($entries->hasPages())
                <div class="mt-4 flex justify-center">
                    {{ $entries->links() }}
                </div>
            @endif
        @endif
    </x-ui.card>
</div>
