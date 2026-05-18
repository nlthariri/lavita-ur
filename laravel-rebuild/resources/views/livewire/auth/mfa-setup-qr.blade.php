<div class="mx-auto w-full max-w-xl py-12">
    {{--
        MFA-setup-view — Livewire-component `Auth\MfaSetupQr` (taak 9.3).

        Bron:
          - requirements.md 6.1  → eerste-keer QR-setup-stap toont secret,
              QR-image (data-URL via `endroid/qr-code`) en 8 recovery codes.
          - requirements.md 6.13 → WCAG 2.1 AA, design tokens uit design.md.
          - requirements.md 6.14 → NL-foutmeldingen.
          - tasks.md 9.3 → kopieer-knoppen met `aria-live`.

        WCAG / a11y-overwegingen:
          - Twee toestanden in dezelfde view: pre-setup (formulier) en
            post-setup (QR + secret + herstelcodes). De heading-structuur
            blijft consistent zodat screenreaders niet "verspringen".
          - Kopieer-knoppen sturen via Alpine een NL-bevestigingsboodschap
            naar een `aria-live="polite"`-region. We gebruiken `polite` zodat
            screenreaders de melding aankondigen zonder de huidige
            voorlees-context te onderbreken (WCAG 4.1.3).
          - Het QR-`<img>` heeft een betekenisvolle NL `alt` zodat blind
            users weten dat ze met de tab daarna naar de tekst-fallback
            (het secret) kunnen.
          - Het secret en de codes staan in een Geist Mono `code`-blok zodat
            ze niet visueel verward kunnen worden met gewone tekst (NFR-4
            typografie-token `code`).
    --}}
    <x-ui.card>
        <x-slot:header>
            <h1 id="mfa-setup-heading" class="text-heading-2 font-semibold text-ink">
                MFA instellen
            </h1>
            <p class="mt-2 text-body-sm text-steel">
                Versterk je account met een tweede factor. Bevestig je wachtwoord
                en scan de QR-code in een authenticator-app zoals Google
                Authenticator, Authy of 1Password.
            </p>
        </x-slot:header>

        @if (! $setupComplete)
            {{-- ─── Pre-setup-state: wachtwoord-bevestiging ──────────────── --}}
            <form
                wire:submit="submit"
                method="POST"
                action="#"
                novalidate
                aria-labelledby="mfa-setup-heading"
                class="flex flex-col gap-4"
            >
                <input type="hidden" name="user_id" value="{{ $userId }}">

                <x-ui.text-input
                    name="password"
                    type="password"
                    label="Bevestig je huidige wachtwoord"
                    autocomplete="current-password"
                    required
                    wire:model.blur="password"
                    :error="$errors->first('password')"
                    help="Vul ter beveiliging je huidige wachtwoord opnieuw in voordat we de QR-code genereren."
                />

                <div class="mt-2 flex flex-col gap-3">
                    <x-ui.button
                        type="submit"
                        variant="primary"
                        class="w-full"
                        wire:loading.attr="disabled"
                        wire:target="submit"
                    >
                        <span wire:loading.remove wire:target="submit">Genereer MFA</span>
                        <span wire:loading wire:target="submit" aria-live="polite">
                            Bezig met genereren…
                        </span>
                    </x-ui.button>

                    <a
                        href="{{ url('/inloggen') }}"
                        class="text-center text-body-sm text-steel underline focus-visible:rounded focus-visible:outline-2"
                    >
                        Terug naar inloggen
                    </a>
                </div>
            </form>
        @else
            {{-- ─── Post-setup-state: QR + secret + recovery codes ───────── --}}
            <div class="flex flex-col gap-6">
                {{-- QR-blok --}}
                <section aria-labelledby="mfa-setup-qr-title" class="flex flex-col items-center gap-3">
                    <h2 id="mfa-setup-qr-title" class="text-button-md font-medium text-ink">
                        Stap 1 — Scan deze QR-code
                    </h2>

                    @if ($qrDataUrl)
                        <img
                            src="{{ $qrDataUrl }}"
                            alt="QR-code voor MFA-setup"
                            width="240"
                            height="240"
                            class="rounded-card border border-hairline bg-canvas p-2"
                        >
                    @else
                        <div
                            role="note"
                            class="rounded-input border border-hairline bg-surface px-4 py-3 text-body-sm text-steel"
                        >
                            QR-code is alleen tijdens setup beschikbaar. Voer in
                            plaats daarvan het secret hieronder handmatig in je
                            authenticator-app in.
                        </div>
                    @endif
                </section>

                {{-- Secret + kopieer-knop --}}
                @if ($secret !== null)
                    <section
                        aria-labelledby="mfa-setup-secret-title"
                        class="flex flex-col gap-2"
                        x-data="{
                            copied: false,
                            announce: '',
                            copy() {
                                navigator.clipboard.writeText(@js($secret)).then(() => {
                                    this.copied = true;
                                    this.announce = 'Gekopieerd!';
                                    setTimeout(() => {
                                        this.copied = false;
                                        this.announce = '';
                                    }, 2000);
                                });
                            },
                        }"
                    >
                        <h2 id="mfa-setup-secret-title" class="text-button-md font-medium text-ink">
                            Stap 2 — Of kopieer het secret handmatig
                        </h2>

                        <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center">
                            <code
                                class="block flex-1 select-all break-all rounded-input border border-hairline bg-surface px-3 py-2 font-mono text-body-sm text-ink"
                                aria-label="MFA-secret"
                            >{{ $secret }}</code>

                            <x-ui.button
                                variant="secondary"
                                type="button"
                                x-on:click="copy()"
                                aria-controls="mfa-setup-secret-status"
                            >
                                <span x-show="!copied">Kopieer secret</span>
                                <span x-show="copied" x-cloak>Gekopieerd!</span>
                            </x-ui.button>
                        </div>

                        {{-- aria-live region — screenreader-aankondiging na kopiëren. --}}
                        <span
                            id="mfa-setup-secret-status"
                            class="sr-only"
                            role="status"
                            aria-live="polite"
                            x-text="announce"
                        ></span>
                    </section>
                @endif

                {{-- Recovery codes + kopieer-alle-knop --}}
                @if (count($recoveryCodes) > 0)
                    @php
                        // Plain-text-kopie: één code per regel, makkelijk plakbaar
                        // in een wachtwoordmanager of notitieblok.
                        $recoveryClipboard = implode("\n", $recoveryCodes);
                    @endphp
                    <section
                        aria-labelledby="mfa-setup-recovery-title"
                        class="flex flex-col gap-3"
                        x-data="{
                            copied: false,
                            announce: '',
                            copyAll() {
                                navigator.clipboard.writeText(@js($recoveryClipboard)).then(() => {
                                    this.copied = true;
                                    this.announce = 'Gekopieerd!';
                                    setTimeout(() => {
                                        this.copied = false;
                                        this.announce = '';
                                    }, 2000);
                                });
                            },
                        }"
                    >
                        <h2 id="mfa-setup-recovery-title" class="text-button-md font-medium text-ink">
                            Stap 3 — Bewaar je 8 herstelcodes op een veilige plek
                        </h2>
                        <p class="text-body-sm text-steel">
                            Elke code werkt eenmalig. Gebruik ze als je geen
                            toegang hebt tot je authenticator-app.
                        </p>

                        <ul
                            class="grid grid-cols-1 gap-2 tablet:grid-cols-2"
                            data-testid="mfa-recovery-codes"
                        >
                            @foreach ($recoveryCodes as $code)
                                <li>
                                    <code
                                        class="block select-all rounded-input border border-hairline bg-surface px-3 py-2 font-mono text-body-sm text-ink"
                                    >{{ $code }}</code>
                                </li>
                            @endforeach
                        </ul>

                        <div class="flex justify-start">
                            <x-ui.button
                                variant="secondary"
                                type="button"
                                x-on:click="copyAll()"
                                aria-controls="mfa-setup-recovery-status"
                            >
                                <span x-show="!copied">Kopieer alle herstelcodes</span>
                                <span x-show="copied" x-cloak>Gekopieerd!</span>
                            </x-ui.button>
                        </div>

                        <span
                            id="mfa-setup-recovery-status"
                            class="sr-only"
                            role="status"
                            aria-live="polite"
                            x-text="announce"
                        ></span>
                    </section>
                @endif

                <div class="flex flex-col gap-2 border-t border-hairline pt-4">
                    <a
                        href="{{ url('/auth/mfa-verify') }}"
                        class="rounded-button bg-primary px-4 py-2 text-center text-button-md text-on-primary no-underline"
                    >
                        Doorgaan naar MFA-verificatie
                    </a>
                </div>
            </div>
        @endif
    </x-ui.card>
</div>
