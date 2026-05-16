<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemJobRun;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingInputReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_reminder_for_missing_team_entries(): void
    {
        Carbon::setTestNow('2026-05-17 09:00:00');

        $org = Organization::create(['name' => 'Reminder BV']);
        $team = Team::create(['organization_id' => $org->id, 'name' => 'Team A']);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@reminder.nl',
            'password' => bcrypt('x'),
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $employeeA = User::create([
            'name' => 'Employee A',
            'email' => 'employee-a@reminder.nl',
            'password' => bcrypt('x'),
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $employeeB = User::create([
            'name' => 'Employee B',
            'email' => 'employee-b@reminder.nl',
            'password' => bcrypt('x'),
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        WorkEntry::create([
            'organization_id' => $org->id,
            'employee_id' => $employeeA->id,
            'team_id' => $team->id,
            'registered_by_id' => $manager->id,
            'entry_date' => '2026-05-16',
            'start_at' => '2026-05-16 08:00:00',
            'end_at' => '2026-05-16 16:00:00',
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'is_finalized' => true,
        ]);

        $this->artisan('reminder:pending-input', ['--days' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $org->id,
            'user_id' => $manager->id,
            'recipient' => $manager->email,
            'type' => 'reminder_open_entries',
            'status' => 'queued',
        ]);

        $jobRun = SystemJobRun::query()
            ->where('job_name', 'reminder.pending_input')
            ->latest('id')
            ->first();

        $this->assertNotNull($jobRun);
        $this->assertSame('completed', $jobRun->status);
        $this->assertSame(1, $jobRun->rows_affected);

        Carbon::setTestNow();
    }

    public function test_command_dry_run_writes_evidence_without_dispatch(): void
    {
        Carbon::setTestNow('2026-05-17 09:00:00');

        $org = Organization::create(['name' => 'Dry Run BV']);
        $team = Team::create(['organization_id' => $org->id, 'name' => 'Team B']);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager-dry@reminder.nl',
            'password' => bcrypt('x'),
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Employee C',
            'email' => 'employee-c@reminder.nl',
            'password' => bcrypt('x'),
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->artisan('reminder:pending-input', ['--days' => 1, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('email_outbox', [
            'organization_id' => $org->id,
            'user_id' => $manager->id,
            'type' => 'reminder_open_entries',
        ]);

        $jobRun = SystemJobRun::query()
            ->where('job_name', 'reminder.pending_input')
            ->latest('id')
            ->first();

        $this->assertNotNull($jobRun);
        $this->assertSame('completed', $jobRun->status);

        Carbon::setTestNow();
    }
}
