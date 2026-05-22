{{--
  Livewire-view — `Hours\EntryFormModal` (taak 8.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.1  → auto-focus begintijd, tab-volgorde geoptimaliseerd.
   - requirements.md 6.2  → slimme defaults: placeholder vorige werkdag.
   - requirements.md 6.3  → live netto-minuten in formaat "X uur Y minuten".
   - requirements.md 6.4  → Enter = submit (als velden gevuld).
   - requirements.md 6.5  → Escape = sluiten.
   - requirements.md 6.6  → ATW-validatie vóór opslaan (oranje/rode banner).
   - requirements.md 6.7  → Toast (success) + event entry-saved.
   - requirements.md 6.8  → Inline NL-foutmeldingen, modal blijft open.
   - requirements.md 6.9  → Project- en kostenplaats-selector dropdowns.
   - requirements.md 6.10 → Focus-trap, role="dialog", aria-modal="true".

  Keyboard shortcuts (Alpine.js):
   - Enter: submit formulier als verplichte velden gevuld zijn.
   - Escape: sluit modal zonder opslaan.

  Focus-trap:
   - Tab/Shift+Tab blijft binnen de modal via Alpine.js x-trap.
   - Auto-focus op begintijd-veld bij openen.
--}}
<div data-livewire-component="hours.entry-form-modal">
    @if ($isOpen)
        @php
            /** @var \Illuminate\Support\ViewErrorBag $errors */
            $authUser = \Illuminate\Support\Facades\Auth::user();
            $authRole = $authUser?->role;

            // Helper voor de live "Netto werktijd"-tekst.
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

        {{-- Modal-paneel met focus-trap en keyboard shortcuts via Alpine.js. --}}
        <div
            x-data="{
                init() {
                    // Auto-focus op begintijd-veld bij openen (Req 6.1)
                    this.$nextTick(() => {
                        const startInput = this.$el.querySelector('[data-field=startTime]');
                        if (startInput) startInput.focus();
                    });
                },
                handleTab(e) {
                    // Focus-trap: houd Tab/Shift+Tab binnen de modal (Req 6.10)
                    const focusable = [...this.$el.querySelectorAll(
                        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])'
                    )];
                    if (!focusable.length) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }"
            x-on:keydown.escape.window="$wire.closeModal()"
            x-on:keydown.enter="
                if ($event.target.tagName === 'TEXTAREA') return;
                if ($event.target.tagName === 'SELECT') return;
                $event.preventDefault();
                const form = $el.querySelector('form');
                const start = form?.querySelector('[data-field=startTime]')?.value || '';
                const end = form?.querySelector('[data-field=endTime]')?.value || '';
                if (start && end) {
                    $wire.submit();
                }
            "
            x-on:keydown.tab="handleTab($event)"
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
                            {{ $this->isEditMode ? 'Uurregel bewerken' : 'Uurregel toevoegen' }}
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
                        {{-- Slimme defaults placeholder (Req 6.2) --}}
                        @if ($previousDayPlaceholder)
                            <p
                                class="rounded-input border border-blue-200 bg-blue-50 px-3 py-2 text-body-sm text-blue-700"
                                data-testid="previous-day-placeholder"
                                aria-label="Suggestie op basis van vorige werkdag"
                            >
                                <svg class="mr-1 inline-block h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {{ $previousDayPlaceholder }}
                            </p>
                        @endif

                        {{-- Datum --}}
                        <x-ui.text-input
                            name="entryDate"
                            type="date"
                            label="Datum"
                            required
                            wire:model.live="entryDate"
                            :value="$entryDate"
                            :error="$errors->first('entryDate')"
                            tabindex="0"
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
                                tabindex="0"
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

                        {{-- Tijden naast elkaar op desktop, gestapeld op mobiel.
                             Tab-volgorde: begintijd → eindtijd (Req 6.1) --}}
                        <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                            <x-ui.text-input
                                name="startTime"
                                type="time"
                                label="Begintijd"
                                required
                                wire:model.live="startTime"
                                :value="$startTime"
                                :error="$errors->first('startTime')"
                                data-field="startTime"
                                tabindex="1"
                                :placeholder="$previousDayPlaceholder ? substr($previousDayPlaceholder, 12, 5) : ''"
                            />
                            <x-ui.text-input
                                name="endTime"
                                type="time"
                                label="Eindtijd"
                                required
                                wire:model.live="endTime"
                                :value="$endTime"
                                :error="$errors->first('endTime')"
                                data-field="endTime"
                                tabindex="2"
                            />
                        </div>

                        {{-- Pauze (tabindex 3) --}}
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
                            tabindex="3"
                        />

                        {{-- Live netto-minuten-feedback in formaat "X uur Y minuten" (Req 6.3). --}}
                        <div
                            class="rounded-input border border-hairline bg-surface px-3 py-2 text-body-sm text-ink"
                            aria-live="polite"
                            data-testid="entry-net-minutes"
                        >
                            <span class="font-medium">Netto werktijd:</span>
                            <span class="font-mono">
                                @if ($hours > 0 && $minutes > 0)
                                    {{ $hours }} uur {{ $minutes }} minuten
                                @elseif ($hours > 0)
                                    {{ $hours }} uur 0 minuten
                                @else
                                    0 uur {{ $minutes }} minuten
                                @endif
                            </span>
                        </div>

                        {{-- Project-select (tabindex 4, Req 6.9). --}}
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
                                tabindex="4"
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

                        {{-- Kostenplaats-select (tabindex 5, Req 6.9). --}}
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
                                tabindex="5"
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

                        {{-- Notitie (tabindex 6). --}}
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
                                tabindex="6"
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

                        {{-- ATW-resultblok: kritiek > waarschuwing (Req 6.6). --}}
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

                        {{-- Acties (tabindex 7 voor opslaan) --}}
                        <div class="mt-2 flex flex-col-reverse gap-3 tablet:flex-row tablet:justify-between">
                            {{-- Verwijderen-knop: alleen in edit-modus --}}
                            @if ($this->isEditMode)
                                <x-ui.button
                                    type="button"
                                    variant="danger"
                                    wire:click="delete"
                                    wire:confirm="Weet je zeker dat je deze werkregel wilt verwijderen?"
                                    tabindex="9"
                                    data-testid="entry-delete-button"
                                >Verwijderen</x-ui.button>
                            @else
                                <div></div>
                            @endif

                            <div class="flex flex-col-reverse gap-3 tablet:flex-row">
                                <x-ui.button
                                    type="button"
                                    variant="secondary"
                                    wire:click="closeModal"
                                    tabindex="8"
                                >Annuleren</x-ui.button>

                                <x-ui.button
                                    type="submit"
                                    variant="primary"
                                    :disabled="$submitDisabled"
                                    wire:loading.attr="disabled"
                                    wire:target="submit"
                                    tabindex="7"
                                >
                                    <span wire:loading.remove wire:target="submit">Opslaan</span>
                                    <span wire:loading wire:target="submit" aria-live="polite">Bezig met opslaan…</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        </div>
    @endif
</div>
