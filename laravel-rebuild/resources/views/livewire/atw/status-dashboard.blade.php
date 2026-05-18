{{--
  Livewire-view — `Atw\StatusDashboard` (taak 11.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.5  → grid: rijen=medewerkers, kolommen=DAILY_LIMIT,
       WEEKLY, 16-WEKEN, REST, PAUSE, met groene/gele/rode statusbadges.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
       `design.md`.
   - requirements.md 6.14 → NL-labels en NL-bevestigingen.

  Compositie:
   - Buitenste container `<x-ui.card>` met `<x-slot:header>` voor titel +
     subtitel (organisatie).
   - (Owner/boekhouder) team-filter via een gelabelde native `<select>` —
     zelfde deviation-rationale als in
     `resources/views/livewire/hours/week-overview-table.blade.php`.
   - Tabel met `<caption>` (sr-only), `<thead>` (medewerker + 5 kolommen)
     en `<tbody>` met per medewerker per kolom een statusbadge plus —
     voor warning/critical-cellen — een ratio `current/threshold` in
     kleinere tekst.
   - Lege-staat fallback wanneer er geen medewerkers in de scope zijn.

  Toegankelijkheid (WCAG 2.1 AA):
   - `<th scope="col">` voor kolommen, `<th scope="row">` voor medewerker.
   - Elke kolom-`<th>` heeft een `id`, en elke `<td>` verwijst er via
     `headers="…"` naar zodat screenreaders kolomnaam + medewerker
     aankondigen.
   - `<caption>` bevat een sr-only beschrijving van wat de tabel toont.
   - Focus-state komt globaal uit `layouts/app.blade.php` (border 2px
     #00d4a4 — NFR-1).
--}}
@php
    /** @var array<string, string> $columnTypes */
    $columnTypes = $this->getColumnTypes();

    /** @var \Illuminate\Support\Collection $employees */
    $employees = $this->getEmployees();

    /** @var \Illuminate\Support\Collection $teams */
    $teams = $this->getAvailableTeams();

    /** @var array<int, array<string, array<string, mixed>>> $matrix */
    $matrix = $this->getStatusMatrix();
@endphp

<div class="flex flex-col gap-4" data-livewire-component="atw.status-dashboard">
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">
                    ATW-dashboard
                    @if ($organizationName !== '')
                        — {{ $organizationName }}
                    @endif
                </h1>
                <p class="text-body-sm text-steel">
                    Status per medewerker op de vijf ATW-limieten: daglimiet, weeklimiet,
                    16-weken-gemiddelde, rusttijd en pauzeplicht.
                </p>
            </div>
        </x-slot:header>

        {{-- Team-filter (alleen voor owner/boekhouder met >1 team) --}}
        @if ($teams->count() > 1)
            @php
                $teamFilterError = $errors->first('teamFilter');
            @endphp
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex flex-col gap-1">
                    <label
                        for="atw-dashboard-team-filter"
                        class="text-body-sm font-medium text-ink"
                    >
                        Team
                    </label>
                    <select
                        id="atw-dashboard-team-filter"
                        name="team_filter"
                        wire:change="setTeamFilter($event.target.value === '' ? null : Number($event.target.value))"
                        @if ($teamFilterError) aria-invalid="true" aria-describedby="atw-dashboard-team-filter-error" @endif
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
                            id="atw-dashboard-team-filter-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >
                            {{ $teamFilterError }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

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
                    aria-label="ATW-status per medewerker"
                >
                    <caption class="sr-only">
                        ATW-status per medewerker op de vijf wettelijke limieten.
                    </caption>
                    <thead>
                        <tr class="border-b border-hairline">
                            <th
                                scope="col"
                                id="atw-th-employee"
                                class="py-2 pr-4 align-bottom font-medium text-ink"
                            >
                                Medewerker
                            </th>
                            @foreach ($columnTypes as $columnKey => $columnLabel)
                                @php
                                    $colId = 'atw-th-col-'.strtolower(str_replace('_', '-', $columnKey));
                                @endphp
                                <th
                                    scope="col"
                                    id="{{ $colId }}"
                                    class="px-2 py-2 align-bottom font-medium text-ink"
                                >
                                    <span class="block text-button-md">{{ $columnLabel }}</span>
                                    <span class="block text-body-sm font-normal text-steel">
                                        {{ $columnKey }}
                                    </span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            @php
                                $employeeName = $employee->full_name ?: $employee->name;
                                $rowId = 'atw-th-emp-'.$employee->id;
                                $cells = $matrix[(int) $employee->id] ?? [];
                            @endphp
                            <tr class="border-b border-hairline last:border-b-0">
                                <th
                                    scope="row"
                                    id="{{ $rowId }}"
                                    class="py-3 pr-4 align-top font-medium text-ink"
                                >
                                    {{ $employeeName }}
                                </th>
                                @foreach ($columnTypes as $columnKey => $columnLabel)
                                    @php
                                        $cell = $cells[$columnKey] ?? [
                                            'severity' => 'ok',
                                            'message' => null,
                                            'current_minutes' => null,
                                            'threshold_minutes' => null,
                                            'violation_type' => null,
                                        ];
                                        $severity = (string) ($cell['severity'] ?? 'ok');
                                        $variant = $this->getStatusBadgeVariantFor($severity);
                                        $label = $this->getStatusBadgeLabelFor($severity, $cell['violation_type'] ?? null);
                                        $currentMinutes = $cell['current_minutes'] ?? null;
                                        $thresholdMinutes = $cell['threshold_minutes'] ?? null;
                                        $colId = 'atw-th-col-'.strtolower(str_replace('_', '-', $columnKey));
                                    @endphp
                                    <td
                                        class="px-2 py-3 align-top"
                                        headers="{{ $rowId }} {{ $colId }}"
                                        data-cell-severity="{{ $severity }}"
                                        data-cell-column="{{ $columnKey }}"
                                    >
                                        <div class="flex flex-col items-start gap-1">
                                            <x-ui.status-badge
                                                :variant="$variant"
                                                data-severity="{{ $severity }}"
                                            >
                                                {{ $label }}
                                            </x-ui.status-badge>
                                            @if (in_array($severity, ['warning', 'critical'], true) && $currentMinutes !== null && $thresholdMinutes !== null)
                                                <span class="font-mono text-body-sm text-steel">
                                                    {{ (int) $currentMinutes }} / {{ (int) $thresholdMinutes }} min
                                                </span>
                                            @endif
                                        </div>
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
