<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Dashboard;

use App\Livewire\Dashboard\ManagerHome;
use App\Models\AtwViolation;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Atw\StatusDashboardTest;
use Tests\Feature\Livewire\Hours\WeekOverviewTableTest;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Dashboard\ManagerHome` (taak 11.3
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Forbidden-pad voor employee-rol (req 6.9 — employees hebben geen
 *    management-dashboard).
 *  - Forbidden-pad voor anonieme requests (defensief).
 *  - Owner ziet `userFullName` + `organizationName` in de header.
 *  - Aanwezigheidsteller telt alleen medewerkers met entries deze week.
 *  - Aanwezigheidsteller telt geen entries uit andere weken.
 *  - Openstaande bezwarenteller telt alleen `status = 'OPEN'`.
 *  - ATW-tellers tellen distinct user_id per severity, en negeren
 *    superseded violations.
 *  - Manager-scope filtert bezwaren op werkregels van andere teams.
 *  - Snelkoppelingen renderen in NL en de owner-only "Accountbeheer"
 *    is alleen zichtbaar voor owners.
 *  - NL-section-headings renderen op de pagina.
 *
 * Rationale waarom we Livewire::test direct aanroepen i.p.v. een HTTP-route:
 *  - De web-route op `/dashboard` wordt in een latere taak geregistreerd,
 *    identiek aan {@see WeekOverviewTableTest}
 *    en {@see StatusDashboardTest}.
 */
final class ManagerHomeTest extends TestCase
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

        // Owner van org1 — geeft een full_name zodat de header-test
        // op een niet-lege begroeting kan asserten.
        $this->owner = User::create([
            'name' => 'Owner Eén',
            'full_name' => 'Olivia Owner',
            'email' => 'owner1-mh@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Twee teams in org1, elk met eigen manager.
        $this->managerA = User::create([
            'name' => 'Manager A',
            'full_name' => 'Anneke Manager',
            'email' => 'mgr-a-mh@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->managerB = User::create([
            'name' => 'Manager B',
            'full_name' => 'Bert Manager',
            'email' => 'mgr-b-mh@lavita.test',
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

        $this->managerA->update(['team_id' => $this->teamA->id]);
        $this->managerB->update(['team_id' => $this->teamB->id]);

        // 2 employees in team A.
        for ($i = 1; $i <= 2; $i++) {
            $this->employeesTeamA[] = User::create([
                'name' => 'Emp A'.$i,
                'full_name' => 'Alpha '.$i.' Werknemer',
                'email' => 'emp-a'.$i.'-mh@lavita.test',
                'password' => bcrypt('Wachtwoord1234'),
                'organization_id' => $this->org1->id,
                'team_id' => $this->teamA->id,
                'role' => 'employee',
                'is_active' => true,
            ]);
        }

        // 1 employee in team B.
        $this->employeesTeamB[] = User::create([
            'name' => 'Emp B1',
            'full_name' => 'Beta 1 Werknemer',
            'email' => 'emp-b1-mh@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'team_id' => $this->teamB->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Boekhouder in org1 zonder team (req 3.8) — telt niet als
        // "aanwezig" maar mag wél het dashboard zien.
        $this->bookkeeper = User::create([
            'name' => 'Boekhouder',
            'full_name' => 'Bea Boekhouder',
            'email' => 'boek1-mh@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        // Sentinel employee in een andere organisatie — moet NOOIT
        // voorkomen in de scope van owner/manager van org1.
        $this->sentinelEmployeeOtherOrg = User::create([
            'name' => 'Sentinel Other',
            'full_name' => 'Sentinel Andere Org',
            'email' => 'sentinel-mh@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org2->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    /**
     * Helper om een werkregel te seeden. Het schema heeft een unique-index
     * `(employee_id, entry_date, start_at)`, dus consumers met meer dan
     * één entry per dag moeten verschillende `start_at` opgeven.
     */
    private function seedWorkEntry(
        User $employee,
        string $isoDate,
        int $netMinutes = 480,
        bool $isFinalized = true,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
    ): WorkEntry {
        return WorkEntry::create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'team_id' => $employee->team_id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $isoDate,
            'start_at' => $isoDate.' '.$startTime,
            'end_at' => $isoDate.' '.$endTime,
            'pause_minutes' => 30,
            'net_minutes' => $netMinutes,
            'is_finalized' => $isFinalized,
        ]);
    }

    /**
     * Helper om een AtwViolation te seeden. Default-severity is 'warning'
     * zodat de aanroeper alleen `severity` hoeft door te geven wanneer
     * iets anders nodig is.
     */
    private function seedViolation(
        User $user,
        string $violationType,
        string $severity,
        ?Carbon $supersededAt = null,
    ): AtwViolation {
        return AtwViolation::create([
            'organization_id' => (int) $user->organization_id,
            'user_id' => (int) $user->id,
            'work_entry_id' => null,
            'violation_type' => $violationType,
            'severity' => $severity,
            'period_start' => Carbon::now()->toDateString(),
            'period_end' => Carbon::now()->toDateString(),
            'current_minutes' => 1,
            'threshold_minutes' => 1,
            'details' => 'Test '.$violationType,
            'superseded_at' => $supersededAt,
        ]);
    }

    public function test_employee_role_is_redirected(): void
    {
        $employee = $this->employeesTeamA[0];

        $this->actingAs($employee);

        Livewire::test(ManagerHome::class)
            ->assertRedirect('/dashboard/medewerker');
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        Livewire::test(ManagerHome::class)
            ->assertForbidden();
    }

    public function test_owner_dashboard_shows_organization_name_and_full_name(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSet('userFullName', 'Olivia Owner')
            ->assertSet('organizationName', 'LaVita Org Eén')
            ->assertSee('Olivia Owner')
            ->assertSee('LaVita Org Eén');
    }

    public function test_present_count_excludes_employees_without_entries_this_week(): void
    {
        // Eén employee in team-A met een entry deze week, één zonder.
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->seedWorkEntry($this->employeesTeamA[0], $monday);
        // employeesTeamA[1] heeft geen entry → telt niet als aanwezig.

        $this->actingAs($this->owner);

        $component = Livewire::test(ManagerHome::class)
            ->assertOk();

        // Owner ziet alle medewerkers in scope: owner zelf + 2 managers
        // + 2 emp-A + 1 emp-B = 6 actieve users met rol owner/manager/employee.
        $this->assertSame(6, $component->get('totalEmployeesInScope'));
        $this->assertSame(1, $component->get('presentEmployeesThisWeek'));
    }

    public function test_present_count_includes_only_current_week(): void
    {
        // Een entry vorige week telt niet voor "aanwezigheid deze week".
        $lastWeekMonday = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->subDays(7)
            ->toDateString();

        $this->seedWorkEntry($this->employeesTeamA[0], $lastWeekMonday);

        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSet('presentEmployeesThisWeek', 0);
    }

    public function test_present_count_does_not_double_count_multiple_entries_per_employee(): void
    {
        // Twee entries in dezelfde week voor één medewerker mogen
        // resulteren in slechts 1 "aanwezige medewerker" — de teller
        // is op distinct employee_id.
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY);
        $mondayIso = $monday->toDateString();
        $tuesdayIso = $monday->copy()->addDay()->toDateString();

        $this->seedWorkEntry($this->employeesTeamA[0], $mondayIso);
        $this->seedWorkEntry($this->employeesTeamA[0], $tuesdayIso);

        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSet('presentEmployeesThisWeek', 1);
    }

    public function test_open_objections_count(): void
    {
        // Seed 1 entry voor employeesTeamA[0] met 2 OPEN bezwaren plus
        // 1 APPROVED bezwaar — alleen de 2 OPEN tellen.
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry1 = $this->seedWorkEntry($this->employeesTeamA[0], $monday, startTime: '08:00:00', endTime: '12:00:00');
        $entry2 = $this->seedWorkEntry($this->employeesTeamA[0], $monday, startTime: '13:00:00', endTime: '17:00:00');
        $entry3 = $this->seedWorkEntry($this->employeesTeamA[1], $monday);

        Objection::create([
            'organization_id' => $this->org1->id,
            'work_entry_id' => $entry1->id,
            'submitted_by_id' => $this->employeesTeamA[0]->id,
            'motivation' => 'Onjuiste begintijd geregistreerd voor entry 1.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        Objection::create([
            'organization_id' => $this->org1->id,
            'work_entry_id' => $entry2->id,
            'submitted_by_id' => $this->employeesTeamA[0]->id,
            'motivation' => 'Onjuiste eindtijd geregistreerd voor entry 2.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        Objection::create([
            'organization_id' => $this->org1->id,
            'work_entry_id' => $entry3->id,
            'submitted_by_id' => $this->employeesTeamA[1]->id,
            'motivation' => 'Goedgekeurd bezwaar uit het verleden.',
            'status' => 'APPROVED',
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSet('openObjectionsCount', 2);
    }

    public function test_atw_critical_and_warning_counts(): void
    {
        // emp-A1: één critical + één warning  → telt voor beide tellers.
        $this->seedViolation($this->employeesTeamA[0], 'DAILY_LIMIT', 'critical');
        $this->seedViolation($this->employeesTeamA[0], 'WEEKLY_WARNING', 'warning');

        // emp-A2: alleen critical.
        $this->seedViolation($this->employeesTeamA[1], 'WEEKLY_LIMIT', 'critical');

        // emp-B1: warning + extra warning (zelfde user telt 1x).
        $this->seedViolation($this->employeesTeamB[0], 'WEEKLY_WARNING', 'warning');
        $this->seedViolation($this->employeesTeamB[0], 'PAUSE_REQUIRED', 'warning');

        // Superseded warning (niet tellen) op employeesTeamA[0] —
        // proeft of de filter `superseded_at IS NULL` werkt.
        $this->seedViolation(
            $this->employeesTeamA[0],
            'REST_PERIOD',
            'warning',
            supersededAt: Carbon::now()->subDay(),
        );

        // Sentinel uit andere org — mag nooit meetellen voor org1.
        $this->seedViolation(
            $this->sentinelEmployeeOtherOrg,
            'DAILY_LIMIT',
            'critical',
        );

        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            // 2 distinct user_ids met critical: emp-A1 + emp-A2.
            ->assertSet('atwCriticalCount', 2)
            // 2 distinct user_ids met (active) warning: emp-A1 + emp-B1.
            ->assertSet('atwWarningCount', 2);
    }

    public function test_manager_scope_filters_other_team_objections(): void
    {
        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();

        // Bezwaar op werkregel in team A (team van managerA).
        $entryA = $this->seedWorkEntry($this->employeesTeamA[0], $monday);
        Objection::create([
            'organization_id' => $this->org1->id,
            'work_entry_id' => $entryA->id,
            'submitted_by_id' => $this->employeesTeamA[0]->id,
            'motivation' => 'Bezwaar in team A op de huidige week.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        // Bezwaar op werkregel in team B (team van managerB).
        $entryB = $this->seedWorkEntry($this->employeesTeamB[0], $monday);
        Objection::create([
            'organization_id' => $this->org1->id,
            'work_entry_id' => $entryB->id,
            'submitted_by_id' => $this->employeesTeamB[0]->id,
            'motivation' => 'Bezwaar in team B op de huidige week.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        // ManagerA logt in → moet alleen het bezwaar in team A tellen.
        $this->actingAs($this->managerA);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSet('openObjectionsCount', 1);

        // Owner moet beide bezwaren zien.
        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSet('openObjectionsCount', 2);
    }

    public function test_quick_links_render_in_nl(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSee('Snelkoppelingen')
            ->assertSee('Weekoverzicht uren')
            ->assertSee('ATW-statusdashboard')
            ->assertSee('Rapportages')
            ->assertSee('Bezwaren');
    }

    public function test_quick_links_show_account_management_only_for_owner(): void
    {
        // Owner ziet de Accountbeheer-link.
        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSee('Accountbeheer');

        // Manager ziet Accountbeheer NIET (owner_only).
        $this->actingAs($this->managerA);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertDontSee('Accountbeheer');
    }

    public function test_render_shows_dutch_section_headings(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSee('Totaal uren deze week')
            ->assertSee('Aanwezigheid')
            ->assertSee('Verlofaanvragen')
            ->assertSee('ATW-meldingen')
            ->assertSee('Bezwaren')
            ->assertSee('Ziekteverzuim')
            ->assertSee('Snelkoppelingen');
    }

    public function test_bookkeeper_can_view_dashboard(): void
    {
        // Boekhouder is read-only over alle non-GET methodes (Req 3),
        // maar mag het dashboard wel bekijken (Req 6.9 vereist
        // management-overzicht; boekhouder is daar onderdeel van).
        $this->actingAs($this->bookkeeper);

        Livewire::test(ManagerHome::class)
            ->assertOk()
            ->assertSee('Snelkoppelingen');
    }
}
