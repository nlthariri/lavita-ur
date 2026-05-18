<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Services\AuthMfaService;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Livewire-component — `Auth\MfaSetupQr` (taak 9.3 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.1  → "Inlog + MFA + QR" met eerste-keer QR-setup-stap
 *      die `secret`, QR-image (data-URL via `endroid/qr-code`) en 8 recovery
 *      codes toont.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → NL-foutmeldingen.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Inlog + MFA + QR" → component `Auth\MfaSetupQr` op
 *      `/mfa-setup`.
 *  - design.md § Architecture > MFA-flow → setup-stap geeft
 *      `{ secret, qr_data_url, recovery_codes[8] }` terug.
 *  - tasks.md 9.3 → secret + QR-data-URL (via `endroid/qr-code`) + 8 recovery
 *      codes; kopieer-knoppen met `aria-live`; dependency
 *      `composer require endroid/qr-code`.
 *
 * Verantwoordelijkheid:
 *  - Wachtwoordbevestiging innemen (re-auth) en via
 *    {@see AuthMfaService::setupMfa()} een nieuw TOTP-secret + 8 recovery
 *    codes provisioneren.
 *  - De `provisioning_secret` (alleen lokaal/testing aanwezig in de
 *    service-response) coderen tot een `otpauth://totp/...`-URI en daar
 *    een PNG-QR-code van bouwen via `endroid/qr-code`. De PNG wordt als
 *    `data:image/png;base64,...` data-URI op `$qrDataUrl` gezet zodat de
 *    Blade-view 'm direct in een `<img>` kan tonen zonder extra round-trip.
 *  - Bij productie (waar de plaintext secret niet meer in de response zit)
 *    valt het component terug op een vriendelijke melding "QR-code is
 *    alleen tijdens setup beschikbaar"; de gebruiker kan dan alsnog de
 *    8 recovery codes opslaan als veiligheidsnet.
 *
 * Bewust niet (taak 9.5):
 *  - Geen route-registratie in `routes/web.php` — die volgt in task 9.5.
 *  - Geen MFA-verify-logica — die zit in `Auth\MfaVerifyForm` (taak 9.2).
 *
 * Auth-context:
 *  - In productie loopt deze flow achter `auth`-middleware; we nemen
 *    `auth()->id()` als bron van waarheid voor de user-id. Voor
 *    testbaarheid (en omdat het MFA-setup-pad expliciet stateless via
 *    een query-param óf `userId`-mount-arg te bereiken is, net als
 *    {@see MfaVerifyForm}) accepteren we óók een expliciete `userId`-arg
 *    op `mount()`. Wanneer die conflicteert met `auth()->id()`, prevaleert
 *    `auth()->id()` — een aanvaller mag nooit via een vreemd user_id de
 *    setup voor een andere account starten.
 */
#[Layout('layouts.app')]
#[Title('MFA instellen — LaVita Urenregistratie')]
class MfaSetupQr extends Component
{
    /**
     * Identifier van de gebruiker waarvoor MFA wordt ingericht.
     *
     * Resolver-volgorde:
     *  1. `auth()->id()` (productie-flow achter auth-middleware).
     *  2. expliciete `mount(int $userId)` (test-fixture).
     *  3. `request()->query('user_id')` (fallback bij sessieloze koppeling).
     */
    public int $userId = 0;

    /**
     * Wachtwoordbevestiging voor re-auth voordat het secret gegenereerd
     * wordt. Wordt server-side gecheckt door
     * {@see AuthMfaService::setupMfa()}; een verkeerde waarde levert een
     * inline NL-melding op het wachtwoord-veld.
     */
    #[Validate(
        rule: 'required|string|min:12',
        message: [
            'password.required' => 'Vul je huidige wachtwoord in om MFA in te stellen.',
            'password.string' => 'Wachtwoord is ongeldig.',
            'password.min' => 'Wachtwoord moet minimaal 12 tekens lang zijn.',
        ],
        attribute: ['password' => 'wachtwoord'],
        translate: false,
    )]
    public string $password = '';

    /**
     * Het plaintext provisioning secret (Base32) dat in de authenticator-app
     * gescand wordt. Na de eerste render wordt dit veld gewist uit de
     * Livewire-state om te voorkomen dat het in volgende snapshots
     * (DOM/network) zichtbaar blijft. De QR-code bevat het secret al
     * visueel — het hoeft niet als tekst in de state te persisteren.
     *
     * Security: het secret wordt alleen getoond in de eerste response
     * na setup. Bij een volgende Livewire-roundtrip is het al null.
     */
    public ?string $secret = null;

    /**
     * `data:image/png;base64,...`-URL met de QR-code. `null` zolang er nog
     * geen secret beschikbaar is (pre-setup óf productie-zonder-plain-secret).
     */
    public ?string $qrDataUrl = null;

    /**
     * 8 plaintext herstelcodes (lengte 10, uppercase alfanumeriek).
     * Worden eenmalig getoond — daarna alleen als gehashte rij in
     * `mfa_recovery_codes`.
     *
     * @var list<string>
     */
    public array $recoveryCodes = [];

    /**
     * Issuer en label uit de service-response. Worden alleen voor de
     * view-context gebruikt (otpauth-label is al ingebakken in
     * {@see $qrDataUrl}). Niet `Validate`-gemerkt — geen user input.
     */
    public ?string $issuer = null;

    public ?string $label = null;

    /**
     * Markeert dat de setup is afgerond en de view de "post-setup"-state
     * mag tonen (QR + secret + herstelcodes). Begint `false` zodat de
     * eerste render het wachtwoord-formulier laat zien.
     */
    public bool $setupComplete = false;

    /**
     * Mount-fase. Resolver-volgorde voor `userId`:
     *  - argument > `auth()->id()` > query-param.
     *
     * Beveiliging: zodra een geauthenticeerde user actief is, **overrulet**
     * `auth()->id()` elke andere bron. Anders zou een aanvaller via een
     * `?user_id=...` of een test-fixture-arg de setup voor een ander
     * account kunnen starten.
     */
    public function mount(?int $userId = null): void
    {
        $authId = auth()->id();

        if ($authId !== null) {
            // Productiepad: auth-middleware levert de user. We negeren
            // expliciet de query-param zodat parameter-tampering geen effect
            // heeft.
            $this->userId = (int) $authId;

            return;
        }

        // Test- of pre-auth-pad: vallen we terug op de expliciete arg, dan
        // de query-string, en pas op het allerlaatste op 0.
        $resolved = $userId
            ?? request()->query('user_id')
            ?? request()->input('user_id')
            ?? 0;

        $this->userId = (int) $resolved;
    }

    /**
     * Hoofdactie: provisioneert het secret en bouwt de QR-data-URL.
     *
     * Stappen:
     *  1. Valideer het wachtwoord-formulier-veld.
     *  2. Roep {@see AuthMfaService::setupMfa()} aan; deze bevestigt het
     *     wachtwoord opnieuw en gooit een ValidationException bij faal —
     *     we mappen die naar een inline NL-melding op `password`.
     *  3. Pak `provisioning_secret` (alleen aanwezig in lokaal/testing),
     *     `recovery_codes`, `issuer` en `label` uit de response. Recovery
     *     codes en issuer/label worden in alle omgevingen gevuld; alleen
     *     het plaintext secret en daarmee de QR ontbreken in productie.
     *  4. Bouw de `otpauth://totp/...`-URI met `digits=6&period=30&algorithm=SHA1`
     *     (TOTP-defaults, identiek aan {@see TotpService}).
     *  5. Render de QR via `endroid/qr-code` op 300x300 met margin 10 en
     *     ErrorCorrection M (medium) — voldoende robuust voor scannen op
     *     telefooncamera's én klein genoeg voor een mobiele viewport.
     *  6. Markeer `setupComplete = true` en wis het wachtwoord-veld zodat
     *     het niet bij de volgende Livewire-snapshot blijft staan.
     */
    public function submit(AuthMfaService $authMfaService): void
    {
        $this->validate();

        try {
            $result = $authMfaService->setupMfa(
                userId: $this->userId,
                passwordConfirmation: $this->password,
            );
        } catch (ValidationException $e) {
            // De service gooit "Wachtwoordbevestiging ongeldig." op
            // `password_confirmation`. We tonen 'm op `password` (waar de
            // input zelf staat) zodat screenreaders en sighted users de
            // melding op de juiste plek zien.
            $first = collect($e->errors())->flatten()->first()
                ?? 'MFA-setup mislukt.';
            $this->addError('password', (string) $first);

            return;
        } finally {
            // Wachtwoord nooit in de Livewire-state laten staan na een
            // submit — ongeacht succes of falen.
            $this->password = '';
        }

        $this->issuer = (string) ($result['issuer'] ?? '');
        $this->label = (string) ($result['label'] ?? '');
        $this->recoveryCodes = array_values(array_map(
            fn ($code): string => (string) $code,
            (array) ($result['recovery_codes'] ?? []),
        ));

        $plainSecret = $result['provisioning_secret'] ?? null;

        if (is_string($plainSecret) && $plainSecret !== '') {
            $this->secret = $plainSecret;
            $this->qrDataUrl = $this->buildQrDataUrl(
                secret: $plainSecret,
                issuer: $this->issuer,
                label: $this->label,
            );
        } else {
            // Productie-pad: het plaintext secret is nooit meer beschikbaar
            // na opslag (alleen via Crypt-encrypted in `mfa_secrets`). We
            // tonen daarom geen QR; de gebruiker krijgt wel de 8
            // herstelcodes te zien zodat hij in elk geval kan inloggen.
            $this->secret = null;
            $this->qrDataUrl = null;
        }

        $this->setupComplete = true;
    }

    /**
     * Bouwt de `otpauth://`-URI volgens de Google Authenticator key URI
     * specificatie en rendert deze als PNG-QR-code in een base64 data-URI.
     *
     * URI-vorm conform tasks.md 9.3:
     *   `otpauth://totp/{label}?secret={secret}&issuer={issuer}&digits=6
     *    &period=30&algorithm=SHA1`
     *
     * Implementatie-noot: zowel `label` als `issuer` worden URL-encoded
     * (rawurlencode) zodat spaties als `%20` worden geserialiseerd —
     * authenticator-apps verwachten percent-encoding, geen plus-sign.
     */
    private function buildQrDataUrl(string $secret, string $issuer, string $label): string
    {
        $otpauth = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&digits=6&period=30&algorithm=SHA1',
            rawurlencode($label),
            rawurlencode($secret),
            rawurlencode($issuer),
        );

        $qrCode = new QrCode(
            data: $otpauth,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 300,
            margin: 10,
        );

        $writer = new PngWriter;
        $result = $writer->write($qrCode);

        // Endroid v6 bouwt de data-URI zelf:
        //   data:<mime>;base64,<base64-encoded-png>
        return $result->getDataUri();
    }

    public function render(): View
    {
        return view('livewire.auth.mfa-setup-qr');
    }

    /**
     * Livewire lifecycle: na elke render wordt het plaintext secret
     * gewist uit de component-state. Dit zorgt ervoor dat het secret
     * alleen in de EERSTE response na setup zichtbaar is en niet in
     * volgende Livewire-snapshots (die in de DOM staan als JSON).
     * De QR-code (als data-URI) blijft beschikbaar — die bevat het
     * secret visueel maar niet als leesbare tekst in de state.
     */
    public function dehydrate(): void
    {
        // Wis het plaintext secret na rendering zodat het niet in
        // de volgende Livewire-snapshot terechtkomt.
        if ($this->setupComplete && $this->secret !== null) {
            $this->secret = null;
        }
    }
}
