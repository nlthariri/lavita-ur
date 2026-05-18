<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Auth;

use App\Livewire\Auth\MfaVerifyForm;
use App\Models\AuthSession;
use App\Models\MfaSecret;
use App\Models\User;
use App\Services\AuthMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke-tests voor Livewire-component `Auth\MfaVerifyForm` (taak 9.2).
 *
 * Bron:
 *  - tasks.md 9.2 (lavita-urenregistratie):
 *      - Render returns 200 + sees NL labels.
 *      - Invalid 4-digit code triggers validation error.
 *      - Wrong 6-digit code adds inline error.
 *      - Valid TOTP code redirects to `/dashboard`.
 *      - Use {@see AuthMfaService::codeForTesting()} (zoals
 *        AuthModuleContractTest doet).
 */
final class MfaVerifyFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper — provisioneert een MFA-secret voor een testgebruiker en
     * geeft het _plain_ (Base32) provisioning-secret terug zodat de
     * tests via {@see AuthMfaService::codeForTesting()} een geldige
     * TOTP kunnen genereren.
     *
     * Maakt ook een actieve AuthSession aan zodat de sessie-binding-
     * check in MfaVerifyForm::submit() slaagt (de check voorkomt dat
     * een aanvaller zonder credentials direct MFA-codes kan brute-forcen).
     *
     * Hergebruikt de service `setupMfa`-flow zodat de encrypted secret
     * en bijbehorende recovery codes consistent zijn met productie.
     */
    private function provisionMfaSecret(User $user): string
    {
        $service = app(AuthMfaService::class);

        $setup = $service->setupMfa($user->id, 'LangWachtwoord123');

        // Maak een actieve sessie aan zodat de sessie-binding-check slaagt
        AuthSession::create([
            'user_id' => $user->id,
            'session_token_hash' => hash('sha256', Str::random(64)),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestSuite/1.0',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        // setupMfa retourneert in test-omgeving het plaintext secret zelf.
        return (string) $setup['provisioning_secret'];
    }

    /**
     * Wis de RateLimiter-bucket vóór elke test om kruistest-leakage te
     * voorkomen — de testsuite gebruikt de `array`-cache-driver, die binnen
     * één PHP-proces (vendor/bin/pest) state vasthoudt over tests heen.
     * `Cache::flush()` schoonmaakt _alle_ buckets, inclusief die van de
     * RateLimiter (die intern op `Cache::store()` zit).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_render_returns_200_and_includes_nl_labels(): void
    {
        Livewire::test(MfaVerifyForm::class, ['userId' => 1])
            ->assertStatus(200)
            ->assertSee('Verifieer met MFA')
            ->assertSee('MFA-code')
            ->assertSee('Verifiëren');
    }

    public function test_mount_resolves_user_id_from_query_string_when_arg_missing(): void
    {
        // Simuleer een query-string-aanvraag op `/auth/mfa-verify?user_id=42`.
        // Livewire's manager-API `withQueryParams()` zet de query-string-bag
        // op de virtuele test-request die `Testable::create()` afvuurt; die
        // bag wordt vervolgens als `$_GET` op de request gezet, zodat
        // `request()->query('user_id')` binnen `mount()` 42 oplevert.
        // Dit dekt expliciet de fallback-tak die tasks.md 9.2 voorschrijft:
        // "request()->query('user_id') of route param".
        Livewire::withQueryParams(['user_id' => 42])
            ->test(MfaVerifyForm::class)
            ->assertSet('userId', 42);
    }

    public function test_invalid_4_digit_code_triggers_validation_error(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-4digit@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);
        $this->provisionMfaSecret($user);

        Livewire::test(MfaVerifyForm::class, ['userId' => $user->id])
            ->set('code', '1234')
            ->call('submit')
            ->assertHasErrors(['code' => 'regex']);
    }

    public function test_empty_code_triggers_required_validation_error(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-empty@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);
        $this->provisionMfaSecret($user);

        Livewire::test(MfaVerifyForm::class, ['userId' => $user->id])
            ->set('code', '')
            ->call('submit')
            ->assertHasErrors(['code' => 'required']);
    }

    public function test_wrong_6_digit_code_adds_inline_error(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-wrong@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);
        $this->provisionMfaSecret($user);

        // 000000 zal vrijwel zeker NIET de huidige TOTP-window matchen.
        // We pakken een code die zeker fout is door de echte ene 1-op-1
        // te negeren en `'000000'` te gebruiken; mocht de generator toevallig
        // 000000 produceren (kans 1/1.000.000) dan faalt deze test fair-and-
        // square — acceptabel voor smoke-testing.
        Livewire::test(MfaVerifyForm::class, ['userId' => $user->id])
            ->set('code', '000000')
            ->call('submit')
            ->assertHasErrors(['code'])
            ->assertSee('De MFA-code klopt niet');

        // Verifieer dat de teller daadwerkelijk een hit heeft geregistreerd.
        $this->assertSame(
            1,
            RateLimiter::attempts('mfa-verify:'.$user->id.':127.0.0.1'),
            'Een verkeerde TOTP-code moet de RateLimiter-teller met 1 verhogen.'
        );
    }

    public function test_valid_totp_code_redirects_to_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-valid@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $secret = $this->provisionMfaSecret($user);
        $code = app(AuthMfaService::class)->codeForTesting($secret);

        Livewire::test(MfaVerifyForm::class, ['userId' => $user->id])
            ->set('code', $code)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(url('/dashboard'));

        // De verified_at-tijdstempel moet zijn gezet in de DB.
        $this->assertNotNull(
            MfaSecret::query()->where('user_id', $user->id)->value('verified_at')
        );
    }

    public function test_throttle_blocks_after_five_failed_attempts_with_nl_message(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-throttle@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);
        $this->provisionMfaSecret($user);

        $component = Livewire::test(MfaVerifyForm::class, ['userId' => $user->id]);

        // Vijf verkeerde pogingen → daarna staat de teller op de drempel.
        for ($i = 0; $i < 5; $i++) {
            $component
                ->set('code', '000000')
                ->call('submit')
                ->assertHasErrors(['code']);
        }

        // De zesde poging moet door de throttle worden geblokkeerd vóór
        // de service-call.
        $component
            ->set('code', '111111')
            ->call('submit')
            ->assertHasErrors(['code'])
            ->assertSee('Te veel pogingen, probeer over een minuut opnieuw.');
    }
}
