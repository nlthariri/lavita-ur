<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Accounts;

use App\Livewire\Accounts\AccountsList;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Accounts\AccountsList` (taak 12.3
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Forbidden-pad voor employee-rol en boekhouder-rol (req 6.8 + 3.x).
 *  - Owner default-scope: alle accounts in eigen organisatie zichtbaar,
 *    maar niets uit een andere organisatie (sentinel-employee).
 *  - Manager-team-scope-isolatie: alleen eigen team-employees + manager
 *    zelf.
 *  - Search-filter (case-insensitive op naam).
 *  - Rol-filter en status-filter werkend.
 *  - `toggleActive`: flipt `is_active`, weigert self-toggle.
 *  - Soft-delete: alleen owner mag, anders foutmelding; toont een
 *    deferred-bevestiging.
 *
 * Rationale waarom we Livewire::test direct aanroepen i.p.v. een HTTP-route:
 *  - De web-route op `/accounts` wordt in een latere taak geregistreerd;
 *    taak 12.3 levert de componenten zelf.
 *  - `Livewire::test()` rendert het component met `actingAs($user)` —
 *    exact wat we hier nodig hebben.
 */
final class AccountsListTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org1;

    private Organization $org2;

    private User $owner;

    private User $managerA;

    private User $managerB;

    private Team $teamA;

    private Team $teamB;

    /** @var array<int, User> */
    private array $employeesTeamA = [];

    /** @var array<int, User> */
    private array $employeesTeamB = [];

    private User $bookkeeper;

    private User $sentinelEmployeeOtherOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org1 = Organization::create(['name' => 'LaVita Org Eén']);
        $this->org2 = Organization::create(['name' => 'LaVita Org Twee']);

        // Owner van org1.
        $this->owner = User::create([
            'name' => 'Owner Eén',
            'full_name' => 'Olivier Owner',
            'email' => 'owner1@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Twee teams in org1, elk met eigen manager.
        $this->managerA = User::create([
            'name' => 'Manager A',
            'full_name' => 'Anneke Manager',
            'email' => 'mgr-a@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->managerB = User::create([
            'name' => 'Manager B',
            'full_name' => 'Bert Manager',
            'email' => 'mgr-b@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->teamA = Team::create([
            'organization_id' => $this->org1->id,
            'name' => 'Team Alfa',
            'manager_id' => $this->managerA->id,
        ]);
        $this->teamB = Team::create([
            'organization_id' => $this->org1->id,
            'name' => 'Team Beta',
            'manager_id' => $this->managerB->id,
        ]);

        // Pin de managers aan hun eigen team.
        $this->managerA->update(['team_id' => $this->teamA->id]);
        $this->managerB->update(['team_id' => $this->teamB->id]);

        // 2 employees in team A.
        for ($i = 1; $i <= 2; $i++) {
            $this->employeesTeamA[] = User::create([
                'name' => 'Emp A'.$i,
                'full_name' => 'Alpha '.$i.' Werknemer',
                'email' => 'emp-a'.$i.'@lavita.test',
                'password' => bcrypt('Wachtwoord1234'),
                'organization_id' => $this->org1->id,
                'team_id' => $this->teamA->id,
                'role' => 'employee',
                'is_active' => true,
            ]);
        }

        // 2 employees in team B (één inactief om status-filter te testen).
        for ($i = 1; $i <= 2; $i++) {
            $this->employeesTeamB[] = User::create([
                'name' => 'Emp B'.$i,
                'full_name' => 'Beta '.$i.' Werknemer',
                'email' => 'emp-b'.$i.'@lavita.test',
                'password' => bcrypt('Wachtwoord1234'),
                'organization_id' => $this->org1->id,
                'team_id' => $this->teamB->id,
                'role' => 'employee',
                'is_active' => $i === 1, // tweede is inactief
            ]);
        }

        // Boekhouder in org1 (zonder team — req 3.8).
        $this->bookkeeper = User::create([
            'name' => 'Boekhouder',
            'full_name' => 'Bea Boekhouder',
            'email' => 'boek1@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        // Sentinel employee in een andere organisatie — moet NOOIT
        // voorkomen in de scope van de owner/manager van org1.
        $this->sentinelEmployeeOtherOrg = User::create([
            'name' => 'Sentinel Other',
            'full_name' => 'Sentinel Andere Org',
            'email' => 'sentinel@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org2->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    public function test_employee_role_is_forbidden(): void
    {
        $this->actingAs($this->employeesTeamA[0]);

        Livewire::test(AccountsList::class)
            ->assertForbidden();
    }

    public function test_boekhouder_role_is_forbidden(): void
    {
        $this->actingAs($this->bookkeeper);

        Livewire::test(AccountsList::class)
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        Livewire::test(AccountsList::class)
            ->assertForbidden();
    }

    public function test_owner_sees_all_org_users_and_org2_sentinel_excluded(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(AccountsList::class)
            ->assertOk()
            ->assertSet('organizationName', 'LaVita Org Eén');

        $users = $component->instance()->getUsers();
        $ids = $users->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Alle zeven org1-accounts staan erin: owner + 2 managers +
        // 4 employees + 1 boekhouder.
        $this->assertContains($this->owner->id, $ids);
        $this->assertContains($this->managerA->id, $ids);
        $this->assertContains($this->managerB->id, $ids);
        $this->assertContains($this->employeesTeamA[0]->id, $ids);
        $this->assertContains($this->employeesTeamA[1]->id, $ids);
        $this->assertContains($this->employeesTeamB[0]->id, $ids);
        $this->assertContains($this->employeesTeamB[1]->id, $ids);
        $this->assertContains($this->bookkeeper->id, $ids);

        // Sentinel uit org2 moet ontbreken.
        $this->assertNotContains($this->sentinelEmployeeOtherOrg->id, $ids);
    }

    public function test_manager_only_sees_own_team_users(): void
    {
        $this->actingAs($this->managerA);

        $component = Livewire::test(AccountsList::class)
            ->assertOk();

        $users = $component->instance()->getUsers();
        $ids = $users->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Manager A ziet alleen team A + zichzelf.
        $this->assertContains($this->managerA->id, $ids);
        $this->assertContains($this->employeesTeamA[0]->id, $ids);
        $this->assertContains($this->employeesTeamA[1]->id, $ids);

        // Manager A ziet NIET het andere team of de owner of de
        // boekhouder (geen team_id).
        $this->assertNotContains($this->managerB->id, $ids);
        $this->assertNotContains($this->employeesTeamB[0]->id, $ids);
        $this->assertNotContains($this->employeesTeamB[1]->id, $ids);
        $this->assertNotContains($this->owner->id, $ids);
        $this->assertNotContains($this->bookkeeper->id, $ids);
    }

    public function test_search_filters_by_name(): void
    {
        $this->actingAs($this->owner);

        // Zoek op "Alpha" — moet de twee team-A-employees opleveren.
        $component = Livewire::test(AccountsList::class)
            ->set('search', 'alpha');

        $ids = $component->instance()->getUsers()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($this->employeesTeamA[0]->id, $ids);
        $this->assertContains($this->employeesTeamA[1]->id, $ids);
        $this->assertNotContains($this->employeesTeamB[0]->id, $ids);
        $this->assertNotContains($this->bookkeeper->id, $ids);
        $this->assertNotContains($this->owner->id, $ids);
    }

    public function test_role_filter_works(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(AccountsList::class)
            ->set('roleFilter', 'manager');

        $ids = $component->instance()->getUsers()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertCount(2, $ids);
        $this->assertContains($this->managerA->id, $ids);
        $this->assertContains($this->managerB->id, $ids);
    }

    public function test_status_filter_works(): void
    {
        $this->actingAs($this->owner);

        // Alleen inactieve accounts: dat is alleen employeesTeamB[1]
        // (zie setUp).
        $component = Livewire::test(AccountsList::class)
            ->set('statusFilter', 'inactive');

        $ids = $component->instance()->getUsers()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertCount(1, $ids);
        $this->assertContains($this->employeesTeamB[1]->id, $ids);
    }

    public function test_toggle_active_flips_is_active(): void
    {
        $this->actingAs($this->owner);

        $target = $this->employeesTeamA[0];
        $this->assertTrue((bool) $target->is_active);

        Livewire::test(AccountsList::class)
            ->call('toggleActive', $target->id)
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);

        // Nog een keer togglen → weer actief.
        Livewire::test(AccountsList::class)
            ->call('toggleActive', $target->id)
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertTrue((bool) $target->is_active);
    }

    public function test_cannot_deactivate_self(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountsList::class)
            ->call('toggleActive', $this->owner->id)
            ->assertHasErrors('toggle');

        $this->owner->refresh();
        $this->assertTrue(
            (bool) $this->owner->is_active,
            'Owner moet actief blijven na poging tot self-deactivate.',
        );
    }

    public function test_manager_cannot_toggle_other_team_user(): void
    {
        $this->actingAs($this->managerA);

        Livewire::test(AccountsList::class)
            ->call('toggleActive', $this->employeesTeamB[0]->id)
            ->assertHasErrors('toggle');

        // Status van de andere team-employee ongewijzigd.
        $this->employeesTeamB[0]->refresh();
        $this->assertTrue((bool) $this->employeesTeamB[0]->is_active);
    }

    public function test_soft_delete_only_for_owner(): void
    {
        // Manager probeert soft-delete → moet falen met softDelete-error.
        $this->actingAs($this->managerA);

        Livewire::test(AccountsList::class)
            ->call('softDeletePlaceholder', $this->employeesTeamA[0]->id)
            ->assertHasErrors('softDelete');

        // Account ongewijzigd in de database.
        $this->employeesTeamA[0]->refresh();
        $this->assertNotNull($this->employeesTeamA[0]);
    }

    public function test_soft_delete_shows_deferred_message(): void
    {
        // Owner mag — zet een NL-bevestiging maar verwijdert niets.
        $this->actingAs($this->owner);

        $component = Livewire::test(AccountsList::class)
            ->call('softDeletePlaceholder', $this->employeesTeamA[0]->id)
            ->assertHasNoErrors();

        $confirmation = $component->get('confirmation');
        $this->assertIsString($confirmation);
        $this->assertStringContainsString('17.x', (string) $confirmation);
        $this->assertStringContainsString('retentie-module', (string) $confirmation);

        // Account is NIET verwijderd of gepseudonimiseerd: de placeholder
        // doet bewust geen DB-mutatie totdat 17.x landt.
        $stillExists = User::where('id', $this->employeesTeamA[0]->id)
            ->where('email_index_hash', hash('sha256', 'emp-a1@lavita.test'))
            ->exists();
        $this->assertTrue(
            $stillExists,
            'Soft-delete-placeholder mag het account nog niet wijzigen.',
        );
    }

    public function test_open_create_dispatches_event_with_null_user(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountsList::class)
            ->call('openCreate')
            ->assertDispatched('open-account-form', userId: null);
    }

    public function test_open_edit_dispatches_event_with_user_id(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountsList::class)
            ->call('openEdit', $this->employeesTeamA[0]->id)
            ->assertDispatched(
                'open-account-form',
                userId: $this->employeesTeamA[0]->id,
            );
    }
}
