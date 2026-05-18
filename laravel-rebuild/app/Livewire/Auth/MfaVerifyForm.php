<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\AuthSession;
use App\Services\AuthMfaService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Livewire-component — `Auth\MfaVerifyForm` (taak 9.2 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.1  → "Inlog + MFA + QR" op `/inloggen` met daarna
 *      een 6-cijferige TOTP-stap.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Inlog + MFA + QR" → component `Auth\MfaVerifyForm` op
 *      `/auth/mfa-verify`.
 *  - design.md § Architecture > MFA-flow → na succesvolle login redirect
 *      naar deze stap met `user_id` als query-parameter; bij geldige TOTP-
 *      code redirect naar `/dashboard`.
 *
 * Verantwoordelijkheid:
 *  - 6-cijferige TOTP-code OF 10-tekens-recovery-code innemen, valideren
 *    en via {@see AuthMfaService::verifyMfa()} verifiëren. Bij succes
 *    redirect naar `/dashboard`. Bij faal een NL-melding op het code-veld.
 *  - Throttle: maximaal 5 pogingen binnen 60 seconden per (user_id, IP);
 *    daarna een NL-melding "Te veel pogingen, probeer over een minuut
 *    opnieuw." Implementatie via Laravel's `RateLimiter` facade met de
 *    sleutel `mfa-verify:{user_id}:{ip}` zoals in tasks.md 9.2 beschreven.
 *
 * Bewust niet (taak 9.5):
 *  - Geen route-registratie in `routes/web.php` — die volgt in task 9.5.
 *
 * Geen sessie-state:
 *  - We houden de stap stateless door `user_id` via de query-string te
 *    krijgen, identiek aan {@see LoginForm::submit()} dat na inloggen
 *    redirect naar `/auth/mfa-verify?user_id=...`. Geen extra session-keys
 *    nodig — past bij design.md MFA-flow-diagram.
 */
#[Layout('layouts.app')]
#[Title('Verifieer MFA — LaVita Urenregistratie')]
class MfaVerifyForm extends Component
{
    /**
     * Identifier van de gebruiker die de TOTP-stap moet doorlopen.
     *
     * Gevuld in {@see mount()} uit de query-string (`?user_id=...`) of
     * route-parameter, conform de redirect die {@see LoginForm::submit()}
     * uitvoert na succesvolle e-mail/wachtwoord-stap.
     */
    public int $userId = 0;

    /**
     * 6-cijferige TOTP-code OF 10-tekens-recovery-code.
     *
     * - TOTP: exact 6 cijfers.
     * - Recovery: exact 10 alfanumeriek-uppercase-tekens (zie
     *   {@see AuthMfaService::generateRecoveryCodes()}).
     *
     * De regex weerspiegelt beide formaten in één expressie zoals tasks.md
     * 9.2 voorschrijft. We trimmen en uppercasen de invoer in {@see submit()}
     * vóór de service-aanroep, omdat gebruikers vaak met spaties of in
     * lowercase intypen — de validatie zelf blijft echter strict op de
     * uppercase-vorm zodat geen ambigue input doorslipt.
     *
     * Implementatie-noot: Laravel's pipe-rule-syntax knipt op `|`, dus de
     * regex (die zelf een `|` bevat) moet als array-rule worden geleverd
     * — anders splitst het framework de regex op de OR-pipe en krijg je
     * een `preg_match(): No ending delimiter '/'`-fout.
     */
    #[Validate(
        rule: ['required', 'string', 'regex:/^[0-9]{6}$|^[A-Z0-9]{10}$/'],
        message: [
            'code.required' => 'Vul je MFA-code in.',
            'code.string' => 'Code moet een tekstwaarde zijn.',
            'code.regex' => 'Voer een geldige 6-cijferige code of 10-tekens herstelcode in.',
        ],
        attribute: ['code' => 'MFA-code'],
        translate: false,
    )]
    public string $code = '';

    /**
     * Verzamelde throttle-melding (NL) wanneer de RateLimiter geblokkeerd
     * heeft. We bewaren 'm in een aparte property zodat de view 'm prominent
     * boven het formulier kan tonen, los van de `code`-veldfout.
     */
    public ?string $throttleMessage = null;

    /**
     * Aantal seconden tot de throttle weer opheft. Wordt gebruikt door de
     * view voor `aria-live`-aankondigingen en is het output-veld waar de
     * test op kan vergelijken.
     */
    public ?int $throttleAvailableInSeconds = null;

    /**
     * Constanten voor het brute-force-throttle-beleid.
     *
     * Tasks.md 9.2: "5 failed attempts within 60 seconds". We hergebruiken
     * dezelfde getallen als de API-laag {@see AppServiceProvider}-`for('mfa')`,
     * zodat web- en API-laag consistent zijn.
     */
    private const THROTTLE_MAX_ATTEMPTS = 5;

    private const THROTTLE_DECAY_SECONDS = 60;

    /**
     * Mount-fase. Pakt `user_id` uit de query-string of de route-parameter.
     *
     * - Tasks.md 9.2: `mount(int $userId)` populeert `$userId` uit
     *   `request()->query('user_id')` of route-param.
     * - Bewuste keuze om `int $userId` als argument toe te staan: Livewire
     *   geeft elke route-parameter automatisch door (route-binding via
     *   `Route::get('/auth/mfa-verify/{userId?}', ...)`); zo is het
     *   component zowel via query-param als via route-param te bereiken.
     */
    public function mount(?int $userId = null): void
    {
        // Resolve volgorde: sessie (V-01 fix) → methode-arg → query-string
        // → request input → route-parameter → 0.
        // De sessie-benadering voorkomt dat user IDs in URLs lekken.
        $sessionUserId = session()->pull('pending_mfa_user_id');

        $resolved = $sessionUserId
            ?? $userId
            ?? request()->query('user_id')
            ?? request()->input('user_id')
            ?? request()->route('userId')
            ?? 0;

        $this->userId = (int) $resolved;
    }

    /**
     * Hoofdactie: verifieert de ingevoerde code via {@see AuthMfaService}.
     *
     * Stappen:
     *  1. Throttle-check vóór alles: als de RateLimiter boven de limiet
     *     zit, slaan we validatie en service-call over en tonen we de
     *     NL-melding. We pinnen de sleutel op `(user_id, ip)` zodat een
     *     aanvaller niet kan switchen tussen IPs of accounts.
     *  2. Valideer het code-formaat (regex).
     *  3. Verifieer dat er een actieve, niet-verlopen sessie bestaat voor
     *     deze user_id — dit voorkomt dat een aanvaller direct naar
     *     `/auth/mfa-verify?user_id=X` navigeert zonder eerst credentials
     *     te hebben ingevoerd.
     *  4. Roep {@see AuthMfaService::verifyMfa()} aan. De service gooit
     *     geen exception bij een verkeerde code maar geeft `false` terug;
     *     wél een ValidationException als MFA niet geconfigureerd is voor
     *     deze user_id (`user_id` => "MFA is niet geconfigureerd voor
     *     deze gebruiker.").
     *  5. Bij `false` → registreer een hit op de RateLimiter, voeg een
     *     inline error toe op `code`, en als de limiter na deze hit boven
     *     de drempel komt, blokkeer met de throttle-melding.
     *  6. Bij `true` → wis de RateLimiter-teller en redirect naar
     *     `/dashboard`.
     */
    public function submit(AuthMfaService $authMfaService): mixed
    {
        $rateKey = $this->rateLimiterKey();

        // Stap 1 — throttle vóór validatie zodat brute-forcers niet via
        // ongeldige codes (die nooit een hit zouden registreren) door de
        // limiet heen kunnen rammen.
        if (RateLimiter::tooManyAttempts($rateKey, self::THROTTLE_MAX_ATTEMPTS)) {
            $this->setThrottleBlocked($rateKey);

            return null;
        }

        // Stap 2 — valideer het code-formaat. Faalt validatie? Geen hit
        // op de limiter; alleen mislukte verifications tellen mee.
        $this->validate();

        // Stap 3 — verifieer dat er een recente, actieve sessie bestaat
        // voor deze user_id. Dit voorkomt dat een aanvaller zonder
        // credentials direct MFA-codes kan brute-forcen.
        if ($this->userId <= 0 || ! $this->hasRecentActiveSession($this->userId)) {
            $this->addError('code', 'Ongeldige sessie. Log opnieuw in.');

            return null;
        }

        $normalizedCode = strtoupper(trim($this->code));

        try {
            $verified = $authMfaService->verifyMfa($this->userId, $normalizedCode);
        } catch (ValidationException $e) {
            // Bv. "MFA is niet geconfigureerd voor deze gebruiker." —
            // toon op het code-veld zodat de gebruiker begrijpt dat hij
            // (waarschijnlijk) eerst MFA moet opzetten via taak 9.3.
            $first = collect($e->errors())->flatten()->first()
                ?? 'MFA-verificatie mislukt.';
            $this->addError('code', (string) $first);

            return null;
        }

        if (! $verified) {
            // Stap 5 — verkeerde code: tel deze poging mee.
            RateLimiter::hit($rateKey, self::THROTTLE_DECAY_SECONDS);

            // Inline foutmelding in NL.
            $this->addError('code', 'De MFA-code klopt niet. Controleer je app of herstelcode en probeer opnieuw.');

            // Direct na de hit nog eens de limiter-status checken zodat
            // een gebruiker die met de 5e poging over de drempel raakt,
            // meteen de throttle-feedback ziet i.p.v. pas bij de volgende
            // submit.
            if (RateLimiter::tooManyAttempts($rateKey, self::THROTTLE_MAX_ATTEMPTS)) {
                $this->setThrottleBlocked($rateKey);
            }

            return null;
        }

        // Stap 6 — succes. Limiter wissen zodat een eventuele volgende
        // sessie niet onnodig gehinderd wordt door oude pogingen.
        RateLimiter::clear($rateKey);

        // Resetten van het code-veld zodat het niet door volgende renders
        // teruggepost wordt (en niet zichtbaar blijft in de UI tijdens de
        // redirect).
        $this->code = '';
        $this->throttleMessage = null;
        $this->throttleAvailableInSeconds = null;

        return $this->redirect(url('/dashboard'), navigate: false);
    }

    /**
     * Controleer of er een actieve, niet-verlopen en niet-ingetrokken
     * sessie bestaat voor de opgegeven user_id. Dit bindt de MFA-verify
     * stap aan een recente succesvolle login en voorkomt dat een aanvaller
     * zonder credentials direct MFA-codes kan brute-forcen.
     *
     * De sessie moet:
     * - Niet ingetrokken zijn (revoked_at IS NULL)
     * - Niet verlopen zijn (expires_at > now())
     *
     * We controleren niet op `created_at` omdat de sessie-levensduur
     * (12 uur) al voldoende beperkt is via `expires_at`. Een aanvaller
     * zonder credentials kan geen sessie aanmaken, dus het bestaan van
     * een actieve sessie is voldoende bewijs dat er recent een
     * succesvolle login heeft plaatsgevonden.
     */
    private function hasRecentActiveSession(int $userId): bool
    {
        return AuthSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Bouw de RateLimiter-sleutel zoals tasks.md 9.2 voorschrijft:
     * `mfa-verify:{user_id}:{ip}`.
     *
     * Zonder user_id (0) of zonder IP gebruiken we een fallback-string
     * `unknown` zodat een aanvaller niet via een leeg veld de limiter
     * kan ontwijken.
     */
    private function rateLimiterKey(): string
    {
        $ip = request()->ip() ?: 'unknown';

        return sprintf('mfa-verify:%d:%s', $this->userId, $ip);
    }

    /**
     * Markeer de UI-state als "geblokkeerd door throttle" en zet de
     * publieke properties die de view rendert.
     *
     * We rapporteren `availableIn` als seconden (1..60) en bouwen een
     * Nederlandstalige melding die exact voldoet aan tasks.md 9.2:
     * "Te veel pogingen, probeer over een minuut opnieuw."
     */
    private function setThrottleBlocked(string $rateKey): void
    {
        $availableIn = RateLimiter::availableIn($rateKey);

        $this->throttleAvailableInSeconds = $availableIn > 0 ? $availableIn : self::THROTTLE_DECAY_SECONDS;
        $this->throttleMessage = 'Te veel pogingen, probeer over een minuut opnieuw.';

        // Ook op het veld zodat assertHasErrors(['code']) blijft werken
        // wanneer de gebruiker een 6e poging doet.
        $this->addError('code', $this->throttleMessage);
    }

    public function render(): View
    {
        return view('livewire.auth.mfa-verify-form');
    }
}
