{{--
  Livewire-view — `Hours\EntryFormModal` (taak 10.2 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.3  → invoermodal met live netto-minuten-berekening,
       ATW-pre-validatie vóór opslaan, project- + kostenplaats-selector.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels, NL-foutmeldingen.

  Compositie:
   - Wanneer `! $isOpen` rendert deze view een lege wrapper. Het component
     blijft op de pagina staan zodat het component listent op het
     `open-entry-form-modal`-event, zonder backdrop of formulier-DOM.
   - Wanneer `$isOpen`:
       1. Backdrop (`role="presentation"`) die op klik `closeModal` triggert.
       2. Modal-paneel (`role="dialog" aria-modal="true"
          aria-labelledby="entry-modal-heading"`) met het invoer-formulier
          binnen `<x-ui.card>`.
       3. Live netto-minuten-blok (`aria-live="polite"`).
       4. ATW-resultblok dat `role="alert"` (kritiek) of `role="status"`
          (waarschuwing) gebruikt zodat screenreaders de meldingen
          aankondigen op het moment dat ze verschijnen.

  Design-token-discipline:
   - `<x-ui.card>` voor het paneel, `<x-ui.button>` voor de actieknoppen,
     `<x-ui.text-input>` voor de scalar-velden (datum, tijden, pauze).
   - Native `<select>` voor type/project/kostenplaats — `<x-ui.text-input>`
     ondersteunt geen `type=select` (taak 8.5 levert alleen scalar-types).
     We mirroren de input-token-styling (border-2, brand-green focus, h-10).
   - Native `<textarea>` voor de notitie — geen textarea-mode op het
     UI-atom.
--}}
<div data-livewire-component="hours.entry-form-modal">
    @if ($isOpen)
        @php
            /** @var \Illuminate\Support\ViewErrorBag $errors */
            $authUser = \Illuminate\Support\Facades\Auth::user();
            $authRole = $authUser?->role;

            // Helper voor de live "Netto: Xu Ymin"-tekst.
            $netMinutesValue = $this->getNetMinutes();
            $hours = intdiv($netMinutesValue, 60);
            $minutes = $netMinutesValue % 60;

            // ATW-result-shape (zie AtwService::validateProposedShift).
            $atw = $atwResult ?? null;
            $hasCritical = (bool) ($atw['has_critical'] ?? false);
            $signals = is_array($atw['signals'] ?? null) ? $atw['signals'] : [];
            $criticalSignals = array_values(array_filter(
                $signals,
                static fn ($s) => is_array($s) && (($s['severity'] ?? '') === 'critical')
            ));
            $warningSignals = array_values(array_filter(
                $signals,
                static fn ($s) => is_array($s) && (($s['severity'] ?? '') === 'warning')
            ));

            // De button-disabled-state hangt af van submit-status én ATW-blokkade.
            $submitDisabled = $isSubmitting || $hasCritical;

            // Algemene `atw`-foutmelding (niet aan een veld gekoppeld).
            $atwError = $errors->first('atw');
        @endphp

        {{-- Backdrop — klik sluit de modal. --}}
        <div
            role="presentation"
            wire:click="closeModal"
            class="fixed inset-0 z-40 bg-ink/60"
            data-testid="entry-form-modal-backdrop"
        ></div>

        {{-- Modal-paneel — gecentreerd, scrollbaar bij smalle viewports. --}}
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="entry-modal-heading"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4"
        >
            <div class="w-full max-w-xl">
                <x-ui.card>
                    <x-slot:header>
                        <h2
                            id="entry-modal-heading"
                            class="text-heading-2 font-semibold text-ink"
                        >
                            Uurregel toevoegen
                        </h2>
                    </x-slot:header>

                    <form
                        wire:submit.prevent="submit"
                        method="POST"
                        action="#"
                        novalidate
                        aria-labelledby="entry-modal-heading"
                        class="flex flex-col gap-4"
                    >
                        {{-- Datum --}}
                        <x-ui.text-input
                            name="entryDate"
                            type="date"
                            label="Datum"
                            required
                            wire:model.live="entryDate"
                            :value="$entryDate"
                            :error="$errors->first('entryDate')"
                        />

                        {{-- Type-select. HOLIDAY verbergen voor employees (Req 7.2). --}}
                        @php
                            $typeError = $errors->first('type');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label
                                for="entry-type"
                                class="text-body-sm font-medium text-ink"
                            >
                                Type
                                <span class="text-danger" aria-hidden="true">*</span>
                                <span class="sr-only">(verplicht)</span>
                            </label>
                            <select
                                id="entry-type"
                                name="type"
                                wire:model.live="type"
                                required
                                @if ($typeError) aria-invalid="true" aria-describedby="entry-type-error" @endif
                                class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                            >
                                @foreach ($this->getAvailableTypes() as $code => $label)
                                    @if ($code === 'HOLIDAY' && $authRole === 'employee')
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
                                    id="entry-type-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $typeError }}</p>
                            @endif
                        </div>

                        {{-- Tijden naast elkaar op desktop, gestapeld op mobiel. --}}
                        <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                            <x-ui.text-input
                                name="startTime"
                                type="time"
                                label="Begintijd"
                                required
                                wire:model.live="startTime"
                                :value="$startTime"
                                :error="$errors->first('startTime')"
                            />
                            <x-ui.text-input
                                name="endTime"
                                type="time"
                                label="Eindtijd"
                                required
                                wire:model.live="endTime"
                                :value="$endTime"
                                :error="$errors->first('endTime')"
                            />
                        </div>

                        <x-ui.text-input
                            name="pauseMinutes"
                            type="number"
                            label="Pauze (in minuten)"
                            required
                            min="0"
                            max="480"
                            step="5"
                            wire:model.live="pauseMinutes"
                            :value="$pauseMinutes"
                            :error="$errors->first('pauseMinutes')"
                            help="Bij meer dan 5,5 uur werken is minimaal 30 minuten pauze verplicht."
                        />

                        {{-- Live netto-minuten-feedback. --}}
                        <p
                            class="rounded-input border border-hairline bg-surface px-3 py-2 font-mono text-body-sm text-ink"
                            aria-live="polite"
                            data-testid="entry-net-minutes"
                        >
                            Netto:
                            @if ($hours > 0 && $minutes > 0)
                                {{ $hours }}u {{ $minutes }}min
                            @elseif ($hours > 0)
                                {{ $hours }}u
                            @else
                                {{ $minutes }}min
                            @endif
                            ({{ $netMinutesValue }} minuten)
                        </p>

                        {{-- Project-select (optioneel). --}}
                        @php
                            $projects = $this->getProjects(app(\App\Services\ProjectsService::class));
                            $projectError = $errors->first('projectId');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label for="entry-project" class="text-body-sm font-medium text-ink">
                                Project
                            </label>
                            <select
                                id="entry-project"
                                name="projectId"
                                wire:model.live="projectId"
                                @if ($projectError) aria-invalid="true" aria-describedby="entry-project-error" @endif
                                class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                            >
                                <option value="">Geen project</option>
                                @foreach ($projects as $id => $name)
                                    <option value="{{ $id }}" @selected($projectId === (int) $id)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @if ($projectError)
                                <p
                                    id="entry-project-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $projectError }}</p>
                            @endif
                        </div>

                        {{-- Kostenplaats-select (optioneel). --}}
                        @php
                            $costCenters = $this->getCostCenters(app(\App\Services\CostCentersService::class));
                            $costCenterError = $errors->first('costCenterId');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label for="entry-cost-center" class="text-body-sm font-medium text-ink">
                                Kostenplaats
                            </label>
                            <select
                                id="entry-cost-center"
                                name="costCenterId"
                                wire:model.live="costCenterId"
                                @if ($costCenterError) aria-invalid="true" aria-describedby="entry-cost-center-error" @endif
                                class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                            >
                                <option value="">Geen kostenplaats</option>
                                @foreach ($costCenters as $id => $name)
                                    <option value="{{ $id }}" @selected($costCenterId === (int) $id)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @if ($costCenterError)
                                <p
                                    id="entry-cost-center-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $costCenterError }}</p>
                            @endif
                        </div>

                        {{-- Notitie (textarea — geen textarea-mode op `<x-ui.text-input>`). --}}
                        @php
                            $noteError = $errors->first('note');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label for="entry-note" class="text-body-sm font-medium text-ink">
                                Notitie
                            </label>
                            <textarea
                                id="entry-note"
                                name="note"
                                rows="3"
                                wire:model.blur="note"
                                @if ($noteError) aria-invalid="true" aria-describedby="entry-note-error" @endif
                                class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                            >{{ $note }}</textarea>
                            @if ($noteError)
                                <p
                                    id="entry-note-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $noteError }}</p>
                            @endif
                        </div>

                        {{-- ATW-resultblok: kritiek > waarschuwing. --}}
                        @if ($hasCritical)
                            <div
                                role="alert"
                                aria-live="assertive"
                                data-testid="atw-critical"
                                class="rounded-input border border-danger/40 bg-danger-bg px-3 py-2 text-body-sm text-danger-fg"
                            >
                                <p class="font-medium">ATW-fouten — eerst oplossen</p>
                                <ul class="mt-1 list-disc space-y-1 pl-5">
                                    @foreach ($criticalSignals as $signal)
                                        <li>
                                            <span class="font-mono text-body-sm">[{{ $signal['type'] ?? '' }}]</span>
                                            {{ $signal['message'] ?? ($signal['type'] ?? '') }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @elseif (count($warningSignals) > 0)
                            <div
                                role="status"
                                aria-live="polite"
                                data-testid="atw-warning"
                                class="rounded-input border border-warning/40 bg-warning-bg px-3 py-2 text-body-sm text-warning-fg"
                            >
                                <p class="font-medium">ATW-waarschuwing</p>
                                <ul class="mt-1 list-disc space-y-1 pl-5">
                                    @foreach ($warningSignals as $signal)
                                        <li>
                                            <span class="font-mono text-body-sm">[{{ $signal['type'] ?? '' }}]</span>
                                            {{ $signal['message'] ?? ($signal['type'] ?? '') }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- ATW-veldfout (eg. "Kon ATW-validatie niet uitvoeren"). --}}
                        @if ($atwError)
                            <p
                                role="alert"
                                aria-live="polite"
                                class="rounded-input border border-danger/40 bg-danger/10 px-3 py-2 text-body-sm text-danger"
                                data-testid="atw-field-error"
                            >{{ $atwError }}</p>
                        @endif

                        {{-- Acties --}}
                        <div class="mt-2 flex flex-col-reverse gap-3 tablet:flex-row tablet:justify-end">
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                wire:click="closeModal"
                            >Annuleren</x-ui.button>

                            <x-ui.button
                                type="submit"
                                variant="primary"
                                :disabled="$submitDisabled"
                                wire:loading.attr="disabled"
                                wire:target="submit"
                            >
                                <span wire:loading.remove wire:target="submit">Opslaan</span>
                                <span wire:loading wire:target="submit" aria-live="polite">Bezig met opslaan…</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        </div>
    @endif
</div>
