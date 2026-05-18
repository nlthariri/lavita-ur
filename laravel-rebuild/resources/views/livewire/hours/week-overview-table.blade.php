{{--
  Livewire-view — `Hours\WeekOverviewTable` (taak 10.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.2  → tabel: rijen = medewerkers, kolommen = ma..zo,
       cellen tonen statusbadges + week-prev/next-navigatie + manager-team-
       scope-filter.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
       `design.md`.
   - requirements.md 6.14 → NL-labels en NL-bevestigingen.

  Compositie:
   - Buitenste container `<x-ui.card>` met `<x-slot:header>` voor titel +
     subtitel (organisatie).
   - Navigatie-rij met drie `<x-ui.button>`'s (Vorige / Vandaag / Volgende)
     en (voor owner/boekhouder met >1 team) een team-filter via een
     gelabelde native `<select>` — zie deviation-note in de component-
     docblock waarom we hier geen extra UI-atom introduceren.
   - Tabel met `<caption>` (sr-only), `<thead>` (medewerker + 7 dagen) en
     `<tbody>` met per medewerker per dag een statusbadge + netto-minuten.
   - Lege-staat fallback wanneer er geen medewerkers in de scope zijn.

  Toegankelijkheid (WCAG 2.1 AA):
   - `<th scope="col">` voor dagen, `<th scope="row">` voor medewerker.
   - Elke dag-`<th>` heeft een `id`, en elke `<td>` verwijst er via
     `headers="…"` naar zodat screenreaders dag- + medewerkernaam aankondigen.
   - `<caption>` bevat de week-range (sr-only zodat sighted users 'm niet
     dubbel zien naast de header-titel).
   - Focus-state komt globaal uit `layouts/app.blade.php` (border 2px
     #00d4a4 — NFR-1).
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

    // NL-afkortingen voor de dagkoppen — Carbon's `shortDayName` is
    // locale-afhankelijk en deze app heeft (nog) geen NL-locale-config,
    // dus we hardcoderen ze hier zoals vastgesteld in tasks.md 10.1.
    $dayLabels = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];

    /** Hulpfunctie: minuten → "Xu Ymin" of "—" wanneer 0. */
    $formatMinutes = static function (int $minutes): string {
        if ($minutes <= 0) {
            return '—';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m.'min';
        }
        if ($m === 0) {
            return $h.'u';
        }
        return $h.'u '.$m.'min';
    };
@endphp

<div class="flex flex-col gap-4" data-livewire-component="hours.week-overview-table">
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

        {{-- Navigatie + team-filter --}}
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
            <div class="mt-6 overflow-x-auto">
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
                                class="py-2 pr-4 align-bottom font-medium text-ink"
                            >
                                Medewerker
                            </th>
                            @foreach ($weekDates as $i => $date)
                                @php
                                    $dayId = 'th-day-'.$date->toDateString();
                                @endphp
                                <th
                                    scope="col"
                                    id="{{ $dayId }}"
                                    class="px-2 py-2 align-bottom font-medium text-ink"
                                >
                                    <span class="block text-button-md">{{ $dayLabels[$i] }}</span>
                                    <span class="block text-body-sm font-normal text-steel">
                                        {{ $date->format('d-m') }}
                                    </span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            @php
                                $employeeName = $employee->full_name ?: $employee->name;
                                $rowId = 'th-emp-'.$employee->id;
                            @endphp
                            <tr class="border-b border-hairline last:border-b-0">
                                <th
                                    scope="row"
                                    id="{{ $rowId }}"
                                    class="py-3 pr-4 align-top font-medium text-ink"
                                >
                                    {{ $employeeName }}
                                </th>
                                @foreach ($weekDates as $date)
                                    @php
                                        $iso = $date->toDateString();
                                        $isHoliday = isset($holidaysMap[$iso]);
                                        $holidayName = $holidaysMap[$iso] ?? null;
                                        $status = $this->getStatusForCell((int) $employee->id, $iso);
                                        $variant = $this->getStatusBadgeVariantFor($status);
                                        $label = $this->getStatusBadgeLabelFor($status);
                                        $minutes = $this->getNetMinutesForCell((int) $employee->id, $iso);
                                        $minutesLabel = $formatMinutes($minutes);
                                        $dayId = 'th-day-'.$iso;
                                    @endphp
                                    <td
                                        class="px-2 py-3 align-top {{ $isHoliday ? 'bg-gray-100' : '' }}"
                                        headers="{{ $rowId }} {{ $dayId }}"
                                        @if ($isHoliday) title="{{ $holidayName }}" @endif
                                    >
                                        @if ($isHoliday)
                                            <div class="flex flex-col items-start gap-1">
                                                <span
                                                    class="inline-flex items-center rounded-full bg-gray-200 px-2 py-0.5 text-body-sm font-medium text-steel"
                                                    title="{{ $holidayName }}"
                                                    aria-label="Feestdag: {{ $holidayName }}"
                                                >
                                                    {{ $holidayName }}
                                                </span>
                                                @if ($minutes > 0)
                                                    <span class="font-mono text-body-sm text-steel">
                                                        {{ $minutesLabel }}
                                                    </span>
                                                @endif
                                            </div>
                                        @else
                                            <div class="flex flex-col items-start gap-1">
                                                <x-ui.status-badge
                                                    :variant="$variant"
                                                    data-status="{{ $status }}"
                                                >
                                                    {{ $label }}
                                                </x-ui.status-badge>
                                                <span class="font-mono text-body-sm text-steel">
                                                    {{ $minutesLabel }}
                                                </span>
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
