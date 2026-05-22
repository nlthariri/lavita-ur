<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $team;

    private User $owner;

    private User $employee;

    private LeaveBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Test BV']);
        $this->team = Team::create(['organization_id' => $this->org->id, 'name' => 'Team A']);

        $this->owner = User::create([
            'name' => 'Owner User',
            'email' => 'owner@test.nl',
            'password' => bcrypt('secret'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee User',
            'email' => 'emp@test.nl',
            'password' => bcrypt('secret'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->service = new LeaveBalanceService;
    }

    public function test_get_balance_returns_unconfigured_when_annual_leave_days_is_null(): void
    {
        $balance = $this->service->getBalance($this->employee->id, 2026);

        $this->assertNull($balance['annual_days']);
        $this->assertSame(0.0, $balance['taken_days']);
        $this->assertNull($balance['remaining_days']);
        $this->assertSame('unconfigured', $balance['status']);
        $this->assertSame([], $balance['breakdown']);
    }

    public function test_calculate_taken_days_counts_full_day_leave_as_one(): void
    {
        $this->createLeaveEntry($this->employee, '2026-03-10', '00:00:00', '23:59:00');

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(1.0, $taken);
    }

    public function test_calculate_taken_days_counts_morning_half_day_as_half(): void
    {
        // Ochtend half-dag: start=00:00, end=12:30 (Amsterdam)
        $this->createLeaveEntry($this->employee, '2026-03-10', '00:00:00', '12:30:00');

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(0.5, $taken);
    }

    public function test_calculate_taken_days_counts_afternoon_half_day_as_half(): void
    {
        // Middag half-dag: start=12:30, end=23:59 (Amsterdam)
        $this->createLeaveEntry($this->employee, '2026-03-10', '12:30:00', '23:59:00');

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(0.5, $taken);
    }

    public function test_calculate_taken_days_sums_multiple_entries(): void
    {
        // 2 full days + 1 half day = 2.5
        $this->createLeaveEntry($this->employee, '2026-03-10', '00:00:00', '23:59:00');
        $this->createLeaveEntry($this->employee, '2026-03-11', '00:00:00', '23:59:00');
        $this->createLeaveEntry($this->employee, '2026-03-12', '00:00:00', '12:30:00');

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(2.5, $taken);
    }

    public function test_calculate_taken_days_ignores_non_finalized_entries(): void
    {
        $this->createLeaveEntry($this->employee, '2026-03-10', '00:00:00', '23:59:00', isFinalized: false);

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(0.0, $taken);
    }

    public function test_calculate_taken_days_ignores_soft_deleted_entries(): void
    {
        $entry = $this->createLeaveEntry($this->employee, '2026-03-10', '00:00:00', '23:59:00');
        $entry->delete(); // soft-delete

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(0.0, $taken);
    }

    public function test_calculate_taken_days_ignores_non_leave_types(): void
    {
        // WORK type should not count
        $startAt = Carbon::createFromFormat('Y-m-d H:i:s', '2026-03-10 08:00:00', 'Europe/Amsterdam')->utc();
        $endAt = Carbon::createFromFormat('Y-m-d H:i:s', '2026-03-10 16:00:00', 'Europe/Amsterdam')->utc();

        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-03-10',
            'start_at' => $startAt,
            'end_at' => $endAt,
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'type' => 'WORK',
            'is_finalized' => true,
        ]);

        // SICK type should not count
        $startAt2 = Carbon::createFromFormat('Y-m-d H:i:s', '2026-03-11 00:00:00', 'Europe/Amsterdam')->utc();
        $endAt2 = Carbon::createFromFormat('Y-m-d H:i:s', '2026-03-11 23:59:00', 'Europe/Amsterdam')->utc();

        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-03-11',
            'start_at' => $startAt2,
            'end_at' => $endAt2,
            'pause_minutes' => 0,
            'net_minutes' => 0,
            'type' => 'SICK',
            'is_finalized' => true,
        ]);

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(0.0, $taken);
    }

    public function test_calculate_taken_days_only_counts_entries_in_specified_year(): void
    {
        // Entry in 2025 should not count for 2026
        $this->createLeaveEntry($this->employee, '2025-12-20', '00:00:00', '23:59:00');
        // Entry in 2026 should count
        $this->createLeaveEntry($this->employee, '2026-01-05', '00:00:00', '23:59:00');

        $taken = $this->service->calculateTakenDays($this->employee->id, 2026);

        $this->assertSame(1.0, $taken);
    }

    public function test_get_balance_status_ok_when_remaining_above_3(): void
    {
        // Simulate annual_leave_days by updating the user directly in DB
        $this->employee->forceFill(['annual_leave_days' => 25])->save();

        // Take 5 days of leave
        for ($i = 1; $i <= 5; $i++) {
            $this->createLeaveEntry($this->employee, "2026-03-{$i}", '00:00:00', '23:59:00');
        }

        $balance = $this->service->getBalance($this->employee->id, 2026);

        $this->assertSame(25, $balance['annual_days']);
        $this->assertSame(5.0, $balance['taken_days']);
        $this->assertSame(20.0, $balance['remaining_days']);
        $this->assertSame('ok', $balance['status']);
    }

    public function test_get_balance_status_warning_when_remaining_is_3_or_less(): void
    {
        $this->employee->forceFill(['annual_leave_days' => 5])->save();

        // Take 3 days → remaining = 2
        for ($i = 1; $i <= 3; $i++) {
            $this->createLeaveEntry($this->employee, "2026-03-0{$i}", '00:00:00', '23:59:00');
        }

        $balance = $this->service->getBalance($this->employee->id, 2026);

        $this->assertSame(2.0, $balance['remaining_days']);
        $this->assertSame('warning', $balance['status']);
    }

    public function test_get_balance_status_warning_when_remaining_is_exactly_3(): void
    {
        $this->employee->forceFill(['annual_leave_days' => 5])->save();

        // Take 2 days → remaining = 3
        $this->createLeaveEntry($this->employee, '2026-03-01', '00:00:00', '23:59:00');
        $this->createLeaveEntry($this->employee, '2026-03-02', '00:00:00', '23:59:00');

        $balance = $this->service->getBalance($this->employee->id, 2026);

        $this->assertSame(3.0, $balance['remaining_days']);
        $this->assertSame('warning', $balance['status']);
    }

    public function test_get_balance_status_danger_when_remaining_is_zero(): void
    {
        $this->employee->forceFill(['annual_leave_days' => 2])->save();

        $this->createLeaveEntry($this->employee, '2026-03-01', '00:00:00', '23:59:00');
        $this->createLeaveEntry($this->employee, '2026-03-02', '00:00:00', '23:59:00');

        $balance = $this->service->getBalance($this->employee->id, 2026);

        $this->assertSame(0.0, $balance['remaining_days']);
        $this->assertSame('danger', $balance['status']);
    }

    public function test_get_balance_status_danger_when_remaining_is_negative(): void
    {
        $this->employee->forceFill(['annual_leave_days' => 1])->save();

        $this->createLeaveEntry($this->employee, '2026-03-01', '00:00:00', '23:59:00');
        $this->createLeaveEntry($this->employee, '2026-03-02', '00:00:00', '23:59:00');

        $balance = $this->service->getBalance($this->employee->id, 2026);

        $this->assertSame(-1.0, $balance['remaining_days']);
        $this->assertSame('danger', $balance['status']);
    }

    public function test_get_balance_breakdown_groups_by_type_name(): void
    {
        // Without leave_types table, all entries should be grouped under 'Verlof'
        $this->createLeaveEntry($this->employee, '2026-03-01', '00:00:00', '23:59:00');
        $this->createLeaveEntry($this->employee, '2026-03-02', '00:00:00', '12:30:00');

        $balance = $this->service->getBalance($this->employee->id, 2026);

        $this->assertSame(['Verlof' => 1.5], $balance['breakdown']);
    }

    public function test_get_balance_for_nonexistent_user_returns_unconfigured(): void
    {
        $balance = $this->service->getBalance(99999, 2026);

        $this->assertNull($balance['annual_days']);
        $this->assertSame(0.0, $balance['taken_days']);
        $this->assertNull($balance['remaining_days']);
        $this->assertSame('unconfigured', $balance['status']);
    }

    /**
     * Helper: maak een LEAVE work entry aan.
     * Times are specified in Amsterdam timezone and stored as UTC.
     */
    private function createLeaveEntry(
        User $employee,
        string $date,
        string $startTime,
        string $endTime,
        bool $isFinalized = true,
    ): WorkEntry {
        $startAt = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $date.' '.$startTime,
            'Europe/Amsterdam'
        )->utc();

        $endAt = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $date.' '.$endTime,
            'Europe/Amsterdam'
        )->utc();

        return WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $employee->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $date,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'pause_minutes' => 0,
            'net_minutes' => 0,
            'type' => 'LEAVE',
            'is_finalized' => $isFinalized,
        ]);
    }
}
