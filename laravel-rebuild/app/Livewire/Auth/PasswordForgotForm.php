<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Services\PasswordResetService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * Livewire-component — `Auth\PasswordForgotForm` (taak 9.4 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.11 → "Wachtwoord vergeten/reset" met tokenvalidatie.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → Foutmeldingen en bevestigingen in het Nederlands.
 *  - requirements.md NFR-10 → UI/foutmeldingen in het Nederlands.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Wachtwoord vergeten/reset" → component
 *      `Auth\PasswordForgotForm` op `/wachtwoord-vergeten`.
 *  - tasks.md 9.4 → Sterkte-indicator zit in {@see PasswordResetForm};
 *      dit component vraagt enkel het e-mailadres en triggert de
 *      generieke timing-safe respons.
 *
 * Verantwoordelijkheid:
 *  - E-mailadres innemen, valideren (`required|email|max:254`) en via
 *    {@see PasswordResetService::requestReset()} een resetlink-mail
 *    laten dispatchen. De service is timing-safe: bij een onbekend
 *    of geblokkeerd adres gebeurt er stilletjes niets, en wij — net
 *    als de bestaande HTTP-controller {@see PasswordResetController::postRequest()}
 *    — tonen ALTIJD dezelfde generieke NL-bevestiging zodat een aanvaller
 *    geen accountenumeratie kan doen.
 *  - De ingevulde `$email` veld wordt na submit gewist zodat hij niet in
 *    de Livewire-snapshot blijft staan en niet onbedoeld terug gerenderd
 *    wordt na een F5-refresh.
 *
 * Bewust niet (taak 9.5 / 9.4):
 *  - Geen route-registratie in `routes/web.php` — die volgt in task 9.5.
 *  - Geen wachtwoord-set-logica — die zit in {@see PasswordResetForm}.
 *  - Geen veld-niveau foutmelding bij onbekend adres — generieke melding
 *    is intentioneel (security-best-practice, identiek aan de bestaande
 *    JSON-controller).
 */
#[Layout('layouts.app')]
#[Title('Wachtwoord vergeten — LaVita Urenregistratie')]
final class PasswordForgotForm extends Component
{
    /**
     * E-mailadres van de gebruiker die een resetlink wil aanvragen.
     *
     * Validatie-messages staan in NL conform NFR-10 / req 6.14. We
     * gebruiken dezelfde `Validate`-stijl als {@see LoginForm} — expliciete
     * NL-messages per failure-code en `translate: false` zodat Laravel's
     * vertaal-laag de tekst niet alsnog door een (engels) lang-bestand
     * jaagt.
     *
     * `max:254` matcht de RFC 5321 5.1.2-grens voor het complete e-mail
     * forward-path; identiek aan
     * {@see PasswordResetController::postRequest()}.
     */
    #[Validate(
        rule: 'required|email|max:254',
        message: [
            'email.required' => 'Vul je e-mailadres in.',
            'email.email' => 'Voer een geldig e-mailadres in.',
            'email.max' => 'E-mailadres is te lang (maximaal 254 tekens).',
        ],
        attribute: ['email' => 'e-mailadres'],
        translate: false,
    )]
    public string $email = '';

    /**
     * Generieke NL-bevestigingsmelding die na elke submit verschijnt —
     * ongeacht of het ingevoerde adres bestaat. De view rendert deze
     * via een `role="status" aria-live="polite"`-region zodat
     * screenreaders 'm aankondigen.
     *
     * Blijft `null` zolang er nog geen submit is uitgevoerd; dan rendert
     * de view alleen het formulier.
     */
    public ?string $confirmation = null;

    /**
     * Maximaal aantal reset-aanvragen per 10 minuten per IP.
     * Voorkomt dat een aanvaller de outbox volspamt met reset-mails.
     */
    private const RESET_MAX_ATTEMPTS = 3;

    private const RESET_DECAY_SECONDS = 600;

    /**
     * Hoofdactie van het formulier.
     *
     * Stappen:
     *  1. Throttle-check: maximaal 3 aanvragen per 10 minuten per IP.
     *  2. Valideer de form-velden volgens de Validate-attributen.
     *  3. Roep {@see PasswordResetService::requestReset()} aan, omwikkeld
     *     in een try/catch op `\Throwable` zodat een database-fout, een
     *     outbox-fout of een onverwachte exception NOOIT lekt of er een
     *     account met dat e-mailadres bestaat. Dit is dezelfde generieke-
     *     respons-strategie als
     *     {@see PasswordResetController::postRequest()}.
     *  4. Zet de NL-bevestigingsmelding op `$confirmation` en wis
     *     `$email` zodat de Livewire-snapshot het adres niet onnodig
     *     blijft droppen.
     *  5. Géén `addError` op `email` bij onbekend adres — generieke
     *     bevestiging is intentioneel om accountenumeratie te voorkomen
     *     (req 6.14, security-best-practice, conform NFR-7-spirit voor
     *     transport-side timing).
     */
    public function submit(PasswordResetService $passwordResetService): void
    {
        // Stap 1 — web-layer throttle (spam-bescherming)
        $rateKey = 'password-forgot-web:'.(request()->ip() ?: 'unknown');

        if (RateLimiter::tooManyAttempts($rateKey, self::RESET_MAX_ATTEMPTS)) {
            // Toon dezelfde generieke bevestiging — geen hint dat we
            // throttlen (voorkomt timing-based enumeration).
            $this->confirmation = 'Als dit e-mailadres bestaat, ontvang je een resetlink.';
            $this->email = '';

            return;
        }

        $this->validate();

        RateLimiter::hit($rateKey, self::RESET_DECAY_SECONDS);

        try {
            $passwordResetService->requestReset($this->email);
        } catch (Throwable) {
            // Bewust generiek — we rapporteren géén foutmelding naar de
            // gebruiker, exact zoals PasswordResetController::postRequest
            // doet bij elke `\Throwable` uit de service-laag.
        }

        // Generieke NL-bevestiging — letterlijk dezelfde tekst als
        // PasswordResetController::GENERIC_MSG zodat web- en API-laag
        // consistent zijn (req 6.14, NFR-10).
        $this->confirmation = 'Als dit e-mailadres bestaat, ontvang je een resetlink.';
        $this->email = '';
    }

    public function render(): View
    {
        return view('livewire.auth.password-forgot-form');
    }
}
