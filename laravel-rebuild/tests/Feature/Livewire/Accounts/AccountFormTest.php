<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Accounts;

use App\Livewire\Accounts\AccountForm;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\Services\AccountProvisioningServiceTest;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Accounts\AccountForm` (taak 12.3
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Modal start dicht en rendert geen formulier.
 *  - Open-event in create-modus zet `isOpen=true`, alle velden default.
 *  - Open-event in edit-modus laadt user-data in de velden.
 *  - Create-pad delegeert aan {@see AccountProvisioningService::create()}.
 *  - Create-pad met ongeldig e-mailadres rendert NL-foutmelding.
 *  - Edit-pad updatet via directe Eloquent.
 *  - Manager kan geen user buiten eigen team bewerken.
 *
 * Rationale waarom we de service mocken in
 * `test_create_calls_provisioning_service`:
 *  - We willen verifiëren dat de Livewire-laag de service met de juiste
 *    payload aanroept, niet de service-implementatie zelf testen — die
 *    heeft eigen unit-tests in {@see AccountProvisioningServiceTest}.
 *  - In de overige edit-tests gebruiken we directe Eloquent-assertions
 *    op de echte database (RefreshDatabase) zodat de scope-checks ook
 *    via SQL doorgevoerd worden.
 */
final class AccountFormTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $teamA;

    private Team $teamB;

    private User $owner;

    private User $managerA;

    private User $managerB;

    private User $employeeA;

    private User $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'LaVita Form Org']);

        $this->owner = User::create([
            'name' => 'Owner Form',
            'full_name' => 'Olivier Form',
            'email' => 'owner-form@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->managerA = User::create([
            'name' => 'Manager Form A',
            'full_name' => 'Mira Form A',
            'email' => 'mgr-form-a@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->managerB = User::create([
            'name' => 'Manager Form B',
            'full_name' => 'Mira Form B',
            'email' => 'mgr-form-b@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->teamA = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Form Alfa',
            'manager_id' => $this->managerA->id,
        ]);
        $this->teamB = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Form Beta',
            'manager_id' => $this->managerB->id,
        ]);

        $this->managerA->update(['team_id' => $this->teamA->id]);
        $this->managerB->update(['team_id' => $this->teamB->id]);

        $this->employeeA = User::create([
            'name' => 'Emp Form A',
            'full_name' => 'Eva Form A',
            'email' => 'emp-form-a@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamA->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employeeB = User::create([
            'name' => 'Emp Form B',
            'full_name' => 'Eva Form B',
            'email' => 'emp-form-b@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamB->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_modal_starts_closed(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->assertOk()
            ->assertSet('isOpen', false)
            ->assertDontSee('Nieuw account aanmaken')
            ->assertDontSee('Account bewerken');
    }

    public function test_open_event_in_create_mode(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: null)
            ->assertSet('isOpen', true)
            ->assertSet('userId', null)
            ->assertSet('name', '')
            ->assertSet('email', '')
            ->assertSet('role', 'employee')
            ->assertSet('teamId', null)
            ->assertSet('isActive', true)
            ->assertSee('Nieuw account aanmaken');
    }

    public function test_open_event_in_edit_mode_loads_user_data(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employeeA->id)
            ->assertSet('isOpen', true)
            ->assertSet('userId', $this->employeeA->id)
            ->assertSet('name', 'Emp Form A')
            ->assertSet('fullName', 'Eva Form A')
            ->assertSet('email', 'emp-form-a@lavita.test')
            ->assertSet('role', 'employee')
            ->assertSet('teamId', $this->teamA->id)
            ->assertSet('isActive', true)
            ->assertSee('Account bewerken');
    }

    public function test_create_calls_provisioning_service(): void
    {
        $this->actingAs($this->owner);

        // Mock de service zodat we de payload kunnen verifiëren zonder
        // de échte welcome-mail-flow te draaien (die heeft eigen tests).
        $mock = Mockery::mock(AccountProvisioningService::class);
        $mock->shouldReceive('create')
            ->once()
            ->withArgs(function (array $input, int $creatorId): bool {
                return $input['name'] === 'Nieuwe Medewerker'
                    && $input['email'] === 'nieuwe@lavita.test'
                    && $input['role'] === 'employee'
                    && $input['team_id'] === $this->teamA->id
                    && $creatorId === $this->owner->id;
            })
            ->andReturn([
                'id' => 9999,
                'email' => 'nieuwe@lavita.test',
                'role' => 'employee',
                'organization_id' => $this->org->id,
                'team_id' => $this->teamA->id,
                'is_active' => true,
                'onboarding_email_queued' => true,
            ]);

        $this->app->instance(AccountProvisioningService::class, $mock);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: null)
            ->set('name', 'Nieuwe Medewerker')
            ->set('fullName', 'Nieuwe Volledige Naam')
            ->set('email', 'nieuwe@lavita.test')
            ->set('role', 'employee')
            ->set('teamId', $this->teamA->id)
            ->set('isActive', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('account-saved')
            ->assertSet('isOpen', false);
    }

    public function test_create_with_invalid_email_shows_error(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: null)
            ->set('name', 'Test')
            ->set('email', 'geen-geldig-emailadres')
            ->set('role', 'employee')
            ->call('submit')
            ->assertHasErrors(['email'])
            ->assertSet('isOpen', true);
    }

    public function test_edit_updates_user_directly(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employeeA->id)
            ->set('name', 'Bewerkte Naam')
            ->set('fullName', 'Bewerkte Volledige Naam')
            // Email gelijk laten zodat de uniciteits-check niet trigger
            // gebeurt en de update zonder andere wijzigingen door kan.
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('account-saved')
            ->assertSet('isOpen', false);

        $this->employeeA->refresh();
        $this->assertSame('Bewerkte Naam', $this->employeeA->name);
        $this->assertSame('Bewerkte Volledige Naam', $this->employeeA->full_name);
    }

    public function test_edit_blocks_owner_role_demote(): void
    {
        // Owner mag z'n eigen rol niet via dit formulier degraderen.
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->owner->id)
            ->set('role', 'manager')
            ->call('submit')
            ->assertHasErrors('role');

        $this->owner->refresh();
        $this->assertSame('owner', (string) $this->owner->role);
    }

    public function test_manager_cannot_edit_user_outside_own_team(): void
    {
        $this->actingAs($this->managerA);

        // ManagerA opent een user uit team B → openModal weigert het en
        // sluit de modal direct.
        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employeeB->id)
            ->assertHasErrors('userId')
            ->assertSet('isOpen', false);

        // Database ongewijzigd.
        $this->employeeB->refresh();
        $this->assertSame('Emp Form B', $this->employeeB->name);
    }

    public function test_close_modal_resets_state(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employeeA->id)
            ->set('name', 'In progress')
            ->set('email', 'temp@lavita.test')
            ->call('closeModal')
            ->assertSet('isOpen', false)
            ->assertSet('userId', null)
            ->assertSet('name', '')
            ->assertSet('email', '')
            ->assertSet('role', 'employee')
            ->assertSet('teamId', null)
            ->assertSet('isActive', true);
    }
}
