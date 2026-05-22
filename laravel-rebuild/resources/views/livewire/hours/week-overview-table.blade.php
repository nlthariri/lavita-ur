{{--
  Livewire-view — `Hours\WeekOverviewTable` (taak 7.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 4.1-4.10 → kleurcodering per type, totalen, tooltips,
       ATW-waarschuwingen, horizontaal scrollbaar bij >15 medewerkers.
   - requirements.md 6.2  → tabel: rijen = medewerkers, kolommen = ma..zo.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-bevestigingen.

  Features:
   - Color_Coding per cel: WORK=emerald-50/brand-green, SICK=red-50/danger,
     LEAVE=blue-50/blue-500, HOLIDAY=purple-50/purple-500.
   - Lege cellen: border-dashed border-hairline bg-canvas, klikbaar → open invoer-modal.
   - Totaal-kolom per medewerker (weeksom HH:mm), totaal-rij per dag, Grand_Total.
   - Visueel onderscheid totalen: bg-surface + font-bold.
   - Tooltips bij hover (type + netto voor gevulde cellen, instructie voor lege).
   - ATW-waarschuwing: oranje rand + icoon bij cellen met violations.
   - Horizontaal scrollbaar bij >15 medewerkers, sticky eerste kolom.
--}}
@php
    /** @var \Carbon\Carbon[] $weekDates */
    $weekDates = $this->getWeekDates();
    $monday = $weekDates[0];
    $sunday = $weekDates[6];

    /** @var \Illuminate\Support\Collection $employees */
    $employees = $this->getEmployees();

    /** @var \Illuminate\Support\Collection $teams */
    $teams = $this->getAvailableTeams();

    /** @var array<string, string> $holidaysMap — [Y-m-d => naam] */
    $holidaysMap = $this->getHolidaysForWeek();

    // NL-afkortingen voor de dagkoppen
    $dayLabels = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];

    // Bepaal of horizontaal scrollen nodig is (>15 medewerkers)
    $needsHorizontalScroll = $employees->count() > 15;

    // Weeknummer voor print-header (Requirement 14.5)
    $weekNumber = $monday->isoWeek();
@endphp

<div class="flex flex-col gap-4" data-livewire-component="hours.week-overview-table">
    {{-- Print-specifieke CSS (Requirements 14.2, 14.3, 14.4) --}}
    @push('head')
    <style>
        @media print {
            /* Verberg navigatie, sidebar, toolbar, footer en niet-relevante elementen (Req 14.3) */
            header[role="banner"],
            nav[aria-label="Hoofdnavigatie"],
            aside[aria-label="Inhoudsopgave"],
            footer[role="contentinfo"],
            [data-livewire-component="hours.week-overview-table"] .flex.flex-wrap.items-end,
            .sr-only[href="#main"] {
                display: none !important;
            }

            /* Reset grid naar single column voor print (Req 14.3) */
            body {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Verwijder grid-layout zodat alleen content overblijft */
            .grid {
                display: block !important;
            }

            /* Behoud kleurcodering bij printen (Req 14.4) */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }

            /* Optimaliseer tabel voor A4 landscape */
            table {
                width: 100% !important;
                font-size: 10pt !important;
            }

            /* Verberg modals en entry-form bij print */
            [role="dialog"],
            [x-data*="toastManager"] {
                display: none !important;
            }

            /* Pagina-instelling: landscape voor brede tabel */
            @page {
                size: A4 landscape;
                margin: 1.5cm;
            }
        }
    </style>
    @endpush
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">
                    Weekoverzicht uren — week van {{ $monday->format('d-m-Y') }}
                </h1>
                @if ($organizationName !== '')
                    <p class="text-body-sm text-steel">{{ $organizationName }}</p>
                @endif
            </div>
        </x-slot:header>

        {{-- Navigatie + team-filter + copy-week --}}
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex flex-wrap gap-2">
                <x-ui.button
                    variant="secondary"
                    type="button"
                    wire:click="previousWeek"
                    aria-label="Ga naar vorige week"
                >
                    Vorige week
                </x-ui.button>
                <x-ui.button
                    variant="secondary"
                    type="button"
                    wire:click="goToToday"
                    aria-label="Spring naar deze week"
                >
                    Vandaag
                </x-ui.button>
                <x-ui.button
                    variant="secondary"
                    type="button"
                    wire:click="nextWeek"
                    aria-label="Ga naar volgende week"
                >
                    Volgende week
                </x-ui.button>

                {{-- Copy-week knop: alleen zichtbaar voor owner/manager (Req 5.1, 5.8) --}}
                @if ($this->canCopyWeek())
                    <x-ui.button
                        variant="secondary"
                        type="button"
                        wire:click="openCopyWeekModal"
                        :disabled="$copyWeekLoading"
                        :loading="$copyWeekLoading"
                        aria-label="Kopieer werkregels van vorige week naar huidige week"
                    >
                        Kopieer vorige week
                    </x-ui.button>
                @endif

                {{-- Print-knop (Requirement 14.1): triggert window.print() --}}
                <x-ui.button
                    variant="secondary"
                    type="button"
                    x-on:click="window.print()"
                    aria-label="Print weekoverzicht"
                    class="print:hidden"
                >
                    Printen
                </x-ui.button>
            </div>

            @if ($teams->count() > 1)
                @php
                    $teamFilterError = $errors->first('teamFilter');
                @endphp
                <div class="flex flex-col gap-1">
                    <label
                        for="week-overview-team-filter"
                        class="text-body-sm font-medium text-ink"
                    >
                        Team
                    </label>
                    <select
                        id="week-overview-team-filter"
                        name="team_filter"
                        wire:change="setTeamFilter($event.target.value === '' ? null : Number($event.target.value))"
                        @if ($teamFilterError) aria-invalid="true" aria-describedby="week-overview-team-filter-error" @endif
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">Alle teams</option>
                        @foreach ($teams as $team)
                            <option
                                value="{{ $team->id }}"
                                @selected($teamFilter === (int) $team->id)
                            >{{ $team->name }}</option>
                        @endforeach
                    </select>
                    @if ($teamFilterError)
                        <p
                            id="week-overview-team-filter-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >
                            {{ $teamFilterError }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- Hoofdcontent: tabel of lege-staat --}}
        @if ($employees->isEmpty())
            <p class="mt-6 text-body-md text-steel">
                Geen medewerkers gevonden voor de huidige scope.
            </p>
        @else
            <div class="mt-6 overflow-x-auto" role="region" aria-label="Weekoverzicht tabel" tabindex="0">
                <table
                    class="w-full min-w-[720px] border-collapse text-left text-body-sm"
                    role="table"
                    aria-label="Weekoverzicht uren per medewerker"
                >
                    <caption class="sr-only">
                        Uren van {{ $monday->format('d-m-Y') }} t/m {{ $sunday->format('d-m-Y') }}.
                    </caption>
                    <thead>
                        <tr class="border-b border-hairline">
                            <th
                                scope="col"
                                id="th-employee"
                                class="py-2 pr-4 align-bottom font-medium text-ink {{ $needsHorizontalScroll ? 'sticky left-0 z-10 bg-canvas' : '' }}"
                            >
                                Medewerker
                            </th>
                            @foreach ($weekDates as $i => $date)
                                @php
                                    $dayId = 'th-day-'.$date->toDateString();
                                    $isHolidayHeader = isset($holidaysMap[$date->toDateString()]);
                                @endphp
                                <th
                                    scope="col"
                                    id="{{ $dayId }}"
                                    class="px-2 py-2 align-bottom font-medium text-ink {{ $isHolidayHeader ? 'bg-gray-100' : '' }}"
                                    @if ($isHolidayHeader) title="{{ $holidaysMap[$date->toDateString()] }}" @endif
                                >
                                    <span class="block text-button-md">{{ $dayLabels[$i] }}</span>
                                    <span class="block text-body-sm font-normal text-steel">
                                        {{ $date->format('d-m') }}
                                    </span>
                                </th>
                            @endforeach
                            {{-- Totaal-kolom header --}}
                            <th
                                scope="col"
                                id="th-total"
                                class="px-2 py-2 align-bottom font-bold text-ink bg-surface"
                            >
                                Totaal
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            @php
                                $employeeName = $employee->full_name ?: $employee->name;
                                $rowId = 'th-emp-'.$employee->id;
                                $weekTotal = $this->getWeekTotalForEmployee((int) $employee->id);
                                $employeeAtwStatus = $this->getAtwStatusForEmployee((int) $employee->id);
                                $employeeAtwTooltip = $employeeAtwStatus ? $this->getAtwTooltipForEmployee((int) $employee->id) : '';
                            @endphp
                            <tr class="border-b border-hairline last:border-b-0">
                                <th
                                    scope="row"
                                    id="{{ $rowId }}"
                                    class="py-3 pr-4 align-top font-medium text-ink {{ $needsHorizontalScroll ? 'sticky left-0 z-10 bg-canvas' : '' }}"
                                >
                                    <span class="inline-flex items-center gap-1.5">
                                        {{ $employeeName }}
                                        @if ($employeeAtwStatus !== null)
                                            {{-- ATW-indicator naast medewerker-naam (Req 15.1, 15.2) --}}
                                            @if ($employeeAtwStatus['severity'] === 'critical')
                                                {{-- Rood uitroepteken voor critical --}}
                                                <span
                                                    class="inline-flex items-center"
                                                    title="{{ $employeeAtwTooltip }}"
                                                    aria-label="ATW-overtreding: {{ $employeeAtwTooltip }}"
                                                >
                                                    <svg class="h-4 w-4 text-danger" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                                    </svg>
                                                </span>
                                            @else
                                                {{-- Oranje driehoek voor warning --}}
                                                <span
                                                    class="inline-flex items-center"
                                                    title="{{ $employeeAtwTooltip }}"
                                                    aria-label="ATW-waarschuwing: {{ $employeeAtwTooltip }}"
                                                >
                                                    <svg class="h-4 w-4 text-warning" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                                    </svg>
                                                </span>
                                            @endif
                                        @endif
                                    </span>
                                </th>
                                @foreach ($weekDates as $date)
                                    @php
                                        $iso = $date->toDateString();
                                        $type = $this->getTypeForCell((int) $employee->id, $iso);
                                        $colorClasses = \App\Livewire\Hours\WeekOverviewTable::getColorCodingClasses($type);
                                        $minutes = $this->getNetMinutesForCell((int) $employee->id, $iso);
                                        $tooltip = $this->getTooltipForCell((int) $employee->id, $employeeName, $iso);
                                        $atwViolation = $this->getAtwViolationForCell((int) $employee->id, $iso);
                                        $entryId = $this->getEntryIdForCell((int) $employee->id, $iso);
                                        $dayId = 'th-day-'.$iso;
                                        $isEmpty = $type === null;

                                        // ATW-waarschuwing styling (Req 15.4: oranje of rode rand)
                                        $atwBorderClass = '';
                                        if ($atwViolation === 'critical') {
                                            $atwBorderClass = 'ring-2 ring-danger ring-inset';
                                        } elseif ($atwViolation === 'warning') {
                                            $atwBorderClass = 'ring-2 ring-warning ring-inset';
                                        }
                                    @endphp
                                    <td
                                        class="px-2 py-3 align-top cursor-pointer transition-colors hover:opacity-80 {{ $colorClasses['bg'] }} {{ $colorClasses['border'] }} {{ $atwBorderClass }}"
                                        headers="{{ $rowId }} {{ $dayId }}"
                                        title="{{ $tooltip }}"
                                        wire:click="$dispatch('open-entry-form-modal', { employeeId: {{ (int) $employee->id }}, entryDate: '{{ $iso }}'{{ $entryId !== null ? ', entryId: '.$entryId : '' }} })"
                                        role="button"
                                        tabindex="0"
                                        aria-label="{{ $tooltip }}"
                                    >
                                        <div class="flex flex-col items-start gap-1">
                                            @if ($atwViolation !== null)
                                                {{-- ATW-waarschuwingsicoon --}}
                                                <span class="inline-flex items-center" aria-label="ATW-waarschuwing">
                                                    <svg class="h-4 w-4 text-warning" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                                    </svg>
                                                </span>
                                            @endif

                                            @if ($isEmpty)
                                                {{-- Lege cel: gestippeld, klikbaar --}}
                                                <span class="text-body-sm text-steel">—</span>
                                            @else
                                                {{-- Gevulde cel: type + netto-minuten --}}
                                                <span class="font-mono text-body-sm text-ink">
                                                    {{ \App\Livewire\Hours\WeekOverviewTable::formatMinutesToHHmm($minutes) }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                                {{-- Totaal-kolom per medewerker --}}
                                <td
                                    class="px-2 py-3 align-top bg-surface font-bold text-ink"
                                    headers="{{ $rowId }} th-total"
                                >
                                    <span class="font-mono text-body-sm">
                                        {{ \App\Livewire\Hours\WeekOverviewTable::formatMinutesToHHmm($weekTotal) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach

                        {{-- Totaal-rij per dag --}}
                        <tr class="border-t-2 border-hairline">
                            <th
                                scope="row"
                                class="py-3 pr-4 align-top font-bold text-ink bg-surface {{ $needsHorizontalScroll ? 'sticky left-0 z-10' : '' }}"
                            >
                                Totaal
                            </th>
                            @foreach ($weekDates as $date)
                                @php
                                    $iso = $date->toDateString();
                                    $dayTotal = $this->getDayTotal($iso);
                                @endphp
                                <td class="px-2 py-3 align-top bg-surface font-bold text-ink">
                                    <span class="font-mono text-body-sm">
                                        {{ \App\Livewire\Hours\WeekOverviewTable::formatMinutesToHHmm($dayTotal) }}
                                    </span>
                                </td>
                            @endforeach
                            {{-- Grand_Total rechtsonder --}}
                            <td class="px-2 py-3 align-top bg-surface font-bold text-ink">
                                <span class="font-mono text-body-sm">
                                    {{ \App\Livewire\Hours\WeekOverviewTable::formatMinutesToHHmm($this->getGrandTotal()) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Ingebedde entry-form-modal — luistert op open-entry-form-modal event --}}
    <livewire:hours.entry-form-modal />

    {{-- Copy-week bevestigingsmodal (Req 5.2) --}}
    @if ($this->canCopyWeek())
        <x-ui.modal
            title="Kopieer vorige week"
            size="md"
            show="$wire.showCopyWeekModal"
        >
            <p class="text-body-md text-ink">
                Wil je de werkregels van
                <strong>{{ $this->getSourceWeekLabel() }}</strong>
                kopiëren naar
                <strong>{{ $this->getTargetWeekLabel() }}</strong>?
            </p>

            {{-- Detail van overgeslagen regels (indien beschikbaar) --}}
            @if (count($copyWeekSkippedDetails) > 0)
                <details class="mt-4 rounded-card border border-hairline p-3">
                    <summary class="cursor-pointer text-body-sm font-medium text-steel">
                        {{ count($copyWeekSkippedDetails) }} overgeslagen regels — klik voor details
                    </summary>
                    <ul class="mt-2 space-y-1 text-body-sm text-steel">
                        @foreach ($copyWeekSkippedDetails as $skipped)
                            <li>
                                {{ $skipped['date'] }} {{ $skipped['start_time'] }} —
                                @if ($skipped['reason'] === 'DUPLICATE')
                                    <span class="text-warning">Duplicaat (bestaat al)</span>
                                @elseif ($skipped['reason'] === 'ATW_BLOCKED')
                                    <span class="text-danger">ATW-overtreding</span>
                                @else
                                    <span class="text-steel">{{ $skipped['reason'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif

            <x-slot:footer>
                <div class="flex justify-end gap-3">
                    <x-ui.button
                        variant="secondary"
                        type="button"
                        wire:click="closeCopyWeekModal"
                        :disabled="$copyWeekLoading"
                    >
                        Annuleren
                    </x-ui.button>
                    <x-ui.button
                        variant="primary"
                        type="button"
                        wire:click="executeCopyWeek"
                        :disabled="$copyWeekLoading"
                        :loading="$copyWeekLoading"
                    >
                        Kopiëren
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Print-only header (Requirement 14.5): verborgen op scherm, zichtbaar bij print --}}
    <div class="hidden print:block print:mb-4" aria-hidden="true">
        <h1 class="text-xl font-bold text-ink">
            Weekoverzicht — Week {{ $weekNumber }} — {{ $organizationName }}
        </h1>
        <p class="text-sm text-steel">
            Printdatum: {{ now()->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}
        </p>
    </div>

    {{-- Print-only footer (Requirement 14.6): verborgen op scherm, zichtbaar bij print --}}
    <div class="hidden print:block print:mt-6 print:border-t print:border-hairline print:pt-3" aria-hidden="true">
        <p class="text-xs text-steel">
            Gegenereerd door La Vita Urenregistratie op {{ now()->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}
        </p>
    </div>
</div>
