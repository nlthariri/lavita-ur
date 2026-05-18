<div class="mx-auto w-full max-w-md py-12">
    {{--
        Password-forgot-view — Livewire-component `Auth\PasswordForgotForm` (taak 9.4).

        Bron:
          - requirements.md 6.11 → scherm "Wachtwoord vergeten/reset" met
              tokenvalidatie en wachtwoordsterkte-indicator (sterkte-indicator
              zit in {@see PasswordResetForm}; deze view is alleen het
              vergeet-formulier).
          - requirements.md 6.13 → WCAG 2.1 AA, design tokens uit design.md.
          - requirements.md 6.14 → NL-foutmeldingen.
          - design.md § Components and Interfaces > Frontend componenten →
              component `Auth\PasswordForgotForm` op `/wachtwoord-vergeten`.

        WCAG / a11y-overwegingen:
          - `<section aria-labelledby="forgot-heading">` als landmark zodat
            screenreaders de scope van de form kunnen aankondigen.
          - `<form novalidate>` — Livewire valideert server-side; we sluiten
            browser-validatie uit zodat NL-meldingen niet door een Engelse
            browser-tooltip overruled worden.
          - Bevestigingsbanner gebruikt `role="status" aria-live="polite"`
            zodat de NL-tekst ALTIJD wordt aangekondigd, ook als hij
            verschijnt zonder dat het formulier opnieuw rendert.
          - Verplicht-marker en sterretje worden door `<x-ui.text-input>`
            zelf gerenderd (sr-only "(verplicht)" + visueel rood `*`).
    --}}
    <x-ui.card>
        <x-slot:header>
            <h1 id="forgot-heading" class="text-heading-2 font-semibold text-ink">
                Wachtwoord vergeten
            </h1>
            <p class="mt-2 text-body-sm text-steel">
                Vul je e-mailadres in. Als er een account bij ons bekend is met
                dit adres, sturen we je een resetlink waarmee je een nieuw
                wachtwoord kunt instellen.
            </p>
        </x-slot:header>

        <section aria-labelledby="forgot-heading">
            {{-- Generieke NL-bevestiging — verschijnt na elke submit, ongeacht
                 of het adres bekend is (security-best-practice tegen account-
                 enumeratie). `aria-live="polite"` kondigt 'm aan voor
                 screenreaders zonder de huidige voorlees-context te
                 onderbreken (WCAG 4.1.3). --}}
            @if ($confirmation !== null)
                <div
                    role="status"
                    aria-live="polite"
                    data-testid="forgot-confirmation"
                    class="mb-4 rounded-input border border-success-fg/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
                >
                    {{ $confirmation }}
                </div>
            @endif

            <form
                wire:submit="submit"
                method="POST"
                action="#"
                novalidate
                aria-labelledby="forgot-heading"
                class="flex flex-col gap-4"
            >
                <x-ui.text-input
                    name="email"
                    type="email"
                    label="E-mailadres"
                    autocomplete="email"
                    required
                    wire:model.blur="email"
                    :value="$email"
                    :error="$errors->first('email')"
                />

                <div class="mt-2 flex flex-col gap-3">
                    <x-ui.button
                        type="submit"
                        variant="primary"
                        class="w-full"
                        wire:loading.attr="disabled"
                        wire:target="submit"
                    >
                        <span wire:loading.remove wire:target="submit">Stuur resetlink</span>
                        <span wire:loading wire:target="submit" aria-live="polite">Bezig met versturen…</span>
                    </x-ui.button>

                    <a
                        href="{{ url('/inloggen') }}"
                        class="text-center text-body-sm text-steel underline focus-visible:rounded focus-visible:outline-2"
                    >
                        Terug naar inloggen
                    </a>
                </div>
            </form>
        </section>
    </x-ui.card>
</div>
