<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AtwViolation;
use App\Models\AuditEvent;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\DashboardAggregationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-tests voor DashboardAggregationService (taak 3.1 spec lavita-urenregistratie).
 *
 * Valideert:
 *  - KPI-berekeningen (total_hours, attendance, pending_leave, atw, objections, sick)
 *  - Scope-filtering (manager=team, owner=organisatie, owner+teamFilter)
 *  - Chart-data structuur (per team voor owner, totaal voor manager)
 *  - Activity feed (laatste 10, gesorteerd op created_at desc)
 *
 * Requirements: 1.2, 1.3, 1.4, 1.8
 */
final class DashboardAggregationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private Team $teamA;

    private Team $teamB;

    private User $owner;

    private User $manager;

    private User $employeeA1;

    private User $employeeA2;

    private User $employeeB1;

    private User $sentinelEmployee;

    private DashboardAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DashboardAggregationService::class);

        $this->org = Organization::create(['name' => 'Test Org']);
        $this->otherOrg = Organization::create(['name' => 'Andere Org']);

        $this->owner = User::create([
            'name' => 'Owner',
            'full_name' => 'Test Owner',
            'email' => 'owner-das@test.nl',
            'password' => bcrypt('password'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->teamA = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Alpha',
            'manager_id' => null,
        ]);

        $this->teamB = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Beta',
            'manager_id' => null,
        ]);

        $this->manager = User::create([
            'name' => 'Manager A',
            'full_name' => 'Manager Alpha',
            'email' => 'manager-das@test.nl',
            'password' => bcrypt('password'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamA->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->teamA->update(['manager_id' => $this->manager->id]);

        $this->employeeA1 = User::create([
            'name' => 'Emp A1',
            'full_name' => 'Employee Alpha 1',
            'email' => 'emp-a1-das@test.nl',
            'password' => bcrypt('password'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamA->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employeeA2 = User::create([
            'name' => 'Emp A2',
            'full_name' => 'Employee Alpha 2',
            'email' => 'emp-a2-das@test.nl',
            'password' => bcrypt('password'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamA->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employeeB1 = User::create([
            'name' => 'Emp B1',
            'full_name' => 'Employee Beta 1',
            'email' => 'emp-b1-das@test.nl',
            'password' => bcrypt('password'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamB->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Sentinel in andere organisatie — mag nooit meetellen
        $this->sentinelEmployee = User::create([
            'name' => 'Sentinel',
            'full_name' => 'Sentinel Other',
            'email' => 'sentinel-das@test.nl',
            'password' => bcrypt('password'),
            'organization_id' => $this->otherOrg->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    private function seedWorkEntry(
        User $employee,
        string $date,
        int $netMinutes = 480,
        string $type = 'WORK',
        bool $isFinalized = true,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
    ): WorkEntry {
        return WorkEntry::create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'team_id' => $employee->team_id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $date,
            'start_at' => $date.' '.$startTime,
            'end_at' => $date.' '.$endTime,
            'pause_minutes' => 30,
            'net_minutes' => $netMinutes,
            'type' => $type,
            'is_finalized' => $isFinalized,
        ]);
    }

    // ─── total_hours_this_week ───────────────────────────────────────

    public function test_total_hours_this_week_sums_net_minutes(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->seedWorkEntry($this->employeeA1, $monday, 480);
        $this->seedWorkEntry($this->employeeA2, $monday, 360);

        $result = $this->service->getKpiData($this->owner);

        $this->assertSame(840, $result['total_hours_this_week']);
    }

    public function test_total_hours_excludes_soft_deleted_entries(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $entry = $this->seedWorkEntry($this->employeeA1, $monday, 480);
        $entry->delete(); // soft-delete

        $result = $this->service->getKpiData($this->owner);

        $this->assertSame(0, $result['total_hours_this_week']);
    }

    public function test_total_hours_prev_week_is_separate(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY);
        $prevMonday = $monday->copy()->subWeek()->toDateString();
        $currentMonday = $monday->toDateString();

        $this->seedWorkEntry($this->employeeA1, $prevMonday, 400);
        $this->seedWorkEntry($this->employeeA1, $currentMonday, 480);

        $result = $this->service->getKpiData($this->owner);

        $this->assertSame(480, $result['total_hours_this_week']);
        $this->assertSame(400, $result['total_hours_prev_week']);
    }

    // ─── attendance_percentage ────────────────────────────────────────

    public function test_attendance_percentage_counts_distinct_employees(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        // Only employeeA1 has an entry this week
        $this->seedWorkEntry($this->employeeA1, $monday, 480);

        $result = $this->service->getKpiData($this->owner);

        // Total employees in scope: owner + manager + empA1 + empA2 + empB1 = 5
        // Present: 1
        // Percentage: floor(1/5 * 100) = 20
        $this->assertSame(20, $result['attendance_percentage']);
    }

    // ─── pending_leave_count ─────────────────────────────────────────

    public function test_pending_leave_count(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        // Pending leave (is_finalized=false)
        $this->seedWorkEntry($this->employeeA1, $monday, 0, 'LEAVE', false, '00:00:00', '23:59:00');

        // Approved leave (is_finalized=true) — should NOT count
        $tuesday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->addDay()->toDateString();
        $this->seedWorkEntry($this->employeeA2, $tuesday, 0, 'LEAVE', true, '00:00:00', '23:59:00');

        $result = $this->service->getKpiData($this->owner);

        $this->assertSame(1, $result['pending_leave_count']);
    }

    // ─── atw counts ──────────────────────────────────────────────────

    public function test_atw_counts_distinct_users(): void
    {
        AtwViolation::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->employeeA1->id,
            'violation_type' => 'DAILY_LIMIT',
            'severity' => 'critical',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'current_minutes' => 1,
            'threshold_minutes' => 1,
        ]);

        AtwViolation::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->employeeA2->id,
            'violation_type' => 'WEEKLY_WARNING',
            'severity' => 'warning',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'current_minutes' => 1,
            'threshold_minutes' => 1,
        ]);

        // Superseded violation — should NOT count
        AtwViolation::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->employeeB1->id,
            'violation_type' => 'REST_PERIOD',
            'severity' => 'critical',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'current_minutes' => 1,
            'threshold_minutes' => 1,
            'superseded_at' => now(),
        ]);

        $result = $this->service->getKpiData($this->owner);

        $this->assertSame(1, $result['atw_critical_count']);
        $this->assertSame(1, $result['atw_warning_count']);
    }

    // ─── open_objections_count ───────────────────────────────────────

    public function test_open_objections_count(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedWorkEntry($this->employeeA1, $monday);

        Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $this->employeeA1->id,
            'motivation' => 'Test bezwaar',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $this->employeeA1->id,
            'motivation' => 'Goedgekeurd bezwaar',
            'status' => 'APPROVED',
            'submitted_at' => now(),
        ]);

        $result = $this->service->getKpiData($this->owner);

        $this->assertSame(1, $result['open_objections_count']);
    }

    // ─── sick_percentage ─────────────────────────────────────────────

    public function test_sick_percentage(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->seedWorkEntry($this->employeeA1, $monday, 0, 'SICK', true, '00:00:00', '23:59:00');

        $result = $this->service->getKpiData($this->owner);

        // 1 sick employee out of 5 total = 20.0%
        $this->assertSame(20.0, $result['sick_percentage']);
    }

    // ─── scope filtering ─────────────────────────────────────────────

    public function test_manager_scope_only_sees_own_team(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        // Entry in team A (manager's team)
        $this->seedWorkEntry($this->employeeA1, $monday, 480);

        // Entry in team B (not manager's team)
        $this->seedWorkEntry($this->employeeB1, $monday, 360);

        $result = $this->service->getKpiData($this->manager);

        // Manager should only see team A's hours (480)
        $this->assertSame(480, $result['total_hours_this_week']);
    }

    public function test_owner_with_team_filter(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->seedWorkEntry($this->employeeA1, $monday, 480);
        $this->seedWorkEntry($this->employeeB1, $monday, 360);

        // Owner with team filter on team B
        $result = $this->service->getKpiData($this->owner, $this->teamB->id);

        // Should only see team B's hours (360)
        $this->assertSame(360, $result['total_hours_this_week']);
    }

    public function test_owner_without_filter_sees_all_teams(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->seedWorkEntry($this->employeeA1, $monday, 480);
        $this->seedWorkEntry($this->employeeB1, $monday, 360);

        $result = $this->service->getKpiData($this->owner);

        // Owner sees all: 480 + 360 = 840
        $this->assertSame(840, $result['total_hours_this_week']);
    }

    public function test_other_org_data_never_leaks(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        // Entry in other org
        WorkEntry::create([
            'organization_id' => $this->otherOrg->id,
            'employee_id' => $this->sentinelEmployee->id,
            'team_id' => null,
            'registered_by_id' => $this->sentinelEmployee->id,
            'entry_date' => $monday,
            'start_at' => $monday.' 08:00:00',
            'end_at' => $monday.' 16:00:00',
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'type' => 'WORK',
            'is_finalized' => true,
        ]);

        $result = $this->service->getKpiData($this->owner);

        // Should not include other org's data
        $this->assertSame(0, $result['total_hours_this_week']);
    }

    // ─── chart_data ──────────────────────────────────────────────────

    public function test_chart_data_structure_for_owner(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->seedWorkEntry($this->employeeA1, $monday, 480);

        $result = $this->service->getKpiData($this->owner);

        // Owner without filter gets data grouped by team
        $this->assertIsArray($result['chart_data']);
        $this->assertArrayHasKey('Team Alpha', $result['chart_data']);
        $this->assertSame(480, $result['chart_data']['Team Alpha']['ma']);
    }

    public function test_chart_data_structure_for_manager(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->seedWorkEntry($this->employeeA1, $monday, 480);

        $result = $this->service->getKpiData($this->manager);

        // Manager gets total per day
        $this->assertIsArray($result['chart_data']);
        $this->assertArrayHasKey('Totaal', $result['chart_data']);
        $this->assertSame(480, $result['chart_data']['Totaal']['ma']);
    }

    public function test_chart_data_has_all_days(): void
    {
        $result = $this->service->getKpiData($this->owner);

        $days = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
        foreach ($result['chart_data'] as $series) {
            foreach ($days as $day) {
                $this->assertArrayHasKey($day, $series);
            }
        }
    }

    // ─── activity_feed ───────────────────────────────────────────────

    public function test_activity_feed_returns_last_10(): void
    {
        // Create 12 audit events
        for ($i = 1; $i <= 12; $i++) {
            AuditEvent::create([
                'organization_id' => $this->org->id,
                'actor_id' => $this->employeeA1->id,
                'action' => 'WORK_ENTRY_CREATED',
                'target_type' => 'work_entry',
                'target_id' => $i,
                'created_at' => now()->subMinutes(12 - $i),
            ]);
        }

        $result = $this->service->getKpiData($this->owner);

        $this->assertCount(10, $result['activity_feed']);
    }

    public function test_activity_feed_sorted_desc(): void
    {
        // Insert directly via DB to control created_at precisely
        $older = Carbon::now()->subHours(2);
        $newer = Carbon::now()->subMinutes(5);

        \Illuminate\Support\Facades\DB::table('audit_events')->insert([
            'organization_id' => $this->org->id,
            'actor_id' => $this->employeeA1->id,
            'action' => 'WORK_ENTRY_CREATED',
            'target_type' => 'work_entry',
            'target_id' => 1,
            'created_at' => $older,
        ]);

        \Illuminate\Support\Facades\DB::table('audit_events')->insert([
            'organization_id' => $this->org->id,
            'actor_id' => $this->employeeA2->id,
            'action' => 'LEAVE_REQUESTED',
            'target_type' => 'work_entry',
            'target_id' => 2,
            'created_at' => $newer,
        ]);

        $result = $this->service->getKpiData($this->owner);

        $this->assertCount(2, $result['activity_feed']);
        $this->assertSame('LEAVE_REQUESTED', $result['activity_feed'][0]['action']);
        $this->assertSame('WORK_ENTRY_CREATED', $result['activity_feed'][1]['action']);
    }

    public function test_activity_feed_has_description(): void
    {
        AuditEvent::create([
            'organization_id' => $this->org->id,
            'actor_id' => $this->employeeA1->id,
            'action' => 'WORK_ENTRY_CREATED',
            'target_type' => 'work_entry',
            'target_id' => 1,
            'created_at' => now(),
        ]);

        $result = $this->service->getKpiData($this->owner);

        $this->assertCount(1, $result['activity_feed']);
        $this->assertStringContains('uren ingevoerd', $result['activity_feed'][0]['description']);
    }

    // ─── return structure ────────────────────────────────────────────

    public function test_return_structure_has_all_keys(): void
    {
        $result = $this->service->getKpiData($this->owner);

        $expectedKeys = [
            'total_hours_this_week',
            'total_hours_prev_week',
            'attendance_percentage',
            'pending_leave_count',
            'atw_critical_count',
            'atw_warning_count',
            'open_objections_count',
            'sick_percentage',
            'chart_data',
            'activity_feed',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    /**
     * Custom assertion for string contains (case-insensitive).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains(strtolower($haystack), strtolower($needle)),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
