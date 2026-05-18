<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use App\Livewire\Auth\LoginForm;
use App\Livewire\Auth\MfaSetupQr;
use App\Livewire\Auth\MfaVerifyForm;
use App\Livewire\Auth\PasswordForgotForm;
use App\Livewire\Auth\PasswordResetForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Livewire\Auth\LoginFormTest;
use Tests\TestCase;

/**
 * Feature-tests voor de auth-web-routes — taak 9.5 spec lavita-urenregistratie.
 *
 * Bron:
 *  - tasks.md 9.5 → "Registreer web-routes in routes/web.php voor alle
 *      auth-schermen (CSRF + sessie)."
 *  - requirements.md 6.1  → scherm "Inlog + MFA + QR" op `/inloggen`,
 *      `/auth/mfa-verify`, `/mfa-setup`.
 *  - requirements.md 6.11 → scherm "Wachtwoord vergeten/reset" op
 *      `/wachtwoord-vergeten` en `/wachtwoord-reset`.
 *
 * We dekken vier soorten zekerheid:
 *  1. Resolutie:    elke path treft de correcte Livewire-component-class
 *                   en levert HTTP 200.
 *  2. Naamgeving:   elke route heeft een named alias en `route(...)` mapt
 *                   naar dezelfde URL die in de spec is afgesproken.
 *  3. Middleware:   elke route zit in de `web`-middleware-groep zodat
 *                   CSRF, session, cookies en share-errors actief zijn.
 *  4. Token-flow:   `/wachtwoord-reset?token=...` accepteert de query-
 *                   parameter en de token komt door tot in het Livewire-
 *                   component. (Het Livewire-component-niveau zelf is al
 *                   uitvoerig getest in PasswordResetFormTest; deze test
 *                   bevestigt de glue-laag op route-niveau.)
 *
 * Conventies:
 *  - PHPUnit-12 class-style (geen Pest `it()`), parity met
 *    {@see LoginFormTest}.
 *  - `RefreshDatabase` voor het geval een Livewire-component tijdens
 *    render naar de database reikt (config-lookup, audit-log, etc.).
 *  - Geen mocks of fakes — we testen de echte route → middleware →
 *    component-pipeline.
 */
final class AuthWebRoutesTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | 1. Route-resolutie — elke path levert de juiste component
    |--------------------------------------------------------------------------
    */

    public function test_inloggen_route_resolves_to_login_form_component(): void
    {
        $this->get('/inloggen')
            ->assertOk()
            ->assertSeeLivewire(LoginForm::class);
    }

    public function test_mfa_verify_route_resolves_to_mfa_verify_form_component(): void
    {
        $this->get('/auth/mfa-verify')
            ->assertOk()
            ->assertSeeLivewire(MfaVerifyForm::class);
    }

    public function test_mfa_setup_route_resolves_to_mfa_setup_qr_component(): void
    {
        // `MfaSetupQr` accepteert een GET zonder geauthenticeerde gebruiker:
        // het component heeft een fallback in mount() die uiteindelijk
        // userId=0 zet wanneer geen auth/query/arg aanwezig is. Het
        // wachtwoord-formulier rendert dan zonder fout (zie
        // MfaSetupQrTest::test_render_returns_200_*). Wij valideren hier
        // alleen dat de route-glue klopt — het mount-gedrag is op
        // component-niveau gedekt.
        $this->get('/mfa-setup')
            ->assertOk()
            ->assertSeeLivewire(MfaSetupQr::class);
    }

    public function test_wachtwoord_vergeten_route_resolves_to_password_forgot_form_component(): void
    {
        $this->get('/wachtwoord-vergeten')
            ->assertOk()
            ->assertSeeLivewire(PasswordForgotForm::class);
    }

    public function test_wachtwoord_reset_route_resolves_to_password_reset_form_component(): void
    {
        $this->get('/wachtwoord-reset')
            ->assertOk()
            ->assertSeeLivewire(PasswordResetForm::class);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Named-route-aliases — `route(name)` mapt naar het pad uit de spec
    |--------------------------------------------------------------------------
    |
    | Voor de spec is het cruciaal dat alle URL-generatie in views/mails
    | via `route('login')` enz. loopt zodat een eventuele her-mapping van
    | URL's centraal aanpasbaar blijft. We bevestigen hier:
    |   - `Route::has(name)` → true voor elke gewenste alias.
    |   - `route(name)` produceert exact de URL uit de spec
    |     (vergelijking via `url(/...)` om scheme + host weg te abstraheren).
    */

    public function test_each_auth_route_has_its_named_alias(): void
    {
        $expectedNames = [
            'login' => '/inloggen',
            'mfa.verify' => '/auth/mfa-verify',
            'mfa.setup' => '/mfa-setup',
            'password.forgot' => '/wachtwoord-vergeten',
            'password.reset' => '/wachtwoord-reset',
        ];

        foreach ($expectedNames as $name => $expectedPath) {
            $this->assertTrue(
                Route::has($name),
                "Verwachtte een named route '{$name}' maar die is niet geregistreerd."
            );

            $this->assertSame(
                url($expectedPath),
                route($name),
                "route('{$name}') moet '{$expectedPath}' opleveren."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Middleware-groep — `web` is verplicht voor CSRF + sessie
    |--------------------------------------------------------------------------
    |
    | De `web`-groep brengt cookies, sessie, share-errors-from-session,
    | CSRF-validatie en SubstituteBindings mee. Taak 9.5 vereist deze
    | expliciet ("CSRF + sessie"). We checken dat `gatherMiddleware()`
    | op de geregistreerde Route-instance de expliciete groepsnaam `web`
    | bevat, exact zoals onze `Route::middleware(['web'])->group(...)`
    | in `routes/web.php` voorschrijft.
    */

    public function test_auth_routes_are_in_web_middleware_group(): void
    {
        $namedRoutes = ['login', 'mfa.verify', 'mfa.setup', 'password.forgot', 'password.reset'];

        foreach ($namedRoutes as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull(
                $route,
                "Verwachtte een geregistreerde Route met naam '{$name}'."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'web',
                $middleware,
                "Route '{$name}' moet in de 'web' middleware-groep zitten ".
                '(CSRF + sessie); aanwezige middleware: '.implode(', ', $middleware).'.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Token-flow — `/wachtwoord-reset?token=…` propagatie
    |--------------------------------------------------------------------------
    |
    | Onderaan tasks.md 9.5: "test_password_reset_route_accepts_token_query_parameter
    | — $this->get('/wachtwoord-reset?token=abc123')->assertOk(); en zorg dat
    | de gerenderde HTML de token bevat in een vorm die de component kan
    | oppakken." We hergebruiken de input-strategie uit
    | PasswordResetForm::mount() (query-string-resolver) en bevestigen op
    | route-niveau dat de glue klopt — niet de component-interne logica
    | (die al gedekt is door PasswordResetFormTest).
    */

    public function test_password_reset_route_accepts_token_query_parameter(): void
    {
        $response = $this->get('/wachtwoord-reset?token=abc123');

        $response->assertOk()
            ->assertSeeLivewire(PasswordResetForm::class);

        // De Livewire-snapshot in de HTML serializeert de publieke
        // properties van de component. Het `token`-veld wordt in mount()
        // uit `request()->query('token')` opgepakt zodra geen explicite
        // arg en geen input-fallback aanwezig is. We bevestigen dat
        // 'abc123' ergens in de respons-body voorkomt — Livewire zet
        // het in zowel de wire:snapshot als (afhankelijk van de view)
        // in een hidden input. De `escape: false`-vlag laat HTML-
        // entities ongemoeid zodat we ook tegen JSON-encoded snapshots
        // matchen.
        $response->assertSee('abc123', escape: false);
    }
}
