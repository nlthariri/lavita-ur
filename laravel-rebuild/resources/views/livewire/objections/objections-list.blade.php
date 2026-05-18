{{--
  Livewire-view — `Objections\ObjectionsList`

  Lijst-overzicht van bezwaren met zoek- en statusfilter.
  Doorklik naar review-formulier per bezwaar.
--}}
@php
    /** @var \Illuminate\Support\ViewErrorBag $errors */
    $objections = $this->getObjections();
    $statusOptions = $this->getStatusOptions();
    $isOwnerOrManager = in_array(auth()->user()?->role, ['owner', 'manager'], true);
@endphp

<div class="flex flex-col gap-4" data-livewire-component="objections.objections-list">
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                <div class="flex flex-col gap-1">
                    <h1 class="text-heading-2 font-semibold text-ink">Bezwaren</h1>
                    <p class="text-body-sm text-steel">
                        Overzicht van ingediende bezwaren
                        @if (! empty($organizationName))
                            — {{ $organizationName }}
                        @endif
                    </p>
                </div>
            </div>
        </x-slot:header>

        {{-- Filters --}}
        <div class="flex flex-col gap-3 tablet:flex-row tablet:items-end tablet:gap-4">
            <div class="flex flex-1 flex-col gap-1">
                <label for="objections-search" class="text-body-sm font-medium text-ink">
                    Zoeken
                </label>
                <input
                    id="objections-search"
                    type="search"
                    placeholder="Zoek op naam, motivatie of ID…"
                    wire:model.live.debounce.250ms="search"
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                />
            </div>

            <div class="flex flex-col gap-1">
                <label for="objections-status-filter" class="text-body-sm font-medium text-ink">
                    Status
                </label>
                <select
                    id="objections-status-filter"
                    wire:model.live="statusFilter"
                    class="block rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                >
                    <option value="">Alle statussen</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Resultaten --}}
        @if ($objections->isEmpty())
            <div class="mt-6 rounded-input border border-hairline bg-surface px-4 py-8 text-center">
                <p class="text-body-md text-steel">
                    @if ($search !== '' || $statusFilter !== null)
                        Geen bezwaren gevonden met de huidige filters.
                    @else
                        Er zijn nog geen bezwaren ingediend.
                    @endif
                </p>
            </div>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-body-sm">
                    <caption class="sr-only">Overzicht van bezwaren</caption>
                    <thead>
                        <tr class="border-b border-hairline">
                            <th scope="col" class="px-3 py-2 font-medium text-steel">#</th>
                            <th scope="col" class="px-3 py-2 font-medium text-steel">Medewerker</th>
                            <th scope="col" class="px-3 py-2 font-medium text-steel">Datum</th>
                            <th scope="col" class="px-3 py-2 font-medium text-steel">Motivatie</th>
                            <th scope="col" class="px-3 py-2 font-medium text-steel">Status</th>
                            @if ($isOwnerOrManager)
                                <th scope="col" class="px-3 py-2 font-medium text-steel">Actie</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($objections as $objection)
                            @php
                                $employee = $objection->workEntry?->employee;
                                $employeeName = $employee?->full_name ?? $employee?->name ?? '—';
                                $entryDate = $objection->workEntry?->entry_date;
                                $dateDisplay = $entryDate instanceof \Carbon\Carbon
                                    ? $entryDate->format('d-m-Y')
                                    : ($entryDate ? (string) $entryDate : '—');
                                $motivation = $objection->motivation ?? '';
                                $motivationPreview = mb_strlen($motivation) > 60
                                    ? mb_substr($motivation, 0, 60) . '…'
                                    : $motivation;
                                $status = (string) $objection->status;
                            @endphp
                            <tr class="border-b border-hairline hover:bg-surface/50">
                                <td class="px-3 py-2 font-mono text-steel">{{ $objection->id }}</td>
                                <td class="px-3 py-2 text-ink">{{ $employeeName }}</td>
                                <td class="px-3 py-2 font-mono text-ink">{{ $dateDisplay }}</td>
                                <td class="px-3 py-2 text-ink" title="{{ $motivation }}">
                                    {{ $motivationPreview ?: '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.status-badge :variant="$this->variantForStatus($status)">
                                        {{ $this->labelForStatus($status) }}
                                    </x-ui.status-badge>
                                </td>
                                @if ($isOwnerOrManager)
                                    <td class="px-3 py-2">
                                        <a
                                            href="/bezwaren/{{ $objection->id }}"
                                            class="text-body-sm font-medium text-primary no-underline hover:underline"
                                        >
                                            @if ($status === 'OPEN')
                                                Beoordelen
                                            @else
                                                Bekijken
                                            @endif
                                        </a>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
