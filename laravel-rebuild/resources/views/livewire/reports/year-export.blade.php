{{--
  Livewire-view — `Reports\YearExport` (taak 12.2 spec
  lavita-urenregistratie).

  Bron:
   - requirements.md 6.7  → "Rapportages & export" met aparte
       "Jaaroverzicht"-tab voor fiscale jaarexport.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-bevestigingen.
   - requirements.md 14.5 → endpoint `GET /reports/year-export`.

  Compositie:
   - Buitenste container `<x-ui.card>` met `<x-slot:header>` voor titel +
     subtitel (organisatie).
   - Form-sectie met 2 filters in een responsief grid:
       (1) jaartal     — `<x-ui.text-input type="number">` (min 1900, max 2099)
       (2) medewerker  — native `<select>` (zelfde deviation-rationale
                         als in de Filters-component)
   - Actions-rij met twee knoppen:
       (a) "Toon aantal medewerkers" — variant secondary
       (b) "Download PDF"            — variant primary
   - Confirmation-block (alleen wanneer `$confirmation` gezet is) +
     row-count-hint (alleen wanneer `$rowCount !== null`).

  Toegankelijkheid (WCAG 2.1 AA):
   - Het `<select>` heeft een gekoppeld `<label for>`; bij validatie-fout
     krijgt het `aria-invalid="true"` en `aria-describedby` naar de
     foutmelding.
   - De jaartal-input is `<x-ui.text-input type="number">` met label,
     error en described-by-bedrading uit de UI-atom.
   - Focus-state komt globaal uit `layouts/app.blade.php` (border 2px
     #00d4a4 — NFR-1).
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\User> $employees */
    $employees = $this->getEmployeesInScope();

    $yearError = $errors->first('year');
    $employeeError = $errors->first('employeeId');
@endphp

<div class="flex flex-col gap-4" data-livewire-component="reports.year-export">
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">
                    Jaaroverzicht
                    @if ($organizationName !== '')
                        — {{ $organizationName }}
                    @endif
                </h1>
                <p class="text-body-sm text-steel">
                    Genereer een fiscale jaarexport per medewerker met de
                    minuten per maand uitgesplitst naar werk, ziek, verlof,
                    feestdag en overig.
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
            aria-label="Filterformulier jaarexport"
        >
            {{-- Filter-grid: 1-koloms op mobiel, 2-koloms op tablet+. --}}
            <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                {{-- (1) Jaar --}}
                <x-ui.text-input
                    label="Jaar"
                    name="year"
                    id="year-export-year"
                    type="number"
                    min="1900"
                    max="2099"
                    step="1"
                    required
                    wire:model="year"
                    :error="$yearError"
                    help="Vul het rapportjaar in (1900–2099)."
                />

                {{-- (2) Medewerker --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="year-export-employee"
                        class="text-body-sm font-medium text-ink"
                    >
                        Medewerker
                    </label>
                    <select
                        id="year-export-employee"
                        name="employee_id"
                        wire:model="employeeId"
                        @if ($employeeError) aria-invalid="true" aria-describedby="year-export-employee-error" @endif
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
                            id="year-export-employee-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >
                            {{ $employeeError }}
                        </p>
                    @endif
                </div>
            </div>

            {{-- Actions: preview + download --}}
            <div class="flex flex-wrap items-center gap-3 pt-2">
                <x-ui.button
                    variant="secondary"
                    type="submit"
                    aria-label="Toon het aantal medewerkers met data voor het opgegeven jaar"
                >
                    Toon aantal medewerkers
                </x-ui.button>
                <x-ui.button
                    variant="primary"
                    type="button"
                    wire:click="downloadPdf"
                    aria-label="Download de fiscale jaarexport als PDF"
                >
                    Download PDF
                </x-ui.button>
            </div>
        </form>

        @if ($rowCount !== null)
            <p
                class="mt-4 text-body-sm text-steel"
                role="status"
                aria-live="polite"
                data-testid="year-export-row-count-hint"
            >
                {{ $rowCount }} medewerkers gevonden voor {{ $year }}.
            </p>
        @endif
    </x-ui.card>
</div>
