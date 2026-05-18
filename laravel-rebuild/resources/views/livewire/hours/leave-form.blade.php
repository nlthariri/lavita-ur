{{--
  Livewire-view — `Hours\LeaveForm` (taak 10.4 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.10 → "Verlof/ziekte invoer" met aparte workflow
       per `type ∈ {SICK, LEAVE, HOLIDAY}`, datum-range-picker en
       optionele/verplichte motivatie.
   - requirements.md 7.1  → defaults `start_time = 00:00`,
       `end_time = 23:59`, `pause_minutes = 0`.
   - requirements.md 7.2  → employee-rol mag geen HOLIDAY indienen,
       motivatie verplicht voor employee, alleen voor zichzelf.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).

  Compositie:
   - `<x-ui.card>` met titelheader.
   - Form `wire:submit.prevent="submit"`.
   - Type-select (native `<select>` — zelfde deviation als 10.2/10.3
     omdat `<x-ui.text-input>` geen `type=select` ondersteunt).
   - Employee-select alleen als rol manager/owner is. Employee-rol
     ziet hier alleen een readonly hint-tekst met de eigen naam.
   - Twee `<x-ui.text-input type="date">` op een 2-koloms-grid voor
     `dateFrom` en `dateTo` met NL-labels "Vanaf" en "Tot en met".
   - Native `<textarea>` voor de motivatie — zelfde deviation als
     10.2: `<x-ui.text-input>` rendert een `<input>`, geen textarea.
     `aria-required` wordt alleen gezet wanneer rol == employee.
   - Bevestigingsblok `role="status" aria-live="polite"` zodra
     `$confirmation` gezet is na een succesvolle submit.
   - Knoppen: secundair "Wissen" (reset client-side), primair
     "Indienen" (`type=submit` met loading-state).

  Toegankelijkheid:
   - Iedere `<select>`/`<textarea>` heeft een expliciete `<label for>`.
   - `aria-invalid` + `aria-describedby` koppelen foutmeldingen aan
     het juiste veld voor screenreaders.
   - Het bevestigingsblok kondigt zich automatisch aan via
     `aria-live="polite"`.
--}}
<div class="flex flex-col gap-4" data-livewire-component="hours.leave-form">
    @php
        /** @var \Illuminate\Support\ViewErrorBag $errors */
        $authUser = \Illuminate\Support\Facades\Auth::user();
        $authRole = $authUser?->role;
        $isEmployee = $authRole === 'employee';

        // Voor employee tonen we geen <select>; we tonen alleen de naam.
        $selfDisplayName = (string) (
            $authUser?->full_name
            ?? $authUser?->name
            ?? ''
        );

        $employees = $this->getRoleEmployees();
        $availableTypes = $this->getAvailableTypes();

        $typeError = $errors->first('type');
        $employeeError = $errors->first('employeeId');
        $dateFromError = $errors->first('dateFrom');
        $dateToError = $errors->first('dateTo');
        $noteError = $errors->first('note');
    @endphp

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

            {{-- Employee-select alleen voor manager/owner. Employee-rol
                 ziet alleen een readonly hint met de eigen naam. --}}
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
                {{-- Employee-rol: alleen tonen voor wie de melding is. --}}
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

            {{-- Motivatie (textarea — zelfde deviation als 10.2). --}}
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
</div>
