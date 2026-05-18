<div class="mx-auto w-full max-w-md py-12">
    {{--
        MFA-verify-view — Livewire-component `Auth\MfaVerifyForm` (taak 9.2).

        Bron:
          - requirements.md 6.1  → 6-cijferige TOTP-stap na inloggen.
          - requirements.md 6.13 → WCAG 2.1 AA, design tokens uit design.md.
          - tasks.md 9.2 → Gebruik `<x-ui.card>`, `<x-ui.text-input>` met
              `inputmode="numeric"` + `autofocus`, `<x-ui.button>`, NL-labels.

        Throttle-feedback (tasks.md 9.2):
          - Boven het formulier rendert een `role="alert"` `aria-live="assertive"`
            block met de NL-melding zodra de RateLimiter de gebruiker blokkeert.

        Toegankelijkheid:
          - `<form aria-labelledby="mfa-heading" novalidate>` zodat browser-side
            validatie geen NL-foutmeldingen overschrijft.
          - `inputmode="numeric"` opent op mobiel het cijferklavier zonder de
            input op `type="number"` te zetten (recovery codes zijn alfanum).
          - `autocomplete="one-time-code"` activeert iOS/Android-suggesties
            voor SMS/2FA-apps die de code in het clipboard zetten.
          - `autofocus` zet de focus op het code-veld bij eerste render.
    --}}
    <x-ui.card>
        <x-slot:header>
            <h1 id="mfa-heading" class="text-heading-2 font-semibold text-ink">
                Verifieer met MFA
            </h1>
            <p class="mt-2 text-body-sm text-steel">
                Vul de 6-cijferige code uit je authenticator-app in. Heb je geen
                toegang? Gebruik dan een van je 10-tekens herstelcodes.
            </p>
        </x-slot:header>

        {{-- Throttle-melding (tasks.md 9.2 + req 6.14): NL-tekst, prominent. --}}
        @if ($throttleMessage)
            <div
                role="alert"
                aria-live="assertive"
                data-testid="mfa-throttle"
                class="mb-4 rounded-input border border-danger/40 bg-danger/10 px-3 py-2 text-body-sm text-danger"
            >
                {{ $throttleMessage }}
            </div>
        @endif

        <form
            wire:submit="submit"
            method="POST"
            action="#"
            novalidate
            aria-labelledby="mfa-heading"
            class="flex flex-col gap-4"
        >
            {{-- Hidden user_id-mirror voor non-JS fallback / form-introspectie. --}}
            <input type="hidden" name="user_id" value="{{ $userId }}">

            <x-ui.text-input
                name="code"
                type="text"
                label="MFA-code"
                inputmode="numeric"
                autocomplete="one-time-code"
                autofocus
                required
                maxlength="10"
                wire:model.blur="code"
                :error="$errors->first('code')"
                help="Voer 6 cijfers in (TOTP) of een 10-tekens herstelcode."
            />

            <div class="mt-2 flex flex-col gap-3">
                <x-ui.button
                    type="submit"
                    variant="primary"
                    class="w-full"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    <span wire:loading.remove wire:target="submit">Verifiëren</span>
                    <span wire:loading wire:target="submit" aria-live="polite">Bezig met verifiëren…</span>
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
