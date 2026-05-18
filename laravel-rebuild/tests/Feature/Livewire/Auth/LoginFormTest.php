<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Auth;

use App\Livewire\Auth\LoginForm;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke-tests voor Livewire-component `Auth\LoginForm` (taak 9.1).
 *
 * We dekken drie minimale scenario's:
 *  1. render → 200, formuliervelden aanwezig.
 *  2. submit zonder e-mail → validation-error op `email` (NL-melding).
 *  3. submit met geldige credentials → AuthMfaService::login() wordt feitelijk
 *     aangeroepen (DB-sessie is aangemaakt) en redirect naar
 *     `/auth/mfa-verify?user_id=...` is gezet.
 */
final class LoginFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_returns_200_and_includes_nl_labels(): void
    {
        Livewire::test(LoginForm::class)
            ->assertStatus(200)
            ->assertSee('Inloggen')
            ->assertSee('E-mailadres')
            ->assertSee('Wachtwoord');
    }

    public function test_missing_email_triggers_validation_error(): void
    {
        Livewire::test(LoginForm::class)
            ->set('password', 'EenLangWachtwoord123')
            ->call('submit')
            ->assertHasErrors(['email' => 'required']);
    }

    public function test_invalid_email_format_triggers_nl_validation_error(): void
    {
        Livewire::test(LoginForm::class)
            ->set('email', 'geen-geldig-email')
            ->set('password', 'EenLangWachtwoord123')
            ->call('submit')
            ->assertHasErrors(['email' => 'email']);
    }

    public function test_short_password_triggers_min_validation_error(): void
    {
        Livewire::test(LoginForm::class)
            ->set('email', 'user@lavita.nl')
            ->set('password', 'kort')
            ->call('submit')
            ->assertHasErrors(['password' => 'min']);
    }

    public function test_valid_credentials_call_service_and_redirect_to_mfa_verify(): void
    {
        $org = Organization::create(['name' => 'LaVita Org Login']);
        $user = User::factory()->create([
            'email' => 'login-success@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'organization_id' => $org->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'LangWachtwoord123')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(url('/auth/mfa-verify'));

        // Bevestig dat de sessie de pending MFA user_id bevat
        $this->assertSame($user->id, session('pending_mfa_user_id') ?? session()->get('pending_mfa_user_id'));

        // Bevestig dat de service feitelijk een sessie heeft aangemaakt.
        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
        ]);
    }

    public function test_invalid_credentials_render_inline_nl_error_on_email_field(): void
    {
        // Geen user aangemaakt → service throwt ValidationException
        // met "Ongeldige inloggegevens." (zie AuthMfaService::login).
        Livewire::test(LoginForm::class)
            ->set('email', 'onbekend@lavita.nl')
            ->set('password', 'LangWachtwoord123')
            ->call('submit')
            ->assertHasErrors(['email'])
            ->assertSee('Ongeldige inloggegevens.');
    }
}
