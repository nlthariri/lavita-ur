{{--
  Livewire-view — `Hours\LeaveForm` (taak 13.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 10.1, 10.2, 10.3, 10.4, 10.10 → Half-dag verlof
   - requirements.md 11.5, 11.6, 11.7 → Verlof-types
   - requirements.md 9.9 → Verlof-saldo op aanvraag-pagina
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).

  Uitbreidingen t.o.v. taak 10.4:
   - Radio-buttons "Hele dag" / "Halve dag" met visuele scheiding
   - Halve dag opties: "Ochtend (tot 12:30)" / "Middag (vanaf 12:30)"
   - Verlof-type dropdown met actieve types van eigen organisatie
   - Verlof-saldo weergave
   - Waarschuwing bij max_days_per_year bereikt (soft limit, oranje banner)
--}}
<div class="flex flex-col gap-4" data-livewire-component="hours.leave-form">
    @php
        /** @var \Illuminate\Support\ViewErrorBag $errors */
        $authUser = \Illuminate\Support\Facades\Auth::user();
        $authRole = $authUser?->role;
        $isEmployee = $authRole === 'employee';

        $selfDisplayName = (string) (
            $authUser?->full_name
            ?? $authUser?->name
            ?? ''
        );

        $employees = $this->getRoleEmployees();
        $availableTypes = $this->getAvailableTypes();
        $leaveTypes = $this->getActiveLeaveTypes();
        $leaveBalance = $this->getLeaveBalance();
        $maxDaysWarning = $this->getMaxDaysWarning();

        $typeError = $errors->first('type');
        $employeeError = $errors->first('employeeId');
        $dateFromError = $errors->first('dateFrom');
        $dateToError = $errors->first('dateTo');
        $noteError = $errors->first('note');
        $leaveTypeError = $errors->first('leaveTypeId');
    @endphp

    {{-- Verlof-saldo weergave (Requirement 9.9) --}}
    @if ($leaveBalance !== null && $leaveBalance['status'] !== 'unconfigured')
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-heading-3 font-semibold text-ink">Verlof-saldo</h2>
            </x-slot:header>

            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between text-body-sm">
                    <span class="text-steel">Jaarlijks recht</span>
                    <span class="font-medium text-ink">{{ $leaveBalance['annual_days'] }} dagen</span>
                </div>
                <div class="flex items-center justify-between text-body-sm">
                    <span class="text-steel">Opgenomen</span>
                    <span class="font-medium text-ink">{{ $leaveBalance['taken_days'] }} dagen</span>
                </div>
                <div class="flex items-center justify-between text-body-sm">
                    <span class="text-steel">Resterend</span>
                    <span class="font-medium {{ $leaveBalance['status'] === 'danger' ? 'text-danger' : ($leaveBalance['status'] === 'warning' ? 'text-warning' : 'text-ink') }}">
                        {{ $leaveBalance['remaining_days'] }} dagen
                    </span>
                </div>

                @php
                    $progressVariant = match($leaveBalance['status']) {
                        'danger' => 'danger',
                        'warning' => 'warning',
                        default => 'success',
                    };
                    $progressValue = $leaveBalance['annual_days'] > 0
                        ? (int) round(($leaveBalance['taken_days'] / $leaveBalance['annual_days']) * 100)
                        : 0;
                @endphp

                <x-ui.progress
                    :value="$progressValue"
                    :max="100"
                    :variant="$progressVariant"
                    label="Verlof opgenomen"
                    :show-percentage="true"
                />

                @if ($leaveBalance['status'] === 'warning')
                    <p class="mt-1 rounded-input border border-warning/40 bg-warning/10 px-3 py-1.5 text-body-sm text-warning">
                        ⚠️ Bijna op — nog {{ $leaveBalance['remaining_days'] }} dag(en) resterend.
                    </p>
                @elseif ($leaveBalance['status'] === 'danger')
                    <p class="mt-1 rounded-input border border-danger/40 bg-danger/10 px-3 py-1.5 text-body-sm text-danger">
                        ⚠️ Saldo op — geen verlofdagen meer beschikbaar.
                    </p>
                @endif
            </div>
        </x-ui.card>
    @endif

    <x-ui.card>
        <x-slot:header>
            <h1
                id="leave-form-heading"
                class="text-heading-2 font-semibold text-ink"
            >
                Verlof / ziekte registreren
            </h1>
        </x-slot:header>

        <form
            wire:submit.prevent="submit"
            method="POST"
            action="#"
            novalidate
            aria-labelledby="leave-form-heading"
            class="flex flex-col gap-4"
        >
            {{-- Type-select. HOLIDAY verbergen voor employee-rol (req 7.2). --}}
            <div class="flex flex-col gap-1">
                <label
                    for="leave-type"
                    class="text-body-sm font-medium text-ink"
                >
                    Type
                    <span class="text-danger" aria-hidden="true">*</span>
                    <span class="sr-only">(verplicht)</span>
                </label>
                <select
                    id="leave-type"
                    name="type"
                    wire:model.live="type"
                    required
                    @if ($typeError) aria-invalid="true" aria-describedby="leave-type-error" @endif
                    class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                >
                    @foreach ($availableTypes as $code => $label)
                        @if ($code === 'HOLIDAY' && $isEmployee)
                            @continue
                        @endif
                        <option
                            value="{{ $code }}"
                            @selected($type === $code)
                        >{{ $label }}</option>
                    @endforeach
                </select>
                @if ($typeError)
                    <p
                        id="leave-type-error"
                        class="text-body-sm text-danger"
                        role="alert"
                        aria-live="polite"
                    >{{ $typeError }}</p>
                @endif
            </div>

            {{-- Verlof-type dropdown (alleen bij type=LEAVE) — Requirement 11.5 --}}
            @if ($type === 'LEAVE' && $leaveTypes->isNotEmpty())
                <div class="flex flex-col gap-1">
                    <label
                        for="leave-type-id"
                        class="text-body-sm font-medium text-ink"
                    >
                        Verlof-type
                        <span class="text-danger" aria-hidden="true">*</span>
                        <span class="sr-only">(verplicht)</span>
                    </label>
                    <select
                        id="leave-type-id"
                        name="leaveTypeId"
                        wire:model.live="leaveTypeId"
                        required
                        @if ($leaveTypeError) aria-invalid="true" aria-describedby="leave-type-id-error" @endif
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        <option value="">— Kies verlof-type —</option>
                        @foreach ($leaveTypes as $lt)
                            <option
                                value="{{ (int) $lt->id }}"
                                @selected((int) $leaveTypeId === (int) $lt->id)
                            >{{ $lt->name }}</option>
                        @endforeach
                    </select>
                    @if ($leaveTypeError)
                        <p
                            id="leave-type-id-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >{{ $leaveTypeError }}</p>
                    @endif

                    {{-- Waarschuwing bij max_days_per_year bereikt (soft limit) — Requirement 11.7 --}}
                    @if ($maxDaysWarning)
                        <div
                            role="alert"
                            aria-live="polite"
                            class="mt-1 flex items-center gap-2 rounded-input border border-warning/40 bg-warning/10 px-3 py-2 text-body-sm text-warning"
                            data-testid="max-days-warning"
                        >
                            <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                            </svg>
                            <span>{{ $maxDaysWarning }}</span>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Employee-select alleen voor manager/owner. --}}
            @if (! $isEmployee)
                <div class="flex flex-col gap-1">
                    <label
                        for="leave-employee"
                        class="text-body-sm font-medium text-ink"
                    >
                        Medewerker
                        <span class="text-danger" aria-hidden="true">*</span>
                        <span class="sr-only">(verplicht)</span>
                    </label>
                    <select
                        id="leave-employee"
                        name="employeeId"
                        wire:model.live="employeeId"
                        required
                        @if ($employeeError) aria-invalid="true" aria-describedby="leave-employee-error" @endif
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    >
                        @foreach ($employees as $employee)
                            <option
                                value="{{ (int) $employee->id }}"
                                @selected((int) $employeeId === (int) $employee->id)
                            >{{ $employee->full_name ?? $employee->name }}</option>
                        @endforeach
                    </select>
                    @if ($employeeError)
                        <p
                            id="leave-employee-error"
                            class="text-body-sm text-danger"
                            role="alert"
                            aria-live="polite"
                        >{{ $employeeError }}</p>
                    @endif
                </div>
            @else
                <p
                    class="rounded-input border border-hairline bg-surface px-3 py-2 text-body-sm text-ink"
                    data-testid="leave-employee-self"
                >
                    Je dient deze melding voor jezelf in
                    @if ($selfDisplayName !== '')
                        ({{ $selfDisplayName }})
                    @endif
                    .
                </p>
                @if ($employeeError)
                    <p
                        class="text-body-sm text-danger"
                        role="alert"
                        aria-live="polite"
                    >{{ $employeeError }}</p>
                @endif
            @endif

            {{-- Dag-duur keuze: Hele dag / Halve dag — Requirement 10.1, 10.10 --}}
            <fieldset class="flex flex-col gap-2">
                <legend class="text-body-sm font-medium text-ink">
                    Duur
                </legend>

                <div class="flex flex-col gap-3 rounded-input border border-hairline p-3 tablet:flex-row tablet:items-start tablet:gap-6" data-testid="day-duration-group">
                    {{-- Hele dag --}}
                    <div class="flex items-center gap-2">
                        <input
                            type="radio"
                            id="day-duration-full"
                            name="dayDuration"
                            value="full"
                            wire:model.live="dayDuration"
                            class="h-4 w-4 border-hairline text-brand-green focus:ring-brand-green/20"
                            @checked($dayDuration === 'full')
                        />
                        <label for="day-duration-full" class="text-body-sm text-ink cursor-pointer">
                            Hele dag
                        </label>
                    </div>

                    {{-- Visuele scheiding --}}
                    <div class="hidden tablet:block tablet:h-10 tablet:w-px tablet:bg-hairline" aria-hidden="true"></div>
                    <div class="block tablet:hidden h-px w-full bg-hairline" aria-hidden="true"></div>

                    {{-- Halve dag --}}
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <input
                                type="radio"
                                id="day-duration-half"
                                name="dayDuration"
                                value="half"
                                wire:model.live="dayDuration"
                                class="h-4 w-4 border-hairline text-brand-green focus:ring-brand-green/20"
                                @checked($dayDuration === 'half')
                            />
                            <label for="day-duration-half" class="text-body-sm text-ink cursor-pointer">
                                Halve dag
                            </label>
                        </div>

                        {{-- Halve dag opties (alleen zichtbaar als halve dag geselecteerd) --}}
                        @if ($dayDuration === 'half')
                            <div class="ml-6 flex flex-col gap-2" data-testid="half-day-options">
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        id="half-day-morning"
                                        name="halfDayPeriod"
                                        value="morning"
                                        wire:model.live="halfDayPeriod"
                                        class="h-4 w-4 border-hairline text-brand-green focus:ring-brand-green/20"
                                        @checked($halfDayPeriod === 'morning')
                                    />
                                    <label for="half-day-morning" class="text-body-sm text-ink cursor-pointer">
                                        Ochtend <span class="text-steel">(tot 12:30)</span>
                                    </label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        id="half-day-afternoon"
                                        name="halfDayPeriod"
                                        value="afternoon"
                                        wire:model.live="halfDayPeriod"
                                        class="h-4 w-4 border-hairline text-brand-green focus:ring-brand-green/20"
                                        @checked($halfDayPeriod === 'afternoon')
                                    />
                                    <label for="half-day-afternoon" class="text-body-sm text-ink cursor-pointer">
                                        Middag <span class="text-steel">(vanaf 12:30)</span>
                                    </label>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </fieldset>

            {{-- Datum-range, naast elkaar op tablet+, gestapeld op mobiel. --}}
            <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                <x-ui.text-input
                    name="dateFrom"
                    type="date"
                    label="Vanaf"
                    required
                    wire:model.live="dateFrom"
                    :value="$dateFrom"
                    :error="$dateFromError"
                />
                <x-ui.text-input
                    name="dateTo"
                    type="date"
                    label="Tot en met"
                    required
                    wire:model.live="dateTo"
                    :value="$dateTo"
                    :error="$dateToError"
                />
            </div>

            {{-- Motivatie (textarea). --}}
            <div class="flex flex-col gap-1">
                <label
                    for="leave-note"
                    class="text-body-sm font-medium text-ink"
                >
                    Motivatie
                    @if ($isEmployee)
                        <span class="text-danger" aria-hidden="true">*</span>
                        <span class="sr-only">(verplicht)</span>
                    @endif
                </label>
                <textarea
                    id="leave-note"
                    name="note"
                    rows="3"
                    wire:model.blur="note"
                    @if ($isEmployee) aria-required="true" @endif
                    @if ($noteError) aria-invalid="true" aria-describedby="leave-note-error" @endif
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                >{{ $note }}</textarea>
                @if ($isEmployee)
                    <p class="text-body-sm text-steel">
                        Voor ziek- of verlofmeldingen door medewerkers is een korte motivatie verplicht.
                    </p>
                @endif
                @if ($noteError)
                    <p
                        id="leave-note-error"
                        class="text-body-sm text-danger"
                        role="alert"
                        aria-live="polite"
                    >{{ $noteError }}</p>
                @endif
            </div>

            {{-- Bevestigingsblok — verschijnt alleen bij succesvolle submit. --}}
            @if ($confirmation !== null && $confirmation !== '')
                <p
                    role="status"
                    aria-live="polite"
                    data-testid="leave-confirmation"
                    class="rounded-input border border-success/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
                >{{ $confirmation }}</p>
            @endif

            {{-- Acties --}}
            <div class="mt-2 flex flex-col-reverse gap-3 tablet:flex-row tablet:justify-end">
                <x-ui.button
                    type="button"
                    variant="secondary"
                    wire:click="$set('note', '')"
                >Wissen</x-ui.button>

                <x-ui.button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    <span wire:loading.remove wire:target="submit">Indienen</span>
                    <span wire:loading wire:target="submit" aria-live="polite">Bezig met indienen…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Recente verlofmeldingen van de huidige gebruiker --}}
    @php
        $recentLeaves = \App\Models\WorkEntry::query()
            ->where('employee_id', (int) $authUser->id)
            ->where('organization_id', (int) $authUser->organization_id)
            ->whereIn('type', ['SICK', 'LEAVE', 'HOLIDAY'])
            ->whereNull('deleted_at')
            ->orderByDesc('entry_date')
            ->limit(5)
            ->get();

        $typeLabelsRecent = [
            'SICK' => 'Ziek',
            'LEAVE' => 'Verlof',
            'HOLIDAY' => 'Feestdag',
        ];
    @endphp

    @if ($recentLeaves->isNotEmpty())
        <x-ui.card>
            <x-slot:header>
                <div class="flex items-center justify-between">
                    <h2 class="text-heading-3 font-semibold text-ink">
                        Recente meldingen
                    </h2>
                    <a
                        href="/verlof/overzicht"
                        class="text-body-sm text-steel no-underline hover:text-ink"
                    >Bekijk alles →</a>
                </div>
            </x-slot:header>

            <ul class="flex flex-col gap-2">
                @foreach ($recentLeaves as $recentEntry)
                    @php
                        $isApproved = (bool) $recentEntry->is_finalized;
                        $isPending = ! $isApproved;
                        $statusVariant = $isApproved ? 'success' : 'concept';
                        $statusLabel = $isApproved ? 'Goedgekeurd' : 'In afwachting';
                    @endphp
                    <li class="flex items-center justify-between rounded-input border border-hairline px-3 py-2">
                        <div class="flex items-center gap-3">
                            <span class="text-body-sm text-ink">
                                {{ $recentEntry->entry_date?->format('d-m-Y') ?? '—' }}
                            </span>
                            <span class="text-body-sm text-steel">
                                {{ $typeLabelsRecent[$recentEntry->type] ?? $recentEntry->type }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            {{-- Annuleren knop: alleen bij PENDING (is_finalized=false) — Req 10.5, 10.8 --}}
                            @if ($isPending)
                                <button
                                    type="button"
                                    wire:click="confirmCancelLeave({{ (int) $recentEntry->id }})"
                                    class="inline-flex items-center gap-1 rounded-button border border-danger/40 bg-danger/5 px-2 py-1 text-body-sm text-danger hover:bg-danger/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger/50 focus-visible:ring-offset-2 transition-colors"
                                    aria-label="Annuleer verlofaanvraag van {{ $recentEntry->entry_date?->format('d-m-Y') ?? '' }}"
                                    data-testid="cancel-leave-btn-{{ $recentEntry->id }}"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <span>Annuleren</span>
                                </button>
                            @endif
                            <x-ui.status-badge :variant="$statusVariant" icon>
                                {{ $statusLabel }}
                            </x-ui.status-badge>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- Bevestigingsmodal voor verlof-annulering — Req 10.6 --}}
    <div x-data="{ showCancelModal: @entangle('cancellingEntryId').live }">
        <x-ui.modal
            title="Verlofaanvraag annuleren"
            size="sm"
            show="showCancelModal !== null && showCancelModal > 0"
        >
            <p class="text-body-md text-ink">
                Weet je zeker dat je deze verlofaanvraag wilt annuleren?
            </p>

            <x-slot:footer>
                <div class="flex flex-col-reverse gap-3 tablet:flex-row tablet:justify-end">
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        wire:click="dismissCancelModal"
                        data-testid="cancel-leave-dismiss"
                    >
                        Nee, behouden
                    </x-ui.button>
                    <x-ui.button
                        type="button"
                        variant="danger"
                        wire:click="cancelLeave"
                        wire:loading.attr="disabled"
                        wire:target="cancelLeave"
                        data-testid="cancel-leave-confirm"
                    >
                        <span wire:loading.remove wire:target="cancelLeave">Ja, annuleren</span>
                        <span wire:loading wire:target="cancelLeave" aria-live="polite">Bezig…</span>
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</div>
