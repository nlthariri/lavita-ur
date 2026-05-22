<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Accounts;

use App\Livewire\Accounts\AccountForm;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor verlof-saldo configuratie in AccountForm (taak 16.1).
 *
 * Dekt:
 *  - Owner/manager kan annual_leave_days instellen per medewerker.
 *  - Validatie: nullable, integer, min:0, max:365.
 *  - Audit-event LEAVE_ALLOWANCE_UPDATED bij wijziging.
 *  - Saldo-overzicht (recht, opgenomen, resterend) bij medewerker-details.
 *  - Widget verborgen wanneer annual_leave_days null is.
 *
 * Requirements: 9.1, 9.2, 9.5, 9.6, 9.7, 9.8, 9.10
 */
final class AccountFormLeaveConfigTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $team;

    private User $owner;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'LaVita Leave Config Org']);

        $this->owner = User::create([
            'name' => 'Owner',
            'full_name' => 'Owner Leave',
            'email' => 'owner-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager',
            'full_name' => 'Manager Leave',
            'email' => 'mgr-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->team = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Leave',
            'manager_id' => $this->manager->id,
        ]);

        $this->manager->update(['team_id' => $this->team->id]);

        $this->employee = User::create([
            'name' => 'Employee',
            'full_name' => 'Employee Leave',
            'email' => 'emp-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
            'annual_leave_days' => null,
        ]);
    }

    public function test_owner_can_set_annual_leave_days(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id)
            ->assertSet('annualLeaveDays', null)
            ->set('annualLeaveDays', 25)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('account-saved');

        $this->employee->refresh();
        $this->assertSame(25, (int) $this->employee->annual_leave_days);
    }

    public function test_manager_can_set_annual_leave_days_for_own_team(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id)
            ->set('annualLeaveDays', 20)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('account-saved');

        $this->employee->refresh();
        $this->assertSame(20, (int) $this->employee->annual_leave_days);
    }

    public function test_annual_leave_days_can_be_set_to_null(): void
    {
        $this->employee->update(['annual_leave_days' => 25]);
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id)
            ->assertSet('annualLeaveDays', 25)
            ->set('annualLeaveDays', null)
            ->call('submit')
            ->assertHasNoErrors();

        $this->employee->refresh();
        $this->assertNull($this->employee->annual_leave_days);
    }

    public function test_annual_leave_days_validation_rejects_negative(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id)
            ->set('annualLeaveDays', -1)
            ->call('submit')
            ->assertHasErrors(['annualLeaveDays']);
    }

    public function test_annual_leave_days_validation_rejects_over_365(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id)
            ->set('annualLeaveDays', 366)
            ->call('submit')
            ->assertHasErrors(['annualLeaveDays']);
    }

    public function test_audit_event_leave_allowance_updated_on_change(): void
    {
        $this->employee->update(['annual_leave_days' => 20]);
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id)
            ->set('annualLeaveDays', 25)
            ->call('submit')
            ->assertHasNoErrors();

        // Verify LEAVE_ALLOWANCE_UPDATED audit event was created
        $auditEvent = AuditEvent::where('action', 'LEAVE_ALLOWANCE_UPDATED')
            ->where('target_id', (string) $this->employee->id)
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertSame((int) $this->owner->id, (int) $auditEvent->actor_id);
        $this->assertSame((int) $this->org->id, (int) $auditEvent->organization_id);
        $this->assertSame('user', $auditEvent->target_type);

        $beforeData = $auditEvent->before_data;
        $afterData = $auditEvent->after_data;

        $this->assertSame(20, $beforeData['annual_leave_days']);
        $this->assertSame(25, $afterData['annual_leave_days']);
    }

    public function test_no_audit_event_when_leave_allowance_unchanged(): void
    {
        $this->employee->update(['annual_leave_days' => 25]);
        $this->actingAs($this->owner);

        Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id)
            ->set('annualLeaveDays', 25) // Same value
            ->call('submit')
            ->assertHasNoErrors();

        // No LEAVE_ALLOWANCE_UPDATED event should exist
        $auditEvent = AuditEvent::where('action', 'LEAVE_ALLOWANCE_UPDATED')
            ->where('target_id', (string) $this->employee->id)
            ->first();

        $this->assertNull($auditEvent);
    }

    public function test_leave_balance_shown_when_configured(): void
    {
        $this->employee->update(['annual_leave_days' => 25]);

        // Create some leave entries for the employee
        WorkEntry::create([
            'employee_id' => $this->employee->id,
            'organization_id' => $this->org->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => now()->startOfYear()->addDays(10)->toDateString(),
            'start_at' => '09:00',
            'end_at' => '17:00',
            'pause_minutes' => 30,
            'net_minutes' => 0,
            'type' => 'LEAVE',
            'is_finalized' => true,
        ]);

        $this->actingAs($this->owner);

        $component = Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id);

        // Verify leave balance is loaded
        $component->assertSet('annualLeaveDays', 25);
        $this->assertNotNull($component->get('leaveBalance'));
        $this->assertSame(25, $component->get('leaveBalance')['annual_days']);
        $this->assertSame('ok', $component->get('leaveBalance')['status']);

        // Verify the balance overview is visible in the view
        $component->assertSee('Verlof-saldo');
        $component->assertSee('Recht');
        $component->assertSee('Opgenomen');
        $component->assertSee('Resterend');
    }

    public function test_leave_balance_hidden_when_not_configured(): void
    {
        // annual_leave_days is null
        $this->actingAs($this->owner);

        $component = Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id);

        $this->assertNotNull($component->get('leaveBalance'));
        $this->assertSame('unconfigured', $component->get('leaveBalance')['status']);

        // The balance overview should NOT be visible
        $component->assertDontSee('Verlof-saldo ' . now()->year);
    }

    public function test_leave_balance_shows_warning_status(): void
    {
        $this->employee->update(['annual_leave_days' => 5]);

        // Create 3 leave entries (remaining = 2, which is ≤ 3 → warning)
        for ($i = 0; $i < 3; $i++) {
            WorkEntry::create([
                'employee_id' => $this->employee->id,
                'organization_id' => $this->org->id,
                'registered_by_id' => $this->owner->id,
                'entry_date' => now()->startOfYear()->addDays(10 + $i)->toDateString(),
                'start_at' => '09:00',
                'end_at' => '17:00',
                'pause_minutes' => 30,
                'net_minutes' => 0,
                'type' => 'LEAVE',
                'is_finalized' => true,
            ]);
        }

        $this->actingAs($this->owner);

        $component = Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id);

        $this->assertSame('warning', $component->get('leaveBalance')['status']);
        $component->assertSee('Bijna op');
    }

    public function test_leave_balance_shows_danger_status(): void
    {
        $this->employee->update(['annual_leave_days' => 2]);

        // Create 2 leave entries (remaining = 0 → danger)
        for ($i = 0; $i < 2; $i++) {
            WorkEntry::create([
                'employee_id' => $this->employee->id,
                'organization_id' => $this->org->id,
                'registered_by_id' => $this->owner->id,
                'entry_date' => now()->startOfYear()->addDays(10 + $i)->toDateString(),
                'start_at' => '09:00',
                'end_at' => '17:00',
                'pause_minutes' => 30,
                'net_minutes' => 0,
                'type' => 'LEAVE',
                'is_finalized' => true,
            ]);
        }

        $this->actingAs($this->owner);

        $component = Livewire::test(AccountForm::class)
            ->dispatch('open-account-form', userId: $this->employee->id);

        $this->assertSame('danger', $component->get('leaveBalance')['status']);
        $component->assertSee('Saldo op');
    }
}
