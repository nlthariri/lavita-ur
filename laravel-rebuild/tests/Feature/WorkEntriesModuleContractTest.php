<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkEntriesModuleContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $boekhouder;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Test BV']);
        $this->team = Team::create(['organization_id' => $this->org->id, 'name' => 'Team A']);

        $this->owner = User::create([
            'name' => 'Owner User', 'email' => 'owner@test.nl', 'password' => bcrypt('secret'),
            'organization_id' => $this->org->id, 'role' => 'owner', 'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager User', 'email' => 'manager@test.nl', 'password' => bcrypt('secret'),
            'organization_id' => $this->org->id, 'team_id' => $this->team->id,
            'role' => 'manager', 'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee User', 'email' => 'emp@test.nl', 'password' => bcrypt('secret'),
            'organization_id' => $this->org->id, 'team_id' => $this->team->id,
            'role' => 'employee', 'is_active' => true,
        ]);

        $this->boekhouder = User::create([
            'name' => 'Boekhouder User', 'email' => 'boekhouder@test.nl', 'password' => bcrypt('secret'),
            'organization_id' => $this->org->id,
            'role' => 'boekhouder', 'is_active' => true,
        ]);

        $this->token = $this->createBearerToken($this->owner);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_owner_can_create_work_entry_and_entry_is_directly_finalized(): void
    {
        $response = $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'pause_minutes' => 60,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('is_finalized', true)
            ->assertJsonStructure(['id', 'employee_id', 'entry_date', 'start_at', 'end_at', 'net_minutes', 'is_finalized']);

        $this->assertSame(480, $response->json('net_minutes'));
        $this->assertDatabaseHas('work_entries', ['employee_id' => $this->employee->id, 'is_finalized' => 1]);
        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'user_id' => $this->employee->id,
            'recipient' => $this->employee->email,
            'type' => 'work_entry_finalized',
            'status' => 'queued',
        ]);
    }

    public function test_net_minutes_is_gross_minus_pause(): void
    {
        $response = $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '12:30',
            'pause_minutes' => 30,
        ]);

        $response->assertStatus(201);
        $this->assertSame(240, $response->json('net_minutes'));
    }

    public function test_long_shift_requires_minimum_60_minutes_pause(): void
    {
        $response = $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '07:00',
            'end_time' => '13:30',
            'pause_minutes' => 30,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.pause_minutes.0', 'Bij meer dan 5,5 uur werken is minimaal 60 minuten pauze verplicht.');
    }

    public function test_manager_can_only_register_entries_for_own_team(): void
    {
        $otherTeam = Team::create(['organization_id' => $this->org->id, 'name' => 'Team B']);
        $otherEmployee = User::create([
            'name' => 'Other Emp', 'email' => 'other@test.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'team_id' => $otherTeam->id,
            'role' => 'employee', 'is_active' => true,
        ]);

        $response = $this->postWithAuth($this->manager, '/api/internal/work-entries', [
            'employee_id' => $otherEmployee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.employee_id.0', 'Manager mag alleen uren registreren voor eigen team.');
    }

    public function test_employee_role_cannot_register_entries(): void
    {
        $response = $this->postWithAuth($this->employee, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.registrar.0', 'Alleen eigenaar of manager mag uren registreren.');
    }

    public function test_get_work_entries_returns_only_own_organization(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-14',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ])->assertStatus(201);

        $response = $this->getWithAuth($this->owner, '/api/internal/work-entries');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'count'])
            ->assertJsonPath('count', 1);
    }

    public function test_manager_list_is_scoped_to_own_team(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-14',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ])->assertStatus(201);

        $otherTeam = Team::create(['organization_id' => $this->org->id, 'name' => 'Team C']);
        $otherEmp = User::create([
            'name' => 'Other', 'email' => 'oc@test.nl',
            'password' => bcrypt('x'), 'organization_id' => $this->org->id,
            'team_id' => $otherTeam->id, 'role' => 'employee', 'is_active' => true,
        ]);
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $otherEmp->id,
            'team_id' => $otherTeam->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-14',
            'start_at' => '2026-05-14 06:00:00',
            'end_at' => '2026-05-14 10:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 240,
            'is_finalized' => true,
        ]);

        $response = $this->getWithAuth($this->manager, '/api/internal/work-entries');

        $response->assertStatus(200)
            ->assertJsonPath('count', 1);
    }

    public function test_post_requires_all_mandatory_fields(): void
    {
        $response = $this->postWithAuth($this->owner, '/api/internal/work-entries', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id', 'entry_date', 'start_time', 'end_time', 'pause_minutes']);
    }

    public function test_spoofed_registrar_id_is_ignored_and_bearer_user_remains_leading(): void
    {
        $response = $this->postWithAuth($this->employee, '/api/internal/work-entries', [
            'registrar_id' => $this->owner->id,
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.registrar.0', 'Alleen eigenaar of manager mag uren registreren.');
    }

    public function test_weekly_atw_warning_dispatches_alert_emails_to_owner_and_manager(): void
    {
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-12',
            'start_at' => '2026-05-12 06:00:00',
            'end_at' => '2026-05-12 17:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 660,
            'is_finalized' => true,
        ]);

        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-13',
            'start_at' => '2026-05-13 06:00:00',
            'end_at' => '2026-05-13 17:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 660,
            'is_finalized' => true,
        ]);

        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-14',
            'start_at' => '2026-05-14 06:00:00',
            'end_at' => '2026-05-14 17:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 660,
            'is_finalized' => true,
        ]);

        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-15',
            'start_at' => '2026-05-15 06:00:00',
            'end_at' => '2026-05-15 17:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 660,
            'is_finalized' => true,
        ]);

        $response = $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-16',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'pause_minutes' => 60,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->owner->email,
            'type' => 'atw_weekly_warning',
            'status' => 'queued',
        ]);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->manager->email,
            'type' => 'atw_weekly_warning',
            'status' => 'queued',
        ]);

        $this->assertDatabaseHas('atw_violations', [
            'organization_id' => $this->org->id,
            'user_id' => $this->employee->id,
            'violation_type' => 'WEEKLY_WARNING',
            'severity' => 'warning',
        ]);
    }

    public function test_boekhouder_cannot_register_work_entries(): void
    {
        $response = $this->postWithAuth($this->boekhouder, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Boekhouder heeft alleen read-only rapportage toegang.');
    }

    // ─── Regressietests: ATW critical → employee, cross-org isolatie ─────────

    public function test_atw_daily_limit_critical_dispatches_email_to_employee_as_well(): void
    {
        // 13 uur bruto - 60 min pauze = 720 min netto = exact daglimiet (critical)
        $response = $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-19',
            'start_time' => '06:00',
            'end_time' => '19:00',   // 780 min bruto
            'pause_minutes' => 60,   // 720 min netto = daglimiet
        ]);

        $response->assertStatus(201);

        // DAILY_LIMIT is critical → e-mail moet ook naar employee gaan
        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->employee->email,
            'type' => 'atw_daily_limit',
            'status' => 'queued',
        ]);

        // Owner en manager ontvangen ook het signaal
        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->owner->email,
            'type' => 'atw_daily_limit',
            'status' => 'queued',
        ]);
    }

    public function test_cross_org_work_entry_blocked_for_manager(): void
    {
        $orgB = \App\Models\Organization::create(['name' => 'Org B']);
        $teamB = \App\Models\Team::create(['organization_id' => $orgB->id, 'name' => 'Team B']);
        $employeeB = \App\Models\User::create([
            'name' => 'Emp B', 'email' => 'emp-b@test.nl', 'password' => bcrypt('x'),
            'organization_id' => $orgB->id, 'team_id' => $teamB->id,
            'role' => 'employee', 'is_active' => true,
        ]);

        // Manager van org A probeert uren te registreren voor employee van org B
        $response = $this->postWithAuth($this->manager, '/api/internal/work-entries', [
            'employee_id' => $employeeB->id,
            'entry_date' => '2026-05-18',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);
    }

    public function test_cross_org_owner_cannot_see_foreign_work_entries(): void
    {
        $orgB = \App\Models\Organization::create(['name' => 'Org B']);
        $teamB = \App\Models\Team::create(['organization_id' => $orgB->id, 'name' => 'Team B']);
        $ownerB = \App\Models\User::create([
            'name' => 'Owner B', 'email' => 'owner-b@test.nl', 'password' => bcrypt('x'),
            'organization_id' => $orgB->id, 'role' => 'owner', 'is_active' => true,
        ]);
        $empB = \App\Models\User::create([
            'name' => 'EmpB', 'email' => 'empl-b@test.nl', 'password' => bcrypt('x'),
            'organization_id' => $orgB->id, 'team_id' => $teamB->id,
            'role' => 'employee', 'is_active' => true,
        ]);

        // Maak een werkregel in org A
        $this->postWithAuth($this->owner, '/api/internal/work-entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-18',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'pause_minutes' => 0,
        ])->assertStatus(201);

        // Owner van org B mag die niet zien
        $response = $this->getWithAuth($ownerB, '/api/internal/work-entries');
        $response->assertStatus(200)
            ->assertJsonPath('count', 0);
    }
}
