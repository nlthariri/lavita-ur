<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Auth;

use App\Livewire\Auth\PasswordResetForm;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailOutboxService;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Smoke-tests voor Livewire-component `Auth\PasswordResetForm` (taak 9.4).
 *
 * Bron:
 *  - tasks.md 9.4 (lavita-urenregistratie):
 *      - "mount pakt token uit query string"
 *      - "berekent sterkte-score correct"
 *      - "blokkeert submit zonder mix"
 *      - "roept PasswordResetService::resetPassword aan bij geldig wachtwoord"
 *      - "toont token-fout bij ValidationException op token"
 *  - requirements.md 6.11 → tokenvalidatie + sterkte-indicator (min 12 +
 *      mix hoofd/klein/cijfer/symbool).
 *  - requirements.md 6.14 → NL-foutmeldingen.
 */
final class PasswordResetFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_returns_200_and_includes_nl_labels(): void
    {
        Livewire::test(PasswordResetForm::class, ['token' => 'dummy'])
            ->assertStatus(200)
            ->assertSee('Nieuw wachtwoord instellen')
            ->assertSee('Nieuw wachtwoord')
            ->assertSee('Bevestig nieuw wachtwoord')
            ->assertSee('Wachtwoord opslaan')
            ->assertSee('Minimaal 12 tekens met hoofdletter, kleine letter, cijfer en symbool.');
    }

    public function test_mount_resolves_token_from_query_string(): void
    {
        // Simuleer `/wachtwoord-reset?token=abc123` — Livewire's
        // `withQueryParams()` zet de query-bag op de virtuele test-request,
        // waarna `request()->query('token')` binnen mount() de waarde
        // teruggeeft. Dit dekt de fallback-tak in
        // {@see PasswordResetForm::mount()}.
        Livewire::withQueryParams(['token' => 'abc123'])
            ->test(PasswordResetForm::class)
            ->assertSet('token', 'abc123');
    }

    public function test_mount_resolves_token_from_explicit_arg_when_query_missing(): void
    {
        Livewire::test(PasswordResetForm::class, ['token' => 'expliciet-token'])
            ->assertSet('token', 'expliciet-token');
    }

    public function test_mount_falls_back_to_empty_string_when_no_token_anywhere(): void
    {
        Livewire::test(PasswordResetForm::class)
            ->assertSet('token', '');
    }

    /**
     * Verifieert dat de drie sterkte-helpers (`getStrengthScore`,
     * `getStrengthLabel`, `getStrengthMeetsPolicy`) correcte resultaten
     * leveren over een breed scala aan input. Datasets staan in
     * {@see strengthCases()}.
     */
    #[DataProvider('strengthCases')]
    public function test_strength_score_and_label_are_correct_for_password(
        string $password,
        int $expectedScore,
        string $expectedLabel,
        bool $expectedMeetsPolicy,
    ): void {
        $component = Livewire::test(PasswordResetForm::class, ['token' => 'dummy'])
            ->set('password', $password);

        $this->assertSame(
            $expectedScore,
            $component->instance()->getStrengthScore(),
            "Score for password [{$password}] should be {$expectedScore}."
        );

        $this->assertSame(
            $expectedLabel,
            $component->instance()->getStrengthLabel(),
            "Label for password [{$password}] should be '{$expectedLabel}'."
        );

        $this->assertSame(
            $expectedMeetsPolicy,
            $component->instance()->getStrengthMeetsPolicy(),
            "Policy-meet for password [{$password}] should be ".($expectedMeetsPolicy ? 'true' : 'false').'.'
        );
    }

    /**
     * Datasets voor de sterkte-helpers. Score is gecapt op 4 (4-segment-bar).
     *
     * Regels:
     *   +1 length ≥ 12, +1 lower, +1 upper, +1 digit, +1 symbol.
     *
     * Labels: 0/1 → Zwak, 2 → Matig, 3 → Sterk, 4 → Zeer sterk.
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: bool}>
     */
    public static function strengthCases(): array
    {
        return [
            // Empty → score 0, "Zwak", policy false.
            'empty string' => ['',                 0, 'Zwak',       false],
            // Korte string met alle mixen maar < 12 tekens:
            //   ruwe som: lower(1) + upper(1) + digit(1) + symbol(1) = 4
            //   gecapt op 4 → "Zeer sterk", maar policy faalt op length.
            'short with all mix but <12' => ['Aa1!Bb',           4, 'Zeer sterk', false],
            // 12 letters lowercase: length(1) + lower(1) = 2 → "Matig".
            'long lowercase only' => ['aaaaaaaaaaaa',     2, 'Matig',      false],
            // 12 tekens zonder symbol: length(1) + lower(1) + upper(1) + digit(1) = 4 → "Zeer sterk", maar policy faalt op symbol.
            'long without symbol' => ['Aaaaaaaaaaa1',     4, 'Zeer sterk', false],
            // 12 tekens met alle 5 criteria: ruwe som = 5 → gecapt op 4 → "Zeer sterk", policy true.
            'long with full mix' => ['Aa1!aaaaaaaa',     4, 'Zeer sterk', true],
            // Wachtwoord uit echt scenario.
            'realistic strong password' => ['Welkom2026!Lavita', 4, 'Zeer sterk', true],
            // Drie criteria: length + lower + digit (geen upper/symbol) = 3 → "Sterk".
            'long lowercase plus digit' => ['aaaaaaaaaa12',     3, 'Sterk',      false],
        ];
    }

    public function test_submit_blocks_when_password_lacks_required_mix(): void
    {
        // 12 tekens lowercase — voldoet aan `min:12` validatie maar faalt
        // de policy-check (geen upper, geen digit, geen symbol). Submit
        // moet daarom een NL-policy-error op `password` zetten en NIET
        // de service aanroepen.
        $serviceCalled = false;
        $this->app->instance(
            PasswordResetService::class,
            new class(app(EmailOutboxService::class)) extends PasswordResetService
            {
                public static bool $called = false;

                public function resetPassword(string $encodedToken, string $newPassword): void
                {
                    self::$called = true;
                }
            }
        );

        Livewire::test(PasswordResetForm::class, ['token' => 'dummy'])
            ->set('password', 'aaaaaaaaaaaa')
            ->set('passwordConfirmation', 'aaaaaaaaaaaa')
            ->call('submit')
            ->assertHasErrors(['password'])
            ->assertSee('Wachtwoord moet minimaal 12 tekens lang zijn en hoofdletter, kleine letter, cijfer en symbool bevatten.');

        // De service mag niet zijn aangeroepen — policy-check faalt al
        // vóór de service-call.
        $service = $this->app->make(PasswordResetService::class);
        $this->assertFalse(
            $service::$called,
            'PasswordResetService::resetPassword mag niet worden aangeroepen wanneer policy faalt.'
        );
    }

    public function test_submit_blocks_when_password_confirmation_does_not_match(): void
    {
        Livewire::test(PasswordResetForm::class, ['token' => 'dummy'])
            ->set('password', 'Welkom2026!Lavita')
            ->set('passwordConfirmation', 'iets-anders')
            ->call('submit')
            ->assertHasErrors(['passwordConfirmation' => 'same']);
    }

    public function test_submit_blocks_short_password_with_min_validation_error(): void
    {
        Livewire::test(PasswordResetForm::class, ['token' => 'dummy'])
            ->set('password', 'Aa1!short')
            ->set('passwordConfirmation', 'Aa1!short')
            ->call('submit')
            ->assertHasErrors(['password' => 'min']);
    }

    public function test_valid_password_invokes_service_and_redirects_to_login_with_reset_ok(): void
    {
        $org = Organization::create([
            'name' => 'LaVita Org Reset',
            'kvk_number' => '99988877',
            'sector' => 'zorg',
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => 'Reset Tester',
            'full_name' => 'Reset Tester',
            'email' => 'reset@lavita.nl',
            'password' => Hash::make('OudWachtwoord123!'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Anonieme spy: registreert (token, password)-aanroepen zonder
        // werkelijke wachtwoord-update of HMAC-validatie.
        $spy = new class(app(EmailOutboxService::class)) extends PasswordResetService
        {
            /** @var list<array{token: string, password: string}> */
            public static array $calls = [];

            public function __construct(EmailOutboxService $emailOutboxService)
            {
                parent::__construct($emailOutboxService);
                self::$calls = [];
            }

            public function resetPassword(string $encodedToken, string $newPassword): void
            {
                self::$calls[] = ['token' => $encodedToken, 'password' => $newPassword];
            }
        };

        $this->app->instance(PasswordResetService::class, $spy);

        $newPassword = 'Welkom2026!Lavita';

        Livewire::test(PasswordResetForm::class, ['token' => 'geldig-token-abc'])
            ->set('password', $newPassword)
            ->set('passwordConfirmation', $newPassword)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(url('/inloggen?reset=ok'));

        $this->assertSame(
            [['token' => 'geldig-token-abc', 'password' => $newPassword]],
            $spy::$calls,
            'PasswordResetService::resetPassword moet exact één keer worden aangeroepen met token+password.'
        );

        // Voor de zekerheid: de echte user is niet geraakt door de spy.
        $user->refresh();
        $this->assertTrue(
            Hash::check('OudWachtwoord123!', $user->password),
            'De spy mag het echte wachtwoord niet wijzigen.'
        );
    }

    public function test_token_validation_exception_sets_token_error_property(): void
    {
        $this->app->instance(
            PasswordResetService::class,
            new class(app(EmailOutboxService::class)) extends PasswordResetService
            {
                public function resetPassword(string $encodedToken, string $newPassword): void
                {
                    throw ValidationException::withMessages([
                        'token' => 'Resetlink is ongeldig of verlopen.',
                    ]);
                }
            }
        );

        $newPassword = 'Welkom2026!Lavita';

        Livewire::test(PasswordResetForm::class, ['token' => 'kapot-token'])
            ->set('password', $newPassword)
            ->set('passwordConfirmation', $newPassword)
            ->call('submit')
            ->assertHasErrors(['token'])
            ->assertSet('tokenError', 'Resetlink is ongeldig of verlopen.')
            ->assertSee('Resetlink is ongeldig of verlopen.')
            ->assertSee('Vraag een nieuwe resetlink aan');
    }

    public function test_password_validation_exception_maps_to_password_field(): void
    {
        // Service gooit een password-error (bv. "kies ander wachtwoord
        // dan het huidige"). Component moet die op het `password`-veld
        // zetten en GEEN `tokenError` markeren.
        $this->app->instance(
            PasswordResetService::class,
            new class(app(EmailOutboxService::class)) extends PasswordResetService
            {
                public function resetPassword(string $encodedToken, string $newPassword): void
                {
                    throw ValidationException::withMessages([
                        'password' => 'Kies een nieuw wachtwoord dat verschilt van het huidige wachtwoord.',
                    ]);
                }
            }
        );

        $newPassword = 'Welkom2026!Lavita';

        Livewire::test(PasswordResetForm::class, ['token' => 'geldig-token'])
            ->set('password', $newPassword)
            ->set('passwordConfirmation', $newPassword)
            ->call('submit')
            ->assertHasErrors(['password'])
            ->assertSet('tokenError', null)
            ->assertSee('Kies een nieuw wachtwoord dat verschilt van het huidige wachtwoord.');
    }
}
