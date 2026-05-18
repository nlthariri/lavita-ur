<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Services\AuthMfaService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Livewire-component — `Auth\LoginForm` (taak 9.1 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.1  → "Inlog + MFA + QR" op `/inloggen` met e-mail+wachtwoord-stap.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit `design.md`.
 *  - requirements.md 6.14 → Foutmeldingen en bevestigingen in het Nederlands.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Inlog + MFA + QR" → component `Auth\LoginForm` op `/inloggen`.
 *  - design.md § Architecture > MFA-flow → na succesvolle login redirect naar
 *      MFA-verify-stap met `user_id`.
 *
 * Verantwoordelijkheid:
 *  - E-mail + wachtwoord innemen, valideren (`required|email` resp.
 *    `required|string|min:12`), via {@see AuthMfaService::login()} authenticeren
 *    en bij succes doorsturen naar `/auth/mfa-verify?user_id=...`.
 *  - Bij authenticatiefout een NL-melding op het email-veld zetten via Livewire's
 *    validation-bag, zodat de view inline-rendert in `<x-ui.text-input :error="…">`.
 *
 * Bewust niet (taak 9.5 / 9.2):
 *  - Geen route-registratie in `routes/web.php` — die volgt in task 9.5.
 *  - Geen MFA-verify-logica — die zit in `Auth\MfaVerifyForm` (taak 9.2).
 *  - Geen "remember me" of throttle — throttle is afgehandeld in
 *    `FailClosedThrottle` middleware op de API-laag; web-throttle volgt later.
 */
#[Layout('layouts.app')]
#[Title('Inloggen — LaVita Urenregistratie')]
class LoginForm extends Component
{
    /**
     * E-mailadres van de gebruiker.
     *
     * Validatie-messages staan in NL conform NFR-10 / req 6.14.
     * Sleutels volgen Laravel's `attribute.rule`-conventie zoals doorgereikt
     * door Livewire's `BaseValidate::boot()` aan `addMessagesFromOutside()`.
     */
    #[Validate(
        rule: 'required|email',
        message: [
            'email.required' => 'Vul je e-mailadres in.',
            'email.email' => 'Voer een geldig e-mailadres in.',
        ],
        attribute: ['email' => 'e-mailadres'],
        translate: false,
    )]
    public string $email = '';

    /**
     * Wachtwoord van de gebruiker. Minimumlengte komt uit het wachtwoord-beleid
     * van de applicatie (zie req 6.11 — min 12 tekens, mix hoofd/klein/cijfer/symbool).
     * De min-12-check op login dient als clientside-pre-check; de server valideert
     * de credentials zelf via {@see AuthMfaService::login()}.
     */
    #[Validate(
        rule: 'required|string|min:12',
        message: [
            'password.required' => 'Vul je wachtwoord in.',
            'password.string' => 'Wachtwoord is ongeldig.',
            'password.min' => 'Wachtwoord moet minimaal 12 tekens lang zijn.',
        ],
        attribute: ['password' => 'wachtwoord'],
        translate: false,
    )]
    public string $password = '';

    /**
     * Maximaal aantal login-pogingen per minuut per IP.
     * Identiek aan de API-laag rate limiter in AppServiceProvider.
     */
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    /**
     * Hoofdactie van het formulier.
     *
     * Stappen:
     *  1. Throttle-check: maximaal 5 pogingen per minuut per IP.
     *  2. Valideer de form-velden volgens de Validate-attributen.
     *  3. Roep {@see AuthMfaService::login()} aan met IP en user-agent uit het
     *     Livewire-request (server-side, dus niet beïnvloedbaar door client).
     *  4. Bij ValidationException uit de service (ongeldige credentials of
     *     gedeactiveerd account) → de Nederlandstalige melding wordt op `email`
     *     gezet zodat hij inline rendert in de `<x-ui.text-input>` van het
     *     emailveld; we gebruiken bewust **niet** `password` om geen hint te
     *     geven welk van de twee fout was.
     *  5. Bij succes redirect naar `/auth/mfa-verify?user_id=...` (taak 9.2).
     *     We geven user_id als query-parameter mee zodat de MFA-component
     *     stateless geactiveerd kan worden zonder sessieafhankelijkheid.
     */
    public function submit(AuthMfaService $authMfaService): mixed
    {
        // Stap 1 — web-layer throttle (brute-force bescherming)
        $rateKey = 'login-web:'.(request()->ip() ?: 'unknown');

        if (RateLimiter::tooManyAttempts($rateKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $this->addError('email', "Te veel inlogpogingen. Probeer het over {$seconds} seconden opnieuw.");

            return null;
        }

        $this->validate();

        try {
            $result = $authMfaService->login(
                email: $this->email,
                password: $this->password,
                ipAddress: request()->ip(),
                userAgent: (string) request()->userAgent(),
            );
        } catch (ValidationException $e) {
            // Registreer een mislukte poging op de rate limiter
            RateLimiter::hit($rateKey, self::LOGIN_DECAY_SECONDS);

            // Service-side foutmeldingen kunnen op `email` of `password` slaan.
            // We mappen alle keys naar `email` voor uniforme UX en om geen
            // informatie te lekken over welk veld faalde (security-best-practice).
            $messages = $e->errors();
            $first = collect($messages)->flatten()->first() ?? 'Ongeldige inloggegevens.';

            $this->addError('email', (string) $first);

            return null;
        }

        // Succesvolle login: wis de rate limiter
        RateLimiter::clear($rateKey);

        // Wachtwoord direct uit de component-state schrappen — het hoort niet
        // bij het Livewire-payload-stateload van de volgende request.
        $this->password = '';

        $userId = (int) ($result['user_id'] ?? 0);

        // Sla de pending MFA user_id op in de sessie i.p.v. de URL.
        // Dit voorkomt dat interne user IDs lekken via browser-history,
        // server-logs, referrer-headers en proxy-logs (V-01 fix).
        session()->put('pending_mfa_user_id', $userId);

        return $this->redirect(
            url('/auth/mfa-verify'),
            navigate: false,
        );
    }

    public function render(): View
    {
        return view('livewire.auth.login-form');
    }
}
