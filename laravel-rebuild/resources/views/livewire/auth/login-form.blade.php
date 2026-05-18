<div class="mx-auto w-full max-w-md py-12">
    {{--
        Login-form view — Livewire-component `Auth\LoginForm`.

        Bron:
          - requirements.md 6.1  → scherm "Inlog + MFA + QR" op `/inloggen`.
          - requirements.md 6.13 → WCAG 2.1 AA, design tokens uit design.md.
          - requirements.md 6.14 → NL-foutmeldingen.
          - design.md § Components and Interfaces > Design tokens (kleur/typografie/radius).

        WCAG / a11y-overwegingen:
          - `<form role="form" aria-labelledby="login-heading" novalidate>` — Livewire
            handelt validatie af, dus we sluiten browser-side validatie uit.
          - `aria-live="polite"` op een algemene foutregio voor non-veld-fouten.
          - Velden hebben verplicht-marker via `<x-ui.text-input :required>` die
            zelf de `(verplicht)` sr-only-tekst en het rode sterretje rendert.
          - Foutmeldingen renderen via `:error` op de input → koppelt automatisch
            `aria-invalid` + `aria-describedby` op het input-element.
    --}}
    <x-ui.card>
        <x-slot:header>
            <h1 id="login-heading" class="text-heading-2 font-semibold text-ink">
                Inloggen
            </h1>
            <p class="mt-2 text-body-sm text-steel">
                Log in met je e-mailadres en wachtwoord. Vervolgens vragen we je MFA-code.
            </p>
        </x-slot:header>

        <form
            wire:submit="submit"
            method="POST"
            action="#"
            novalidate
            aria-labelledby="login-heading"
            class="flex flex-col gap-4"
        >
            {{-- E-mailadres --}}
            <x-ui.text-input
                name="email"
                type="email"
                label="E-mailadres"
                autocomplete="username"
                required
                wire:model.blur="email"
                :value="$email"
                :error="$errors->first('email')"
            />

            {{-- Wachtwoord --}}
            <x-ui.text-input
                name="password"
                type="password"
                label="Wachtwoord"
                autocomplete="current-password"
                required
                wire:model.blur="password"
                :error="$errors->first('password')"
                help="Minimaal 12 tekens."
            />

            {{--
                Algemene foutregio voor niet-veld-fouten. We renderen ook hier
                de email-fout, voor screenreaders die formulier-fouten als één
                gegroepeerde mededeling verwachten (WCAG 3.3.1).
            --}}
            @if ($errors->any() && ! $errors->has('email') && ! $errors->has('password'))
                <div
                    role="alert"
                    aria-live="polite"
                    class="rounded-input border border-danger/40 bg-danger/10 px-3 py-2 text-body-sm text-danger"
                >
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="mt-2 flex flex-col gap-3">
                <x-ui.button
                    type="submit"
                    variant="primary"
                    class="w-full"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    <span wire:loading.remove wire:target="submit">Inloggen</span>
                    <span wire:loading wire:target="submit" aria-live="polite">Bezig met inloggen…</span>
                </x-ui.button>

                <a
                    href="{{ url('/wachtwoord-vergeten') }}"
                    class="text-center text-body-sm text-steel underline focus-visible:rounded focus-visible:outline-2"
                >
                    Wachtwoord vergeten?
                </a>
            </div>
        </form>
    </x-ui.card>
</div>
