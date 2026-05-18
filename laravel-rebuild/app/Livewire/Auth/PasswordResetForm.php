<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Services\PasswordResetService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Livewire-component — `Auth\PasswordResetForm` (taak 9.4 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.11 → scherm "Wachtwoord vergeten/reset" op
 *      `/wachtwoord-reset` met tokenvalidatie en wachtwoordsterkte-indicator
 *      (min 12 tekens, mix hoofd/klein/cijfer/symbool).
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → Foutmeldingen en bevestigingen in het Nederlands.
 *  - requirements.md NFR-10 → UI/foutmeldingen in het Nederlands.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Wachtwoord vergeten/reset" → component
 *      `Auth\PasswordResetForm` op `/wachtwoord-reset`.
 *
 * Verantwoordelijkheid:
 *  - Token uit de URL halen (zelfde resolve-volgorde als
 *    {@see MfaVerifyForm::mount()}: methode-arg → query-string → request
 *    input → fallback `''`).
 *  - Wachtwoord innemen (`required|string|min:12|max:128`) plus bevestiging
 *    (`required|string|same:password`), beide met expliciete NL-meldingen.
 *  - Live sterkte-indicator: {@see getStrengthScore()} retourneert 0..4
 *    based op {length≥12, lower, upper, digit, symbol}, capped op 4 zodat
 *    de view een 4-segment-bar kan vullen. {@see getStrengthLabel()}
 *    geeft NL-labels. {@see getStrengthMeetsPolicy()} is true iff álle
 *    vijf criteria (length≥12, lower, upper, digit, symbol) gehaald zijn.
 *    Submit-knop is disabled zolang `getStrengthMeetsPolicy()` false is —
 *    zowel via `disabled`-attribute als `aria-disabled` zodat screen-
 *    readers het correct rapporteren (WCAG 4.1.2).
 *  - Submit roept {@see PasswordResetService::resetPassword()} aan binnen
 *    een try/catch op `ValidationException`. Errors uit de service worden
 *    op het juiste veld gemapt:
 *      - `token` → `addError('token', …)` + de publieke property
 *        `$tokenError` zodat de view een prominente assertive
 *        live-region boven het formulier kan tonen (resetlink kapot,
 *        gebruiker moet een nieuwe aanvragen).
 *      - `password` → `addError('password', …)` (bv. "kies een ander
 *        wachtwoord dan het huidige").
 *  - Op succes: redirect naar `/inloggen?reset=ok` zodat de login-view
 *    de bevestigingsbanner kan tonen (taak 9.5 zal de query-param
 *    behandelen; de huidige login-view negeert 'm zonder error).
 *
 * Bewust niet (taak 9.5):
 *  - Geen route-registratie in `routes/web.php` — die volgt in task 9.5.
 *  - Geen tokengeneratie of HMAC-verificatie hier — dat doet
 *    {@see PasswordResetService}.
 */
#[Layout('layouts.app')]
#[Title('Nieuw wachtwoord instellen — LaVita Urenregistratie')]
final class PasswordResetForm extends Component
{
    /**
     * Reset-token uit de URL. Wordt door
     * {@see PasswordResetService::resetPassword()} gedecodeerd, op
     * signature gevalideerd en op TTL gecontroleerd. Een ongeldig of
     * verlopen token resulteert in een NL-melding op `$tokenError`.
     */
    public string $token = '';

    /**
     * Nieuw wachtwoord. We binden 'm `wire:model.live` (met debounce in
     * de view) zodat de sterkte-indicator real-time meebeweegt zonder
     * elk toetsenbord-event te flushen.
     *
     * `min:12` op server-side dekt de spec-regel uit req 6.11; client-
     * side wordt dezelfde grens gehanteerd in {@see getStrengthMeetsPolicy()}.
     * `max:128` dekt redelijke memory-bound en voorkomt long-string-DOS
     * op de hash-functie.
     */
    #[Validate(
        rule: 'required|string|min:12|max:128',
        message: [
            'password.required' => 'Vul een nieuw wachtwoord in.',
            'password.string' => 'Wachtwoord is ongeldig.',
            'password.min' => 'Wachtwoord moet minimaal 12 tekens lang zijn.',
            'password.max' => 'Wachtwoord mag maximaal 128 tekens lang zijn.',
        ],
        attribute: ['password' => 'wachtwoord'],
        translate: false,
    )]
    public string $password = '';

    /**
     * Bevestiging van het nieuwe wachtwoord. Moet exact gelijk zijn aan
     * `$password` (`same:password`).
     */
    #[Validate(
        rule: 'required|string|same:password',
        message: [
            'passwordConfirmation.required' => 'Bevestig je nieuwe wachtwoord.',
            'passwordConfirmation.string' => 'Wachtwoordbevestiging is ongeldig.',
            'passwordConfirmation.same' => 'De wachtwoordbevestiging komt niet overeen met het nieuwe wachtwoord.',
        ],
        attribute: ['passwordConfirmation' => 'wachtwoordbevestiging'],
        translate: false,
    )]
    public string $passwordConfirmation = '';

    /**
     * Token-fout-property. Wordt gevuld zodra
     * {@see PasswordResetService::resetPassword()} een
     * `ValidationException` met key `token` gooit (resetlink ongeldig
     * of verlopen). De view rendert dit prominent als
     * `role="alert" aria-live="assertive"` block boven het formulier en
     * disabelt de form-velden zodat de gebruiker meteen ziet dat hij
     * een nieuwe link moet aanvragen.
     */
    public ?string $tokenError = null;

    /**
     * Mount-fase. Resolver-volgorde voor `$token`:
     *  1. expliciete methode-arg (test-fixture / route-binding).
     *  2. `request()->query('token')` (typische `/wachtwoord-reset?token=...`).
     *  3. `request()->input('token')` (POST-fallback).
     *  4. `''` als alles leeg is — dan rendert de view een token-fout-
     *     banner zodra de gebruiker probeert te submitten.
     *
     * Identieke volgorde aan {@see MfaVerifyForm::mount()} en
     * {@see MfaSetupQr::mount()} voor consistente UX.
     */
    public function mount(?string $token = null): void
    {
        $resolved = $token
            ?? request()->query('token')
            ?? request()->input('token')
            ?? '';

        $this->token = (string) $resolved;
    }

    /**
     * Sterkte-score van het huidige wachtwoord — 0..4.
     *
     * Regels (cumulatief, conform tasks.md 9.4):
     *  +1 als length ≥ 12
     *  +1 als heeft lowercase ([a-z])
     *  +1 als heeft uppercase ([A-Z])
     *  +1 als heeft digit ([0-9])
     *  +1 als heeft symbol ([^A-Za-z0-9])
     *
     * De ruwe som zit dus tussen 0 en 5, maar de bar in de view heeft 4
     * segmenten — dus we cappen op 4. Dit is intentioneel: zodra alle
     * vijf criteria gehaald zijn, is het wachtwoord "Zeer sterk" en is
     * elk segment van de bar gevuld.
     *
     * Bewust een gewone methode (geen `#[Computed]`) — Livewire's computed
     * properties cachen per request en herbouwen niet bij `wire:model.live`-
     * updates op het wachtwoord-veld zonder extra `forget()`-aanroep.
     * Een gewone method werkt out-of-the-box en is goedkoop genoeg op
     * een paar regex-checks.
     */
    public function getStrengthScore(): int
    {
        $value = $this->password;

        if ($value === '') {
            return 0;
        }

        $score = 0;

        if (mb_strlen($value) >= 12) {
            $score++;
        }
        if (preg_match('/[a-z]/', $value) === 1) {
            $score++;
        }
        if (preg_match('/[A-Z]/', $value) === 1) {
            $score++;
        }
        if (preg_match('/[0-9]/', $value) === 1) {
            $score++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $value) === 1) {
            $score++;
        }

        // Cap op 4 zodat een 4-segment-bar in de view consistent vult.
        return min($score, 4);
    }

    /**
     * NL-label bij de sterkte-score:
     *  0/1 → "Zwak"
     *  2   → "Matig"
     *  3   → "Sterk"
     *  4   → "Zeer sterk"
     *
     * Wordt door de view zowel als visueel hulplabel (sighted users) als
     * via `aria-valuetext` op de `role="meter"` (screenreaders) gebruikt.
     */
    public function getStrengthLabel(): string
    {
        return match ($this->getStrengthScore()) {
            0, 1 => 'Zwak',
            2 => 'Matig',
            3 => 'Sterk',
            4 => 'Zeer sterk',
            default => 'Zwak',
        };
    }

    /**
     * Voldoet het wachtwoord aan het volledige beleid?
     *
     * True iff ALLE vijf criteria gehaald zijn:
     *  - length ≥ 12
     *  - heeft lowercase
     *  - heeft uppercase
     *  - heeft digit
     *  - heeft symbol
     *
     * Zelfde check als {@see getStrengthScore()} maar dan zonder de cap
     * op 4 — de score-functie cappt namelijk de visuele bar, terwijl
     * deze policy-check elke afzonderlijke regel hard ingelopen wil
     * hebben. We rekenen het opnieuw uit zodat de twee functies
     * onafhankelijk verifieerbaar zijn (geen impliciete koppeling
     * "score === 4 ⇒ policy gehaald" waar een latere refactor over
     * kan struikelen).
     */
    public function getStrengthMeetsPolicy(): bool
    {
        $value = $this->password;

        if ($value === '') {
            return false;
        }

        return mb_strlen($value) >= 12
            && preg_match('/[a-z]/', $value) === 1
            && preg_match('/[A-Z]/', $value) === 1
            && preg_match('/[0-9]/', $value) === 1
            && preg_match('/[^A-Za-z0-9]/', $value) === 1;
    }

    /**
     * Hoofdactie van het formulier.
     *
     * Stappen:
     *  1. Valideer de form-velden volgens de Validate-attributen
     *     (required, min/max, same).
     *  2. Enforce de policy ook server-side: de regex-mix-check zit niet
     *     in een Laravel `Rule`, dus we doen 'm hier expliciet. Bij
     *     mismatch een NL-melding op `password` en stoppen we — geen
     *     service-call, geen redirect.
     *  3. Roep {@see PasswordResetService::resetPassword()} aan binnen
     *     een try/catch op `ValidationException`. De service gooit met
     *     keys `token` (resetlink ongeldig/verlopen) of `password`
     *     (zelfde wachtwoord als oude). We mappen deze naar het juiste
     *     veld; bij `token` zetten we daarnaast de publieke
     *     `$tokenError`-property zodat de view een prominente assertive
     *     live-region kan tonen.
     *  4. Op succes: redirect naar `/inloggen?reset=ok` zodat de
     *     login-flow de bevestigingsbanner kan tonen. We gebruiken
     *     `navigate: false` zodat een echte page-navigation gebeurt en
     *     de Livewire-state wordt afgebroken (anders zou het
     *     wachtwoord-veld eventueel achterblijven in een snapshot).
     *
     * Return-type `mixed`: Livewire accepteert zowel `null` (geen
     * redirect) als een redirect-response.
     */
    public function submit(PasswordResetService $passwordResetService): mixed
    {
        $this->validate();

        if (! $this->getStrengthMeetsPolicy()) {
            $this->addError(
                'password',
                'Wachtwoord moet minimaal 12 tekens lang zijn en hoofdletter, kleine letter, cijfer en symbool bevatten.'
            );

            return null;
        }

        try {
            $passwordResetService->resetPassword($this->token, $this->password);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            // Token-fout: prominent boven het formulier + inline op het
            // hidden token-veld zodat assertHasErrors(['token']) blijft
            // werken in tests.
            if (isset($errors['token'])) {
                $first = (string) (collect($errors['token'])->first()
                    ?? 'Resetlink is ongeldig of verlopen.');
                $this->tokenError = $first;
                $this->addError('token', $first);

                return null;
            }

            // Wachtwoord-niveau-fouten: bv. "Kies een nieuw wachtwoord
            // dat verschilt van het huidige wachtwoord."
            if (isset($errors['password'])) {
                $first = (string) (collect($errors['password'])->first()
                    ?? 'Wachtwoord is niet geldig.');
                $this->addError('password', $first);

                return null;
            }

            // Onbekende fout-key — toon op het token-veld als laatste
            // redmiddel; gebruiker kan dan de link opnieuw aanvragen.
            $first = (string) (collect($errors)->flatten()->first()
                ?? 'Wachtwoord opnieuw instellen is mislukt.');
            $this->tokenError = $first;
            $this->addError('token', $first);

            return null;
        }

        // Wis de wachtwoord-velden zodat ze niet in de Livewire-snapshot
        // achterblijven tijdens de redirect.
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->tokenError = null;

        return $this->redirect(url('/inloggen?reset=ok'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.password-reset-form');
    }
}
