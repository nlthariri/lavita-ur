<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Settings;

use App\Livewire\Settings\LeaveTypesManager;
use App\Models\AuditEvent;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Settings\LeaveTypesManager` (taak 12.3).
 *
 * Dekt:
 *  - Autorisatie: alleen owner heeft toegang
 *  - CRUD: aanmaken, bewerken, deactiveren van verlof-types
 *  - Validatie: unieke code per organisatie, verplichte velden, max-lengtes
 *  - Audit-events: LEAVE_TYPE_CREATED, LEAVE_TYPE_UPDATED, LEAVE_TYPE_DEACTIVATED
 *  - Dispatch `leave-type-updated` event bij wijzigingen
 *  - Organisatie-scoping: geen data-lekkage tussen organisaties
 */
final class LeaveTypesManagerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org1;

    private Organization $org2;

    private User $owner;

    private User $manager;

    private User $employee;

    private User $ownerOrg2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org1 = Organization::create(['name' => 'Org Eén']);
        $this->org2 = Organization::create(['name' => 'Org Twee']);

        $this->owner = User::create([
            'name' => 'Owner',
            'full_name' => 'Olivia Owner',
            'email' => 'owner-lt@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager',
            'full_name' => 'Marten Manager',
            'email' => 'manager-lt@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee',
            'full_name' => 'Eva Employee',
            'email' => 'employee-lt@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->ownerOrg2 = User::create([
            'name' => 'Owner2',
            'full_name' => 'Oscar Owner',
            'email' => 'owner2-lt@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org2->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    // ─── Autorisatie ─────────────────────────────────────────────────────

    public function test_employee_cannot_access_leave_types_manager(): void
    {
        $this->actingAs($this->employee);

        Livewire::test(LeaveTypesManager::class)
            ->assertForbidden();
    }

    public function test_manager_cannot_access_leave_types_manager(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(LeaveTypesManager::class)
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        Livewire::test(LeaveTypesManager::class)
            ->assertForbidden();
    }

    public function test_owner_can_access_leave_types_manager(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->assertOk()
            ->assertSee('Verlof-types');
    }

    // ─── Aanmaken ────────────────────────────────────────────────────────

    public function test_owner_can_create_leave_type(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->assertSet('showForm', true)
            ->set('code', 'VAKANTIE')
            ->set('name', 'Vakantieverlof')
            ->set('description', 'Regulier vakantieverlof')
            ->set('maxDaysPerYear', '25')
            ->set('countsTowardsBalance', true)
            ->call('save')
            ->assertSet('showForm', false)
            ->assertSet('confirmation', 'Verlof-type "Vakantieverlof" aangemaakt.')
            ->assertDispatched('leave-type-updated');

        $this->assertDatabaseHas('leave_types', [
            'organization_id' => $this->org1->id,
            'code' => 'VAKANTIE',
            'name' => 'Vakantieverlof',
            'description' => 'Regulier vakantieverlof',
            'max_days_per_year' => 25,
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        // Audit-event geschreven
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org1->id,
            'actor_id' => $this->owner->id,
            'action' => 'LEAVE_TYPE_CREATED',
            'target_type' => 'leave_type',
        ]);
    }

    public function test_create_leave_type_with_nullable_fields(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'BIJZONDER')
            ->set('name', 'Bijzonder verlof')
            ->set('description', '')
            ->set('maxDaysPerYear', '')
            ->set('countsTowardsBalance', false)
            ->call('save')
            ->assertSet('showForm', false)
            ->assertDispatched('leave-type-updated');

        $this->assertDatabaseHas('leave_types', [
            'organization_id' => $this->org1->id,
            'code' => 'BIJZONDER',
            'name' => 'Bijzonder verlof',
            'description' => null,
            'max_days_per_year' => null,
            'counts_towards_balance' => false,
        ]);
    }

    // ─── Bewerken ────────────────────────────────────────────────────────

    public function test_owner_can_edit_leave_type(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org1->id,
            'code' => 'VAKANTIE',
            'name' => 'Vakantieverlof',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('editLeaveType', $leaveType->id)
            ->assertSet('showForm', true)
            ->assertSet('editingId', $leaveType->id)
            ->assertSet('code', 'VAKANTIE')
            ->assertSet('name', 'Vakantieverlof')
            ->set('name', 'Vakantiedagen')
            ->set('maxDaysPerYear', '30')
            ->call('save')
            ->assertSet('showForm', false)
            ->assertSet('confirmation', 'Verlof-type "Vakantiedagen" bijgewerkt.')
            ->assertDispatched('leave-type-updated');

        $this->assertDatabaseHas('leave_types', [
            'id' => $leaveType->id,
            'name' => 'Vakantiedagen',
            'max_days_per_year' => 30,
        ]);

        // Audit-event geschreven
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org1->id,
            'actor_id' => $this->owner->id,
            'action' => 'LEAVE_TYPE_UPDATED',
            'target_type' => 'leave_type',
            'target_id' => $leaveType->id,
        ]);
    }

    // ─── Deactiveren ─────────────────────────────────────────────────────

    public function test_owner_can_deactivate_leave_type(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org1->id,
            'code' => 'ONBETAALD',
            'name' => 'Onbetaald verlof',
            'counts_towards_balance' => false,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('deactivate', $leaveType->id)
            ->assertSet('confirmation', 'Verlof-type "Onbetaald verlof" gedeactiveerd.')
            ->assertDispatched('leave-type-updated');

        $this->assertDatabaseHas('leave_types', [
            'id' => $leaveType->id,
            'is_active' => false,
        ]);

        // Audit-event geschreven
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org1->id,
            'actor_id' => $this->owner->id,
            'action' => 'LEAVE_TYPE_DEACTIVATED',
            'target_type' => 'leave_type',
            'target_id' => $leaveType->id,
        ]);
    }

    public function test_cannot_deactivate_already_inactive_leave_type(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org1->id,
            'code' => 'INACTIEF',
            'name' => 'Inactief type',
            'counts_towards_balance' => false,
            'is_active' => false,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('deactivate', $leaveType->id)
            ->assertSet('error', 'Dit verlof-type is al gedeactiveerd.');
    }

    // ─── Validatie ───────────────────────────────────────────────────────

    public function test_code_is_required(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', '')
            ->set('name', 'Test')
            ->call('save')
            ->assertHasErrors('code')
            ->assertSet('showForm', true);
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'TEST')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors('name')
            ->assertSet('showForm', true);
    }

    public function test_code_max_length_40(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', str_repeat('A', 41))
            ->set('name', 'Test')
            ->call('save')
            ->assertHasErrors('code');
    }

    public function test_name_max_length_120(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'TEST')
            ->set('name', str_repeat('A', 121))
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_description_max_length_500(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'TEST')
            ->set('name', 'Test')
            ->set('description', str_repeat('A', 501))
            ->call('save')
            ->assertHasErrors('description');
    }

    public function test_max_days_per_year_must_be_between_1_and_365(): void
    {
        $this->actingAs($this->owner);

        // 0 is invalid
        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'TEST')
            ->set('name', 'Test')
            ->set('maxDaysPerYear', '0')
            ->call('save')
            ->assertHasErrors('maxDaysPerYear');

        // 366 is invalid
        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'TEST2')
            ->set('name', 'Test2')
            ->set('maxDaysPerYear', '366')
            ->call('save')
            ->assertHasErrors('maxDaysPerYear');
    }

    public function test_code_must_be_unique_per_organization(): void
    {
        LeaveType::create([
            'organization_id' => $this->org1->id,
            'code' => 'VAKANTIE',
            'name' => 'Vakantieverlof',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'VAKANTIE')
            ->set('name', 'Ander vakantie type')
            ->call('save')
            ->assertHasErrors('code');
    }

    public function test_same_code_allowed_in_different_organization(): void
    {
        LeaveType::create([
            'organization_id' => $this->org2->id,
            'code' => 'VAKANTIE',
            'name' => 'Vakantieverlof Org2',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'VAKANTIE')
            ->set('name', 'Vakantieverlof Org1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('leave_types', [
            'organization_id' => $this->org1->id,
            'code' => 'VAKANTIE',
        ]);
    }

    public function test_code_unique_check_excludes_current_on_edit(): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $this->org1->id,
            'code' => 'VAKANTIE',
            'name' => 'Vakantieverlof',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        // Editing the same leave type should not trigger unique violation
        Livewire::test(LeaveTypesManager::class)
            ->call('editLeaveType', $leaveType->id)
            ->set('name', 'Vakantiedagen Nieuw')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);
    }

    // ─── Organisatie-scoping ─────────────────────────────────────────────

    public function test_owner_only_sees_own_organization_leave_types(): void
    {
        LeaveType::create([
            'organization_id' => $this->org1->id,
            'code' => 'ORG1_TYPE',
            'name' => 'Org1 Type',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        LeaveType::create([
            'organization_id' => $this->org2->id,
            'code' => 'ORG2_TYPE',
            'name' => 'Org2 Type',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->assertOk()
            ->assertSee('Org1 Type')
            ->assertDontSee('Org2 Type');
    }

    public function test_cannot_edit_leave_type_from_other_organization(): void
    {
        $otherOrgType = LeaveType::create([
            'organization_id' => $this->org2->id,
            'code' => 'OTHER',
            'name' => 'Other Org Type',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('editLeaveType', $otherOrgType->id)
            ->assertSet('error', 'Verlof-type niet gevonden.')
            ->assertSet('showForm', false);
    }

    public function test_cannot_deactivate_leave_type_from_other_organization(): void
    {
        $otherOrgType = LeaveType::create([
            'organization_id' => $this->org2->id,
            'code' => 'OTHER',
            'name' => 'Other Org Type',
            'counts_towards_balance' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('deactivate', $otherOrgType->id)
            ->assertSet('error', 'Verlof-type niet gevonden.');

        // Type should still be active
        $this->assertDatabaseHas('leave_types', [
            'id' => $otherOrgType->id,
            'is_active' => true,
        ]);
    }

    // ─── Formulier-cancel ────────────────────────────────────────────────

    public function test_cancel_form_resets_state(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LeaveTypesManager::class)
            ->call('openForm')
            ->set('code', 'TEST')
            ->set('name', 'Test')
            ->call('cancelForm')
            ->assertSet('showForm', false)
            ->assertSet('code', '')
            ->assertSet('name', '')
            ->assertSet('editingId', null);
    }
}
