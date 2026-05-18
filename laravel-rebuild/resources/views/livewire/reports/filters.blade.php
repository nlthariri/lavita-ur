{{--
  Livewire-view — `Reports\Filters` (taak 12.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.7  → filters medewerker/team/project/kostenplaats/
       periode + downloadknoppen PDF/Excel.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
       `design.md`.
   - requirements.md 6.14 → NL-labels en NL-bevestigingen.

  Compositie:
   - Buitenste container `<x-ui.card>` met `<x-slot:header>` voor titel +
     subtitel (organisatie).
   - Form-sectie met 6 filters in een responsief grid:
       (1) medewerker  — native `<select>` (zie deviation in component
           docblock)
       (2) team        — native `<select>`
       (3) project     — native `<select>`
       (4) kostenplaats— native `<select>`
       (5) begindatum  — `<x-ui.text-input type="date">`
       (6) einddatum   — `<x-ui.text-input type="date">`
   - Actions-rij met drie knoppen:
       (a) "Toon aantal regels" — variant secondary, niet-blokkerend
       (b) "Download PDF"        — variant primary
       (c) "Download Excel"      — variant primary
   - Confirmation-block (alleen wanneer `$confirmation` gezet is) +
     row-count-hint (alleen wanneer `$rowCount !== null`).

  Toegankelijkheid (WCAG 2.1 AA):
   - Elk `<select>` heeft een gekoppeld `<label for>`; bij validatie-fout
     krijgt het `aria-invalid="true"` en `aria-describedby` naar de
     foutmelding.
   - Datum-inputs zijn `<x-ui.text-input type="date">` met label, error en
     described-by-bedrading uit de UI-atom.
   - Focus-state komt globaal uit `layouts/app.blade.php` (border 2px
     #00d4a4 — NFR-1).

  Design-token-discipline (NFR-4):
   - Alleen `<x-ui.card>`, `<x-ui.button>`, `<x-ui.text-input>` en native
     `<select>` (zelfde deviation-rationale als
     `livewire/hours/week-overview-table.blade.php`).
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\User> $employees */
    $employees = $this->getEmployeesInScope();

    /** @var \Illuminate\Support\Collection<int, \App\Models\Team> $teams */
    $teams = $this->getTeamsInScope();

    /** @var array<int, string> $projects */
    $projects = $this->getProjectsInScope(app(\App\Services\ProjectsService::class));

    /** @var array<int, string> $costCenters */
    $costCenters = $this->getCostCentersInScope(app(\App\Services\CostCentersService::class));

    $employeeError = $errors->first('employeeId');
    $teamError = $errors->first('teamId');
    $projectError = $errors->first('projectId');
    $costCenterError = $errors->first('costCenterId');
    $dateFromError = $errors->first('dateFrom');
    $dateToError = $errors->first('dateTo');
@endphp

<div class="flex flex-col gap-4" data-livewire-component="reports.filters">
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">
                    Rapportages
                    @if ($organizationName !== '')
                        — {{ $organizationName }}
                    @endif
                </h1>
                <p class="text-body-sm text-steel">
                    Filter werkregels op medewerker, team, project, kostenplaats en
                    periode. Genereer een PDF- of Excel-export van het resultaat.
                </p>
            </div>
        </x-slot:header>

        @if ($confirmation !== null)
            <div
                role="status"
                aria-live="polite"
                class="mb-4 rounded-input border border-hairline bg-surface px-4 py-3 text-body-sm text-ink"
            >
                {{ $confirmation }}
            </div>
        @endif

        <form
            wire:submit.prevent="previewCount"
            class="flex flex-col gap-4"
            aria-label="Filterformulier rapportages"
        >
            {{-- Filter-grid: 1-koloms op mobiel, 2-koloms op tablet, 3-koloms op desktop. --}}
            <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2 desktop:grid-cols-3">
                {{-- (1) Medewerker --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="reports-filter-employee"
                        class="text-body-sm font-medium text-ink"
                    >
                        Medewerker
                    </label>
                    <select
                        id="reports-filter-employee"
                        name="employee_id"
                        wire:model="employeeId"
                        @if ($employeeError) aria-invalid="true" aria-describedby="reports-filter-employee-error" @endif
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">Alle medewerkers</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">
                                {{ $employee->full_name ?: $employee->name }}
                            </option>
                        @endforeach
                    </select>
                    @if ($employeeError)
                        <p
                            id="reports-filter-employee-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >
                            {{ $employeeError }}
                        </p>
                    @endif
                </div>

                {{-- (2) Team --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="reports-filter-team"
                        class="text-body-sm font-medium text-ink"
                    >
                        Team
                    </label>
                    <select
                        id="reports-filter-team"
                        name="team_id"
                        wire:model="teamId"
                        @if ($teamError) aria-invalid="true" aria-describedby="reports-filter-team-error" @endif
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">Alle teams</option>
                        @foreach ($teams as $team)
                            <option value="{{ $team->id }}">{{ $team->name }}</option>
                        @endforeach
                    </select>
                    @if ($teamError)
                        <p
                            id="reports-filter-team-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >
                            {{ $teamError }}
                        </p>
                    @endif
                </div>

                {{-- (3) Project --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="reports-filter-project"
                        class="text-body-sm font-medium text-ink"
                    >
                        Project
                    </label>
                    <select
                        id="reports-filter-project"
                        name="project_id"
                        wire:model="projectId"
                        @if ($projectError) aria-invalid="true" aria-describedby="reports-filter-project-error" @endif
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">Alle projecten</option>
                        @foreach ($projects as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @if ($projectError)
                        <p
                            id="reports-filter-project-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >
                            {{ $projectError }}
                        </p>
                    @endif
                </div>

                {{-- (4) Kostenplaats --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="reports-filter-cost-center"
                        class="text-body-sm font-medium text-ink"
                    >
                        Kostenplaats
                    </label>
                    <select
                        id="reports-filter-cost-center"
                        name="cost_center_id"
                        wire:model="costCenterId"
                        @if ($costCenterError) aria-invalid="true" aria-describedby="reports-filter-cost-center-error" @endif
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">Alle kostenplaatsen</option>
                        @foreach ($costCenters as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @if ($costCenterError)
                        <p
                            id="reports-filter-cost-center-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >
                            {{ $costCenterError }}
                        </p>
                    @endif
                </div>

                {{-- (5) Begindatum --}}
                <x-ui.text-input
                    label="Begindatum"
                    name="date_from"
                    id="reports-filter-date-from"
                    type="date"
                    wire:model="dateFrom"
                    :error="$dateFromError"
                />

                {{-- (6) Einddatum --}}
                <x-ui.text-input
                    label="Einddatum"
                    name="date_to"
                    id="reports-filter-date-to"
                    type="date"
                    wire:model="dateTo"
                    :error="$dateToError"
                />
            </div>

            {{-- Actions: preview + downloads --}}
            <div class="flex flex-wrap items-center gap-3 pt-2">
                <x-ui.button
                    variant="secondary"
                    type="submit"
                    aria-label="Toon het aantal regels dat de huidige filters opleveren"
                >
                    Toon aantal regels
                </x-ui.button>
                <x-ui.button
                    variant="primary"
                    type="button"
                    wire:click="downloadPdf"
                    aria-label="Download een PDF-export van de gefilterde werkregels"
                >
                    Download PDF
                </x-ui.button>
                <x-ui.button
                    variant="primary"
                    type="button"
                    wire:click="downloadExcel"
                    aria-label="Download een Excel-export van de gefilterde werkregels"
                >
                    Download Excel
                </x-ui.button>
            </div>
        </form>

        @if ($rowCount !== null)
            <p
                class="mt-4 text-body-sm text-steel"
                role="status"
                aria-live="polite"
                data-testid="reports-row-count-hint"
            >
                {{ $rowCount }} regels gevonden voor de huidige filters.
            </p>
        @endif
    </x-ui.card>
</div>
