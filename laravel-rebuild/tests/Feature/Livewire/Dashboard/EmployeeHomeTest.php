<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Dashboard;

use App\Livewire\Dashboard\EmployeeHome;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Dashboard\EmployeeHome` (taak 5.1
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Persoonlijke begroeting met naam + datum (Requirement 2.1)
 *  - Progress_Bar "Mijn uren deze week" (Requirement 2.2, 2.10)
 *  - Verlof-saldo Progress_Bar met waarschuwingskleuren (Requirement 2.3, 2.4)
 *  - Verberg widgets wanneer niet geconfigureerd (Requirement 2.10)
 *  - Lijst openstaande bezwaren met status-badge (Requirement 2.5)
 *  - Snelactie-knoppen (Requirement 2.6)
 *  - Mini-weekoverzicht met Color_Coding (Requirement 2.7)
 *  - Skeleton placeholders (Requirement 2.8)
 *  - Notificaties (Requirement 2.9)
 */
final class EmployeeHomeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $team;

    private User $owner;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'LaVita Test Org']);

        $this->owner = User::create([
            'name' => 'Owner',
            'full_name' => 'Olivia Owner',
            'email' => 'owner-eh@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->team = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Alfa',
            'manager_id' => $this->owner->id,
        ]);

        $this->employee = User::create([
            'name' => 'Werknemer',
            'full_name' => 'Jan de Vries',
            'email' => 'jan-eh@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    private function seedWorkEntry(
        User $employee,
        string $isoDate,
        int $netMinutes = 480,
        string $type = 'WORK',
        bool $isFinalized = true,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
        ?int $registeredById = null,
    ): WorkEntry {
        return WorkEntry::create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'team_id' => $employee->team_id,
            'registered_by_id' => $registeredById ?? $employee->id,
            'entry_date' => $isoDate,
            'start_at' => $isoDate . ' ' . $startTime,
            'end_at' => $isoDate . ' ' . $endTime,
            'pause_minutes' => 30,
            'net_minutes' => $netMinutes,
            'type' => $type,
            'is_finalized' => $isFinalized,
        ]);
    }

    // --- Requirement 2.1: Begroeting ---

    public function test_shows_personal_greeting_with_full_name(): void
    {
        $this->actingAs($this->employee);

        Livewire::test(EmployeeHome::class)
            ->assertOk()
            ->assertSet('userFullName', 'Jan de Vries')
            ->assertSee('Jan de Vries');
    }

    public function test_shows_formatted_date_in_dutch(): void
    {
        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        // De datum moet een Nederlands dag-naam bevatten
        $formattedDate = $component->instance()->getFormattedDate();
        $dutchDays = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];
        $containsDutchDay = false;
        foreach ($dutchDays as $day) {
            if (str_contains($formattedDate, $day)) {
                $containsDutchDay = true;
                break;
            }
        }
        $this->assertTrue($containsDutchDay, "Datum '{$formattedDate}' bevat geen Nederlandse dagnaam.");
    }

    // --- Requirement 2.2: Uren deze week ---

    public function test_shows_total_minutes_this_week(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->seedWorkEntry($this->employee, $monday, netMinutes: 480);

        $this->actingAs($this->employee);

        Livewire::test(EmployeeHome::class)
            ->assertOk()
            ->assertSet('totalMinutesThisWeek', 480)
            ->assertSet('daysWorkedThisWeek', 1);
    }

    public function test_excludes_entries_from_other_weeks(): void
    {
        $lastWeek = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->subDays(7)
            ->toDateString();

        $this->seedWorkEntry($this->employee, $lastWeek, netMinutes: 480);

        $this->actingAs($this->employee);

        Livewire::test(EmployeeHome::class)
            ->assertOk()
            ->assertSet('totalMinutesThisWeek', 0);
    }

    // --- Requirement 2.10: Verberg progress bar als geen contracturen ---

    public function test_hides_hours_progress_when_no_contract_hours(): void
    {
        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        // contractMinutesPerWeek should be null (column doesn't exist or is null)
        $this->assertNull($component->get('contractMinutesPerWeek'));
    }

    // --- Requirement 2.3, 2.4: Verlof-saldo ---

    public function test_shows_leave_balance_when_configured(): void
    {
        // Configureer annual_leave_days
        $this->employee->forceFill(['annual_leave_days' => 25])->save();

        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $leaveBalance = $component->get('leaveBalance');
        $this->assertNotNull($leaveBalance);
        $this->assertSame(25, $leaveBalance['annual_days']);
        $this->assertSame('ok', $leaveBalance['status']);
    }

    public function test_hides_leave_balance_when_not_configured(): void
    {
        // annual_leave_days is null (default)
        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $leaveBalance = $component->get('leaveBalance');
        $this->assertSame('unconfigured', $leaveBalance['status']);
    }

    public function test_leave_balance_warning_when_remaining_3_or_less(): void
    {
        $this->employee->forceFill(['annual_leave_days' => 5])->save();

        // Take 3 days of leave
        $dates = ['2026-01-05', '2026-01-06', '2026-01-07'];
        foreach ($dates as $date) {
            $this->seedWorkEntry(
                $this->employee,
                $date,
                netMinutes: 0,
                type: 'LEAVE',
                isFinalized: true,
                startTime: '00:00:00',
                endTime: '23:59:00',
            );
        }

        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $leaveBalance = $component->get('leaveBalance');
        $this->assertSame('warning', $leaveBalance['status']);
    }

    public function test_leave_balance_danger_when_remaining_0_or_less(): void
    {
        $this->employee->forceFill(['annual_leave_days' => 2])->save();

        // Take 2 days of leave
        $this->seedWorkEntry($this->employee, '2026-02-01', netMinutes: 0, type: 'LEAVE', isFinalized: true, startTime: '00:00:00', endTime: '23:59:00');
        $this->seedWorkEntry($this->employee, '2026-02-02', netMinutes: 0, type: 'LEAVE', isFinalized: true, startTime: '00:00:00', endTime: '23:59:00');

        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $leaveBalance = $component->get('leaveBalance');
        $this->assertSame('danger', $leaveBalance['status']);
    }

    // --- Requirement 2.5: Bezwaren ---

    public function test_shows_open_objections_count(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedWorkEntry($this->employee, $monday);

        Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Onjuiste begintijd.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->employee);

        Livewire::test(EmployeeHome::class)
            ->assertOk()
            ->assertSet('openObjectionsCount', 1);
    }

    public function test_objections_list_shows_status_badge(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedWorkEntry($this->employee, $monday);

        Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Bezwaar op werkregel.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $objections = $component->get('objections');
        $this->assertCount(1, $objections);
        $this->assertSame('OPEN', $objections[0]['status']);
    }

    // --- Requirement 2.6: Snelactie-knoppen ---

    public function test_shows_quick_action_buttons(): void
    {
        $this->actingAs($this->employee);

        Livewire::test(EmployeeHome::class)
            ->assertOk()
            ->assertSee('Uren invoeren')
            ->assertSee('Verlof aanvragen');
    }

    // --- Requirement 2.7: Mini-weekoverzicht ---

    public function test_mini_week_overview_shows_entries_per_day(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->seedWorkEntry($this->employee, $monday, netMinutes: 480, type: 'WORK');

        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $weekOverview = $component->get('weekOverview');
        $this->assertCount(7, $weekOverview);

        // Maandag (index 0) moet een entry hebben
        $this->assertNotEmpty($weekOverview[0]['entries']);
        $this->assertSame('WORK', $weekOverview[0]['entries'][0]['type']);
        $this->assertSame(480, $weekOverview[0]['entries'][0]['net_minutes']);
    }

    public function test_mini_week_overview_shows_empty_days(): void
    {
        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $weekOverview = $component->get('weekOverview');
        // Alle dagen moeten leeg zijn
        foreach ($weekOverview as $day) {
            $this->assertEmpty($day['entries']);
        }
    }

    // --- Requirement 2.8: Data loaded state ---

    public function test_data_loaded_is_true_after_mount(): void
    {
        $this->actingAs($this->employee);

        Livewire::test(EmployeeHome::class)
            ->assertOk()
            ->assertSet('dataLoaded', true);
    }

    // --- Requirement 2.9: Notificaties ---

    public function test_shows_notifications_for_recent_leave_approval(): void
    {
        $this->employee->forceFill(['annual_leave_days' => 25])->save();

        // Maak een recent goedgekeurd verlof-entry
        $entry = WorkEntry::create([
            'organization_id' => $this->employee->organization_id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->employee->team_id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => Carbon::now('Europe/Amsterdam')->subDays(2)->toDateString(),
            'start_at' => Carbon::now('Europe/Amsterdam')->subDays(2)->toDateString() . ' 00:00:00',
            'end_at' => Carbon::now('Europe/Amsterdam')->subDays(2)->toDateString() . ' 23:59:00',
            'pause_minutes' => 0,
            'net_minutes' => 0,
            'type' => 'LEAVE',
            'is_finalized' => true,
        ]);

        // Ensure updated_at is recent
        $entry->touch();

        $this->actingAs($this->employee);

        $component = Livewire::test(EmployeeHome::class)
            ->assertOk();

        $notifications = $component->get('notifications');
        $this->assertNotEmpty($notifications);
        $this->assertSame('success', $notifications[0]['type']);
        $this->assertStringContains('Verlof goedgekeurd', $notifications[0]['message']);
    }

    // --- Unauthenticated ---

    public function test_unauthenticated_request_is_forbidden(): void
    {
        Livewire::test(EmployeeHome::class)
            ->assertForbidden();
    }

    // --- Helper ---

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
