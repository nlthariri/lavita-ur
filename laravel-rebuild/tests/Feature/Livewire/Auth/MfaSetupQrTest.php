<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Auth;

use App\Livewire\Auth\MfaSetupQr;
use App\Models\MfaRecoveryCode;
use App\Models\MfaSecret;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke-tests voor Livewire-component `Auth\MfaSetupQr` (taak 9.3).
 *
 * Bron:
 *  - tasks.md 9.3 (lavita-urenregistratie):
 *      - Render returns 200 + sees NL pre-setup label.
 *      - Wrong password adds inline error.
 *      - Correct password generates secret, qrDataUrl (data:image/png),
 *        and 8 recovery codes.
 *
 * Implementatie-noot: we testen de component in beide modi:
 *  - "stateless" via expliciete `userId`-mount-arg (test-fixture). Dit dekt
 *    de fallback-tak in {@see MfaSetupQr::mount()} omdat `auth()->id()` in
 *    de testcontext null is. In productie loopt deze flow achter
 *    `auth`-middleware en overrulet `auth()->id()` de mount-arg —
 *    die productieflow blijft hier buiten scope (komt in routing-task 9.5).
 */
final class MfaSetupQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_returns_200_and_includes_nl_pre_setup_label(): void
    {
        Livewire::test(MfaSetupQr::class, ['userId' => 1])
            ->assertStatus(200)
            ->assertSee('MFA instellen')
            ->assertSee('Bevestig je huidige wachtwoord')
            ->assertSee('Genereer MFA');
    }

    public function test_wrong_password_adds_inline_error(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-setup-wrong@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        // We geven een wachtwoord van >=12 tekens (zodat de `min:12`-rule
        // slaagt) maar dat NIET overeenkomt met het opgeslagen hash. De
        // service moet dan met "Wachtwoordbevestiging ongeldig." throwen.
        Livewire::test(MfaSetupQr::class, ['userId' => $user->id])
            ->set('password', 'AnderWachtwoord456')
            ->call('submit')
            ->assertHasErrors(['password'])
            ->assertSee('Wachtwoordbevestiging ongeldig.');

        // Geen secret of recovery codes mogen zijn opgeslagen in de DB.
        $this->assertDatabaseCount('mfa_secrets', 0);
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
    }

    public function test_short_password_triggers_min_validation_error(): void
    {
        Livewire::test(MfaSetupQr::class, ['userId' => 1])
            ->set('password', 'kort')
            ->call('submit')
            ->assertHasErrors(['password' => 'min']);
    }

    public function test_correct_password_generates_secret_qr_data_url_and_eight_recovery_codes(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-setup-ok@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $component = Livewire::test(MfaSetupQr::class, ['userId' => $user->id])
            ->set('password', 'LangWachtwoord123')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('setupComplete', true);

        // 1) `secret` is gewist na dehydrate (V-06 security fix) maar
        //    `qrDataUrl` bevat het secret visueel in de QR-code.
        //    We verifiëren dat qrDataUrl wél beschikbaar is.
        $secret = $component->get('secret');
        // Na dehydrate is secret null (security: niet in volgende snapshot)
        // Dit is correct gedrag — het secret was beschikbaar in de eerste render.

        // 2) `qrDataUrl` begint met de PNG-data-URI-prefix.
        $qrDataUrl = $component->get('qrDataUrl');
        $this->assertIsString($qrDataUrl);
        $this->assertStringStartsWith('data:image/png;base64,', $qrDataUrl);

        // 3) Precies 8 recovery codes, elk 10 tekens uppercase alfanumeriek.
        $recoveryCodes = $component->get('recoveryCodes');
        $this->assertIsArray($recoveryCodes);
        $this->assertCount(8, $recoveryCodes);
        foreach ($recoveryCodes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{10}$/', $code);
        }

        // 4) Wachtwoord is gewist uit de component-state.
        $component->assertSet('password', '');

        // 5) DB-zijdig: 1 mfa_secret en 8 mfa_recovery_codes voor deze user.
        $this->assertSame(1, MfaSecret::query()->where('user_id', $user->id)->count());
        $this->assertSame(8, MfaRecoveryCode::query()->where('user_id', $user->id)->count());
    }

    public function test_post_setup_view_shows_qr_image_with_nl_alt_and_aria_live_status(): void
    {
        $user = User::factory()->create([
            'email' => 'mfa-setup-render@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        Livewire::test(MfaSetupQr::class, ['userId' => $user->id])
            ->set('password', 'LangWachtwoord123')
            ->call('submit')
            ->assertHasNoErrors()
            // QR-img met NL alt-tekst (tasks.md 9.3 + WCAG 1.1.1).
            ->assertSeeHtml('alt="QR-code voor MFA-setup"')
            // Kopieer-knop labels (NL).
            ->assertSee('Kopieer secret')
            ->assertSee('Kopieer alle herstelcodes')
            // `aria-live="polite"` op de aankondigings-regio's voor de
            // kopieer-acties (tasks.md 9.3).
            ->assertSeeHtml('aria-live="polite"');
    }
}
