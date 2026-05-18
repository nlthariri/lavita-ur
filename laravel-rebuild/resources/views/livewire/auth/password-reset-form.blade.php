<div class="mx-auto w-full max-w-md py-12">
    {{--
        Password-reset-view — Livewire-component `Auth\PasswordResetForm` (taak 9.4).

        Bron:
          - requirements.md 6.11 → "Wachtwoord vergeten/reset" met tokenvalidatie
              en wachtwoordsterkte-indicator (min 12 tekens, mix
              hoofd/klein/cijfer/symbool).
          - requirements.md 6.13 → WCAG 2.1 AA, design tokens uit design.md.
          - requirements.md 6.14 → NL-foutmeldingen.

        Sterkte-indicator (tasks.md 9.4):
          - 4-segment-bar als visuele weergave (1 segment per score-punt).
          - `role="meter"` met `aria-valuemin/max/now/text` zodat screen-
            readers de sterkte als een numerieke + tekstuele waarde kunnen
            voorlezen (WCAG 4.1.2 + ARIA 1.2 § meter).
          - Visueel NL-label `aria-hidden="true"` zodat sighted users 'm
            zien zonder dat screenreaders hem dubbel oplezen (de
            `aria-valuetext` doet al de voorlees-rol).

        Token-fout-state:
          - `role="alert" aria-live="assertive"` zodat een kapotte resetlink
            direct wordt aangekondigd (WCAG 4.1.3, hoge urgentie).
          - Form-velden worden disabled zodat de gebruiker niet zinloos
            een nieuw wachtwoord typt.
    --}}
    <x-ui.card>
        <x-slot:header>
            <h1 id="reset-heading" class="text-heading-2 font-semibold text-ink">
                Nieuw wachtwoord instellen
            </h1>
            <p class="mt-2 text-body-sm text-steel">
                Kies een wachtwoord van minimaal 12 tekens met een hoofdletter,
                een kleine letter, een cijfer en een symbool. Bevestig daarna
                je nieuwe wachtwoord.
            </p>
        </x-slot:header>

        @if ($tokenError !== null)
            {{-- Prominente token-fout: assertive zodat het screenreader-
                 voorlees-buffer wordt onderbroken (WCAG 4.1.3 — high urgency). --}}
            <div
                role="alert"
                aria-live="assertive"
                data-testid="reset-token-error"
                class="mb-4 rounded-input border border-danger/40 bg-danger/10 px-3 py-2 text-body-sm text-danger"
            >
                <p class="font-medium">{{ $tokenError }}</p>
                <p class="mt-2">
                    <a
                        href="{{ url('/wachtwoord-vergeten') }}"
                        class="underline focus-visible:rounded focus-visible:outline-2"
                    >
                        Vraag een nieuwe resetlink aan
                    </a>
                </p>
            </div>
        @endif

        <form
            wire:submit="submit"
            method="POST"
            action="#"
            novalidate
            aria-labelledby="reset-heading"
            class="flex flex-col gap-4"
        >
            {{-- Token roundtrips via een hidden input zodat een POST zonder
                 JS (bv. tijdens hydration-loss) hem nog steeds meeneemt. --}}
            <input type="hidden" name="token" wire:model="token">

            {{-- Nieuw wachtwoord — debounce 150ms op `wire:model.live` zodat
                 de sterkte-bar real-time meebeweegt zonder elk toets-event
                 een hele Livewire-roundtrip te triggeren. --}}
            <x-ui.text-input
                name="password"
                type="password"
                label="Nieuw wachtwoord"
                autocomplete="new-password"
                required
                :disabled="$tokenError !== null"
                wire:model.live.debounce.150ms="password"
                aria-describedby="password-strength password-help"
                :error="$errors->first('password')"
            />

            <p id="password-help" class="text-body-sm text-steel">
                Minimaal 12 tekens met hoofdletter, kleine letter, cijfer en symbool.
            </p>

            {{-- Sterkte-indicator: 4-segment-bar.
                 - `role="meter"` + aria-valuemin/max/now/text → screenreaders
                   krijgen de score als getal + NL-tekst.
                 - `aria-label="Wachtwoordsterkte"` zodat de meter een
                   accessible name heeft (sommige SR's lezen dat liever dan
                   het bijbehorende `<label>` van de input).
                 - Segmenten lichten op in brand-mint zodra hun index ≤ score.
                   Bij score=0 zijn ze allemaal grijs (hairline).
            --}}
            @php
                $strengthScore = $this->getStrengthScore();
                $strengthLabel = $this->getStrengthLabel();
                $meetsPolicy = $this->getStrengthMeetsPolicy();
            @endphp
            <div
                id="password-strength"
                role="meter"
                aria-label="Wachtwoordsterkte"
                aria-valuemin="0"
                aria-valuemax="4"
                aria-valuenow="{{ $strengthScore }}"
                aria-valuetext="{{ $strengthLabel }}"
                data-testid="password-strength-meter"
                class="flex flex-col gap-1"
            >
                <div class="flex gap-1" aria-hidden="true">
                    @for ($i = 1; $i <= 4; $i++)
                        <span
                            data-segment="{{ $i }}"
                            class="h-2 flex-1 rounded-full {{ $strengthScore >= $i ? 'bg-brand-green' : 'bg-hairline' }}"
                        ></span>
                    @endfor
                </div>
                <span
                    aria-hidden="true"
                    data-testid="password-strength-label"
                    class="text-body-sm {{ $meetsPolicy ? 'text-success-fg' : 'text-steel' }}"
                >
                    Sterkte: {{ $strengthLabel }}
                </span>
            </div>

            {{-- Bevestig nieuw wachtwoord. --}}
            <x-ui.text-input
                name="passwordConfirmation"
                type="password"
                label="Bevestig nieuw wachtwoord"
                autocomplete="new-password"
                required
                :disabled="$tokenError !== null"
                wire:model.blur="passwordConfirmation"
                :error="$errors->first('passwordConfirmation')"
            />

            <div class="mt-2 flex flex-col gap-3">
                <x-ui.button
                    type="submit"
                    variant="primary"
                    class="w-full"
                    :disabled="! $meetsPolicy || $tokenError !== null"
                    aria-disabled="{{ (! $meetsPolicy || $tokenError !== null) ? 'true' : 'false' }}"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    <span wire:loading.remove wire:target="submit">Wachtwoord opslaan</span>
                    <span wire:loading wire:target="submit" aria-live="polite">Bezig met opslaan…</span>
                </x-ui.button>

                <a
                    href="{{ url('/inloggen') }}"
                    class="text-center text-body-sm text-steel underline focus-visible:rounded focus-visible:outline-2"
                >
                    Terug naar inloggen
                </a>
            </div>
        </form>
    </x-ui.card>
</div>
