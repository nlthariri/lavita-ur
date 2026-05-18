{{--
  Livewire-view — `Objections\NewObjectionForm` (taak 10.3 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.4  → bezwaar indienen op finalized werkregels.
   - requirements.md 4.x  → motivatie ≥10 / ≤2000 tekens, één open
       bezwaar per regel; service handhaaft die regels.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).

  Compositie:
   - Wanneer `! $isOpen` rendert de view alleen een lege wrapper zodat
     het component op pagina aanwezig is en kan luisteren op het
     `open-new-objection`-event, zonder backdrop of formulier-DOM.
   - Wanneer `$isOpen`:
       1. Backdrop (`role="presentation"`) die op klik `closeModal` triggert.
       2. Modal-paneel (`role="dialog" aria-modal="true"
          aria-labelledby="objection-modal-heading"`) met het invoer-formulier
          binnen `<x-ui.card>`.
       3. Live char-counter (`aria-live="polite"`).
       4. Optionele bevestigings-block (`role="status"`) na submit.

  Design-token-discipline:
   - `<x-ui.card>` voor het paneel, `<x-ui.button>` voor de actieknoppen.
   - Native `<textarea>` voor de motivatie — geen textarea-mode op het
     UI-atom (zelfde deviation als taak 10.2).
--}}
<div data-livewire-component="objections.new-objection-form">
    @if ($isOpen)
        @php
            /** @var \Illuminate\Support\ViewErrorBag $errors */
            $motivationError = $errors->first('motivation');

            $motivationLength = mb_strlen($motivation);
            $submitDisabled = $motivationLength < 10;
        @endphp

        {{-- Backdrop — klik sluit de modal. --}}
        <div
            role="presentation"
            wire:click="closeModal"
            class="fixed inset-0 z-40 bg-ink/60"
            data-testid="new-objection-modal-backdrop"
        ></div>

        {{-- Modal-paneel --}}
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="objection-modal-heading"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4"
        >
            <div class="w-full max-w-xl">
                <x-ui.card>
                    <x-slot:header>
                        <h2
                            id="objection-modal-heading"
                            class="text-heading-2 font-semibold text-ink"
                        >
                            Bezwaar indienen
                        </h2>
                    </x-slot:header>

                    <p class="text-body-md text-ink">
                        Geef de reden van je bezwaar (minimaal 10, maximaal 2000 tekens).
                    </p>

                    <form
                        wire:submit.prevent="submit"
                        method="POST"
                        action="#"
                        novalidate
                        aria-labelledby="objection-modal-heading"
                        class="mt-4 flex flex-col gap-4"
                    >
                        <div class="flex flex-col gap-1">
                            <label
                                for="objection-motivation"
                                class="text-body-sm font-medium text-ink"
                            >
                                Motivatie
                                <span class="text-danger" aria-hidden="true">*</span>
                                <span class="sr-only">(verplicht)</span>
                            </label>
                            <textarea
                                id="objection-motivation"
                                name="motivation"
                                rows="6"
                                required
                                maxlength="2000"
                                wire:model.live.debounce.150ms="motivation"
                                @if ($motivationError) aria-invalid="true" aria-describedby="objection-motivation-error objection-motivation-counter" @else aria-describedby="objection-motivation-counter" @endif
                                class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                            >{{ $motivation }}</textarea>

                            @if ($motivationError)
                                <p
                                    id="objection-motivation-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $motivationError }}</p>
                            @endif

                            <p
                                id="objection-motivation-counter"
                                class="text-body-sm text-steel"
                                aria-live="polite"
                            >
                                {{ $motivationLength }} / 2000 tekens
                            </p>
                        </div>

                        @if ($confirmation !== null && $confirmation !== '')
                            <div
                                role="status"
                                aria-live="polite"
                                class="rounded-input border border-success/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
                                data-testid="new-objection-confirmation"
                            >
                                {{ $confirmation }}
                            </div>
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
                                <span wire:loading.remove wire:target="submit">Indienen</span>
                                <span wire:loading wire:target="submit" aria-live="polite">Bezig met indienen…</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        </div>
    @endif
</div>
