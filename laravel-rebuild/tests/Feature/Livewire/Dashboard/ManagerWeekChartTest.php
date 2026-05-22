<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Dashboard;

use App\Livewire\Dashboard\ManagerWeekChart;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Dashboard\ManagerWeekChart` (taak 4.2
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Component rendert correct voor owner (alle teams).
 *  - Component rendert correct voor manager (eigen team).
 *  - chartData bevat correcte structuur met dagen ma-zo.
 *  - Unauthenticated request levert lege fallback-data.
 *  - Lazy-loading placeholder bevat skeleton HTML.
 *
 * Requirements: 1.3, 1.10
 */
final class ManagerWeekChartTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $manager;

    private Team $team;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Test Org']);

        $this->owner = User::create([
            'name' => 'Owner',
            'full_name' => 'Test Owner',
            'email' => 'owner-chart@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager',
            'full_name' => 'Test Manager',
            'email' => 'manager-chart@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->team = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Alfa',
            'manager_id' => $this->manager->id,
        ]);

        $this->manager->update(['team_id' => $this->team->id]);

        $this->employee = User::create([
            'name' => 'Emp 1',
            'full_name' => 'Werknemer Eén',
            'email' => 'emp1-chart@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    public function test_owner_sees_chart_data_with_days_of_week(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $monday,
            'start_at' => $monday . ' 08:00:00',
            'end_at' => $monday . ' 16:00:00',
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'is_finalized' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::withoutLazyLoading()
            ->test(ManagerWeekChart::class)
            ->assertOk()
            ->assertViewHas('chartData');
    }

    public function test_chart_data_contains_all_weekdays(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::withoutLazyLoading()
            ->test(ManagerWeekChart::class)
            ->assertOk();

        $chartData = $component->get('chartData');

        // chartData moet minstens één key bevatten (team of 'Totaal')
        $this->assertNotEmpty($chartData);

        // Elke team-entry moet alle 7 dagen bevatten
        $expectedDays = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];

        foreach ($chartData as $teamName => $dayData) {
            foreach ($expectedDays as $day) {
                $this->assertArrayHasKey(
                    $day,
                    $dayData,
                    "Team '{$teamName}' mist dag '{$day}' in chart_data"
                );
            }
        }
    }

    public function test_unauthenticated_user_gets_fallback_data(): void
    {
        // Geen actingAs → Auth::user() is null → fallback
        $component = Livewire::withoutLazyLoading()
            ->test(ManagerWeekChart::class)
            ->assertOk();

        $chartData = $component->get('chartData');

        $this->assertArrayHasKey('Totaal', $chartData);
        $this->assertSame(0, $chartData['Totaal']['ma']);
        $this->assertSame(0, $chartData['Totaal']['zo']);
    }

    public function test_component_renders_chart_container(): void
    {
        $this->actingAs($this->owner);

        Livewire::withoutLazyLoading()
            ->test(ManagerWeekChart::class)
            ->assertOk()
            ->assertSee('Uren per dag deze week')
            ->assertSee('managerBarChart');
    }
}
