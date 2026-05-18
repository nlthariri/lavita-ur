<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Auth;

use App\Livewire\Auth\PasswordForgotForm;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailOutboxService;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Smoke-tests voor Livewire-component `Auth\PasswordForgotForm` (taak 9.4).
 *
 * Bron:
 *  - tasks.md 9.4 (lavita-urenregistratie):
 *      - "rendert het formulier"
 *      - "valideert leeg e-mailadres met NL melding"
 *      - "toont generieke bevestiging ook bij onbekend adres"
 *      - "roept PasswordResetService::requestReset aan bij geldig adres"
 *  - requirements.md 6.11 → vergeet-/reset-flow.
 *  - requirements.md 6.14 → NL-foutmeldingen.
 */
final class PasswordForgotFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_returns_200_and_includes_nl_labels(): void
    {
        Livewire::test(PasswordForgotForm::class)
            ->assertStatus(200)
            ->assertSee('Wachtwoord vergeten')
            ->assertSee('E-mailadres')
            ->assertSee('Stuur resetlink')
            ->assertSee('Terug naar inloggen');
    }

    public function test_empty_email_triggers_required_validation_with_nl_message(): void
    {
        Livewire::test(PasswordForgotForm::class)
            ->set('email', '')
            ->call('submit')
            ->assertHasErrors(['email' => 'required'])
            ->assertSee('Vul je e-mailadres in.');
    }

    public function test_invalid_email_format_triggers_nl_validation_error(): void
    {
        Livewire::test(PasswordForgotForm::class)
            ->set('email', 'geen-geldig-email')
            ->call('submit')
            ->assertHasErrors(['email' => 'email']);
    }

    public function test_unknown_email_shows_generic_nl_confirmation_without_error(): void
    {
        // Geen user aangemaakt → service::requestReset doet stilletjes niets
        // (timing-safe). Component MOET niettemin de generieke bevestiging
        // tonen en GEEN veldfout zetten.
        Livewire::test(PasswordForgotForm::class)
            ->set('email', 'onbekend@lavita.nl')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('email', '')
            ->assertSet('confirmation', 'Als dit e-mailadres bestaat, ontvang je een resetlink.')
            ->assertSee('Als dit e-mailadres bestaat, ontvang je een resetlink.');
    }

    public function test_unexpected_service_exception_still_shows_generic_confirmation(): void
    {
        // Bind een PasswordResetService die een random fout gooit; de
        // component moet NOOIT lekken dat er iets misging — generieke NL-
        // bevestiging blijft staan en email wordt gewist.
        $this->app->instance(PasswordResetService::class, new class extends PasswordResetService
        {
            public function __construct()
            {
                // Skip parent constructor: we hebben geen EmailOutboxService nodig
                // omdat we requestReset() overschrijven om altijd te throwen.
            }

            public function requestReset(string $email): void
            {
                throw new RuntimeException('Database is down');
            }
        });

        Livewire::test(PasswordForgotForm::class)
            ->set('email', 'iemand@lavita.nl')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('email', '')
            ->assertSet('confirmation', 'Als dit e-mailadres bestaat, ontvang je een resetlink.');
    }

    public function test_known_email_invokes_password_reset_service_request_reset(): void
    {
        // Maak een echt account aan zodat de service de gebruiker vindt en
        // een outbox-mail zou willen aanmaken. We vervangen de service
        // door een spy zodat we precies kunnen verifiëren dat
        // `requestReset($email)` is aangeroepen.
        $org = Organization::create([
            'name' => 'LaVita Org Forgot',
            'kvk_number' => '11122233',
            'sector' => 'zorg',
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => 'Forgot Tester',
            'full_name' => 'Forgot Tester',
            'email' => 'forgot@lavita.nl',
            'password' => Hash::make('LangWachtwoord123!'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Anonieme spy: extends PasswordResetService maar logt elke
        // requestReset-aanroep in een statische array. We omzeilen de
        // parent-constructor zodat we geen echte EmailOutboxService nodig
        // hebben (anders zou de spy een DB-write naar email_outbox doen
        // die voor deze unit-test niet relevant is).
        $spy = new class(app(EmailOutboxService::class)) extends PasswordResetService
        {
            /** @var list<string> */
            public static array $calls = [];

            public function __construct(EmailOutboxService $emailOutboxService)
            {
                parent::__construct($emailOutboxService);
                self::$calls = [];
            }

            public function requestReset(string $email): void
            {
                self::$calls[] = $email;
                // Geen parent-aanroep: we testen alleen dát de Livewire-
                // component de service aanroept, niet de side-effects van
                // de service zelf (die heeft eigen contract-tests).
            }
        };

        $this->app->instance(PasswordResetService::class, $spy);

        Livewire::test(PasswordForgotForm::class)
            ->set('email', $user->email)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('email', '')
            ->assertSet('confirmation', 'Als dit e-mailadres bestaat, ontvang je een resetlink.');

        // De service is exact één keer aangeroepen met het ingevoerde adres.
        $this->assertSame([$user->email], $spy::$calls);
    }
}
