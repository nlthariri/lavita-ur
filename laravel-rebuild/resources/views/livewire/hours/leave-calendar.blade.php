{{--
  Livewire-view — `Hours\LeaveCalendar` (taak 14.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 8.1-8.10 → Verlofkalender maandweergave
   - requirements.md 11.10    → Legenda met actieve verlof-types

  Features:
   - Maandweergave grid: rijen=medewerkers, kolommen=dagen
   - Color_Coding: SICK=bg-red-100, LEAVE=bg-blue-100, HOLIDAY=bg-gray-100, leeg=bg-white
   - Scope-filtering: manager=eigen team, owner=alle teams (optioneel team-filter)
   - Feestdagen markeren in kolomheader (grijze achtergrond + tooltip)
   - Klik op lege cel → dispatch event voor snelle verlof-invoer-modal
   - Maand-navigatie (vorige/volgende, vandaag-knop) + keyboard shortcuts (←/→)
   - Verticaal scrollbaar bij >20 medewerkers, sticky header-rij
   - Totaal-kolom per medewerker (verlofdagen in maand)
   - Legenda met actieve verlof-types + kleurcodering
--}}
@php
    /** @var \Carbon\Carbon[] $monthDates */
    $monthDates = $this->getMonthDates();

    /** @var \Illuminate\Support\Collection $employees */
    $employees = $this->getEmployees();

    /** @var \Illuminate\Support\Collection $teams */
    $teams = $this->getAvailableTeams();

    /** @var array<string, string> $holidaysMap — [Y-m-d => naam] */
    $holidaysMap = $this->getHolidaysForMonth();

    /** @var \Illuminate\Support\Collection $leaveTypes */
    $leaveTypes = $this->getActiveLeaveTypes();

    // Bepaal of verticaal scrollen nodig is (>20 medewerkers)
    $needsVerticalScroll = $employees->count() > 20;

    // Maand-label
    $monthLabel = $this->getMonthLabel();

    // Kan de gebruiker verlof invoeren?
    $canCreate = $this->canCreateLeave();
@endphp

<div
    class="flex flex-col gap-4"
    data-livewire-component="hours.leave-calendar"
    x-data="{}"
    x-on:keydown.left.window="$wire.previousMonth()"
    x-on:keydown.right.window="$wire.nextMonth()"
>
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">
                    Verlofkalender — {{ $monthLabel }}
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
                    wire:click="previousMonth"
                    aria-label="Ga naar vorige maand"
                >
                    Vorige maand
                </x-ui.button>
                <x-ui.button
                    variant="secondary"
                    type="button"
                    wire:click="goToToday"
                    aria-label="Spring naar huidige maand"
                >
                    Vandaag
                </x-ui.button>
                <x-ui.button
                    variant="secondary"
                    type="button"
                    wire:click="nextMonth"
                    aria-label="Ga naar volgende maand"
                >
                    Volgende maand
                </x-ui.button>
            </div>

            {{-- Team-filter (alleen als er meerdere teams zijn) --}}
            @if ($teams->count() > 1)
                <div class="flex flex-col gap-1">
                    <label
                        for="leave-calendar-team-filter"
                        class="text-body-sm font-medium text-ink"
                    >
                        Team
                    </label>
                    <select
                        id="leave-calendar-team-filter"
                        name="team_filter"
                        wire:change="setTeamFilter($event.target.value === '' ? null : Number($event.target.value))"
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
                </div>
            @endif
        </div>

        {{-- Hoofdcontent: kalender-grid of lege-staat --}}
        @if ($employees->isEmpty())
            <p class="mt-6 text-body-md text-steel">
                Geen medewerkers gevonden voor de huidige scope.
            </p>
        @else
            <div
                class="mt-6 overflow-x-auto {{ $needsVerticalScroll ? 'max-h-[600px] overflow-y-auto' : '' }}"
                role="region"
                aria-label="Verlofkalender tabel"
                tabindex="0"
            >
                <table
                    class="w-full min-w-[900px] border-collapse text-left text-body-sm"
                    role="table"
                    aria-label="Verlofkalender {{ $monthLabel }}"
                >
                    <caption class="sr-only">
                        Verlofkalender voor {{ $monthLabel }}.
                    </caption>
                    <thead class="{{ $needsVerticalScroll ? 'sticky top-0 z-20 bg-canvas' : '' }}">
                        <tr class="border-b border-hairline">
                            <th
                                scope="col"
                                class="py-2 pr-2 align-bottom font-medium text-ink sticky left-0 z-10 bg-canvas min-w-[140px]"
                            >
                                Medewerker
                            </th>
                            @foreach ($monthDates as $date)
                                @php
                                    $iso = $date->toDateString();
                                    $isHoliday = isset($holidaysMap[$iso]);
                                    $isWeekend = $date->isWeekend();
                                    $dayNumber = $date->day;
                                @endphp
                                <th
                                    scope="col"
                                    class="px-0.5 py-2 text-center align-bottom font-medium text-ink min-w-[28px] {{ $isHoliday ? 'bg-gray-100' : ($isWeekend ? 'bg-gray-50' : '') }}"
                                    @if ($isHoliday) title="{{ $holidaysMap[$iso] }}" @endif
                                >
                                    <span class="block text-[10px] leading-tight">{{ $dayNumber }}</span>
                                </th>
                            @endforeach
                            {{-- Totaal-kolom header --}}
                            <th
                                scope="col"
                                class="px-2 py-2 text-center align-bottom font-bold text-ink bg-surface min-w-[50px]"
                            >
                                Totaal
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            @php
                                $employeeName = $employee->full_name ?: $employee->name;
                                $employeeId = (int) $employee->id;
                                $leaveDays = $this->getLeaveDaysForEmployee($employeeId);
                            @endphp
                            <tr class="border-b border-hairline last:border-b-0">
                                <th
                                    scope="row"
                                    class="py-1.5 pr-2 align-middle font-medium text-ink text-body-sm truncate max-w-[140px] sticky left-0 z-10 bg-canvas"
                                    title="{{ $employeeName }}"
                                >
                                    {{ $employeeName }}
                                </th>
                                @foreach ($monthDates as $date)
                                    @php
                                        $iso = $date->toDateString();
                                        $type = $this->getTypeForCell($employeeId, $iso);
                                        $colorClasses = \App\Livewire\Hours\LeaveCalendar::getCalendarColorClasses($type);
                                        $isEmpty = $type === null;
                                        $isHolidayCol = isset($holidaysMap[$iso]);
                                        $isWeekend = $date->isWeekend();

                                        // Tooltip
                                        $tooltip = '';
                                        if ($type === 'SICK') {
                                            $tooltip = 'Ziek';
                                        } elseif ($type === 'LEAVE') {
                                            $tooltip = 'Verlof';
                                        } elseif ($type === 'HOLIDAY') {
                                            $tooltip = $holidaysMap[$iso] ?? 'Feestdag';
                                        } elseif ($canCreate) {
                                            $tooltip = "Verlof invoeren voor {$employeeName}";
                                        }

                                        // Achtergrondkleur: feestdag-kolom of weekend als fallback
                                        $bgClass = $colorClasses['bg'];
                                        if ($isEmpty && $isHolidayCol) {
                                            $bgClass = 'bg-gray-50';
                                        } elseif ($isEmpty && $isWeekend) {
                                            $bgClass = 'bg-gray-50';
                                        }
                                    @endphp
                                    <td
                                        class="px-0.5 py-1.5 text-center align-middle {{ $bgClass }} border border-hairline/50 {{ $isEmpty && $canCreate ? 'cursor-pointer hover:bg-blue-50/50' : '' }}"
                                        title="{{ $tooltip }}"
                                        @if ($isEmpty && $canCreate)
                                            wire:click="$dispatch('open-leave-form', { employeeId: {{ $employeeId }}, entryDate: '{{ $iso }}' })"
                                            role="button"
                                            tabindex="0"
                                            aria-label="Verlof invoeren voor {{ $employeeName }} op {{ $date->format('d-m-Y') }}"
                                        @endif
                                    >
                                        @if ($type === 'SICK')
                                            <span class="inline-block h-4 w-4 rounded-sm bg-red-400" aria-label="Ziek"></span>
                                        @elseif ($type === 'LEAVE')
                                            <span class="inline-block h-4 w-4 rounded-sm bg-blue-400" aria-label="Verlof"></span>
                                        @elseif ($type === 'HOLIDAY')
                                            <span class="inline-block h-4 w-4 rounded-sm bg-gray-400" aria-label="Feestdag"></span>
                                        @else
                                            <span class="sr-only">Leeg</span>
                                        @endif
                                    </td>
                                @endforeach
                                {{-- Totaal-kolom per medewerker (Req 8.9) --}}
                                <td class="px-2 py-1.5 text-center align-middle bg-surface font-bold text-ink">
                                    {{ $leaveDays }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Legenda (Requirement 11.10) --}}
        <div class="mt-4 flex flex-wrap items-center gap-4 border-t border-hairline pt-4">
            <span class="text-body-sm font-medium text-ink">Legenda:</span>
            <div class="flex items-center gap-1.5">
                <span class="inline-block h-4 w-4 rounded-sm bg-red-400"></span>
                <span class="text-body-sm text-steel">Ziek</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="inline-block h-4 w-4 rounded-sm bg-blue-400"></span>
                <span class="text-body-sm text-steel">Verlof</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="inline-block h-4 w-4 rounded-sm bg-gray-400"></span>
                <span class="text-body-sm text-steel">Feestdag</span>
            </div>
            @foreach ($leaveTypes as $leaveType)
                <div class="flex items-center gap-1.5">
                    <span class="inline-block h-4 w-4 rounded-sm bg-blue-400 opacity-70"></span>
                    <span class="text-body-sm text-steel">{{ $leaveType->name }}</span>
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
