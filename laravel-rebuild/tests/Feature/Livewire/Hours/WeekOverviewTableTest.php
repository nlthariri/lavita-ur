<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Hours;

use App\Livewire\Hours\WeekOverviewTable;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Hours\WeekOverviewTable` (taak 10.1
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Forbidden-pad voor employee-rol (req 6.2 — employee gebruikt /uren/mijn-week).
 *  - Manager-team-scope-isolatie (manager ziet alleen eigen team).
 *  - Owner default-scope (alle teams + alleen eigen organisatie).
 *  - Team-filter wisselen + valideren tegen org-grens.
 *  - Status-matrix voor finalized/draft/objection/empty.
 *  - Netto-minuten-aggregatie binnen één cel.
 *  - Week-navigatie (previous/next).
 *  - NL-rendering en lege-staat-tekst (req 6.14).
 *
 * Rationale waarom we Livewire::test direct aanroepen i.p.v. een HTTP-route:
 *  - De web-route op `/uren/week` wordt in een latere taak (sectie 13 of
 *    een interim-taak) geregistreerd; taak 10.1 levert het component zelf.
 *  - `Livewire::test()` rendert de component met de actieve user-sessie
 *    via Laravel's `actingAs`, wat exact is wat we hier nodig hebben.
 */
final class WeekOverviewTableTest extends TestCase
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

    private string $weekStartIso;

    protected function setUp(): void
    {
        parent::setUp();

        // Vaste maandag — vrijdag is bewust een week voor de huidige
        // datum-default in de component zodat tests deterministisch zijn.
        $this->weekStartIso = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();

        $this->org1 = Organization::create(['name' => 'LaVita Org Eén']);
        $this->org2 = Organization::create(['name' => 'LaVita Org Twee']);

        // Owner van org1.
        $this->owner = User::create([
            'name' => 'Owner Eén',
            'full_name' => 'Owner Eén',
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

        // 2 employees in team B.
        for ($i = 1; $i <= 2; $i++) {
            $this->employeesTeamB[] = User::create([
                'name' => 'Emp B'.$i,
                'full_name' => 'Beta '.$i.' Werknemer',
                'email' => 'emp-b'.$i.'@lavita.test',
                'password' => bcrypt('Wachtwoord1234'),
                'organization_id' => $this->org1->id,
                'team_id' => $this->teamB->id,
                'role' => 'employee',
                'is_active' => true,
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

    /**
     * Kleine helper om een werkregel te seeden voor (employee, dag).
     *
     * Het schema heeft een UNIQUE-index op
     * `(employee_id, entry_date, start_at)`, dus om meerdere entries
     * binnen dezelfde dag te kunnen seeden moet de aanroeper verschillende
     * `startTime`s opgeven.
     */
    private function seedWorkEntry(
        User $employee,
        string $isoDate,
        int $netMinutes = 480,
        bool $isFinalized = true,
        ?User $registrar = null,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
        string $type = 'WORK'
    ): WorkEntry {
        $registrar ??= $this->owner;

        return WorkEntry::create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'team_id' => $employee->team_id,
            'registered_by_id' => $registrar->id,
            'entry_date' => $isoDate,
            'start_at' => $isoDate.' '.$startTime,
            'end_at' => $isoDate.' '.$endTime,
            'pause_minutes' => 30,
            'net_minutes' => $netMinutes,
            'is_finalized' => $isFinalized,
            'type' => $type,
        ]);
    }

    public function test_employee_role_is_forbidden(): void
    {
        $employee = $this->employeesTeamA[0];

        $this->actingAs($employee);

        Livewire::test(WeekOverviewTable::class)
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        // Defensief pad: zonder actingAs -> abort(403).
        Livewire::test(WeekOverviewTable::class)
            ->assertForbidden();
    }

    public function test_manager_sees_only_their_own_team_rows(): void
    {
        $this->actingAs($this->managerA);

        $component = Livewire::test(WeekOverviewTable::class)
            ->assertOk();

        /** @var Collection $employees */
        $employees = $component->instance()->getEmployees();
        $emails = $employees->pluck('email')->all();

        // Manager A zelf zit erin (rol manager → toegestaan in lijst), plus
        // de twee team-A-employees.
        $this->assertContains($this->managerA->email, $emails);
        $this->assertContains($this->employeesTeamA[0]->email, $emails);
        $this->assertContains($this->employeesTeamA[1]->email, $emails);

        // Team B en owner (andere team_id) horen niet zichtbaar te zijn.
        $this->assertNotContains($this->managerB->email, $emails);
        $this->assertNotContains($this->employeesTeamB[0]->email, $emails);
        $this->assertNotContains($this->employeesTeamB[1]->email, $emails);

        // Boekhouder hoort niet als rij — heeft geen werkregels.
        $this->assertNotContains($this->bookkeeper->email, $emails);

        // Sentinel uit andere org mag nooit verschijnen.
        $this->assertNotContains($this->sentinelEmployeeOtherOrg->email, $emails);

        /** @var Collection $teams */
        $teams = $component->instance()->getAvailableTeams();
        $this->assertCount(1, $teams, 'Manager mag maar 1 team in de filter-dropdown zien.');
        $this->assertSame($this->teamA->id, (int) $teams->first()->id);
    }

    public function test_owner_sees_all_org_teams_by_default(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(WeekOverviewTable::class)
            ->assertOk();

        $emails = $component->instance()->getEmployees()->pluck('email')->all();

        // Owner ziet alle managers en employees binnen org1.
        $this->assertContains($this->owner->email, $emails);
        $this->assertContains($this->managerA->email, $emails);
        $this->assertContains($this->managerB->email, $emails);
        $this->assertContains($this->employeesTeamA[0]->email, $emails);
        $this->assertContains($this->employeesTeamB[0]->email, $emails);

        // Boekhouder rendert niet als rij.
        $this->assertNotContains($this->bookkeeper->email, $emails);
        // Sentinel in andere org mag nooit lekken.
        $this->assertNotContains($this->sentinelEmployeeOtherOrg->email, $emails);

        // Owner ziet beide teams in de dropdown.
        $teamIds = $component->instance()->getAvailableTeams()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$this->teamA->id, $this->teamB->id], $teamIds);
    }

    public function test_owner_can_filter_by_team(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(WeekOverviewTable::class)
            ->call('setTeamFilter', $this->teamA->id)
            ->assertSet('teamFilter', $this->teamA->id);

        $emails = $component->instance()->getEmployees()->pluck('email')->all();
        // Alleen team-A-medewerkers; niet manager B of team B.
        $this->assertContains($this->employeesTeamA[0]->email, $emails);
        $this->assertContains($this->managerA->email, $emails);
        $this->assertNotContains($this->employeesTeamB[0]->email, $emails);
        $this->assertNotContains($this->managerB->email, $emails);

        // Reset → alle teams weer.
        $component->call('setTeamFilter', null)
            ->assertSet('teamFilter', null);
        $emailsAfter = $component->instance()->getEmployees()->pluck('email')->all();
        $this->assertContains($this->employeesTeamB[0]->email, $emailsAfter);
    }

    public function test_owner_team_filter_rejects_other_org_team(): void
    {
        // Maak een team in org2 aan dat niet bij owner van org1 hoort.
        $otherOrgTeam = Team::create([
            'organization_id' => $this->org2->id,
            'name' => 'Vreemde org team',
        ]);

        $this->actingAs($this->owner);

        $component = Livewire::test(WeekOverviewTable::class)
            ->call('setTeamFilter', $otherOrgTeam->id)
            ->assertHasErrors('teamFilter')
            ->assertSet('teamFilter', null);

        // Filter blijft null → owner blijft alle org1-medewerkers zien.
        $emails = $component->instance()->getEmployees()->pluck('email')->all();
        $this->assertContains($this->employeesTeamA[0]->email, $emails);
        $this->assertContains($this->employeesTeamB[0]->email, $emails);
    }

    public function test_status_matrix_for_finalized_draft_objection_empty(): void
    {
        $this->actingAs($this->managerA);

        $employee = $this->employeesTeamA[0];

        $monday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');
        $finalizedDate = $monday->copy()->toDateString();
        $draftDate = $monday->copy()->addDays(1)->toDateString();
        $objectionDate = $monday->copy()->addDays(2)->toDateString();
        $emptyDate = $monday->copy()->addDays(3)->toDateString();

        // 1) Vastgesteld
        $this->seedWorkEntry($employee, $finalizedDate, 480, isFinalized: true);

        // 2) Concept (niet finalized)
        $this->seedWorkEntry($employee, $draftDate, 240, isFinalized: false);

        // 3) Bezwaar open op vastgestelde regel
        $entryWithObjection = $this->seedWorkEntry($employee, $objectionDate, 360, isFinalized: true);
        Objection::create([
            'organization_id' => $employee->organization_id,
            'work_entry_id' => $entryWithObjection->id,
            'submitted_by_id' => $employee->id,
            'motivation' => 'Onjuiste tijden geregistreerd.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        // 4) emptyDate krijgt geen entry → moet 'empty' opleveren

        $component = Livewire::test(WeekOverviewTable::class)
            ->assertOk();

        $matrix = $component->instance()->getStatusMatrix();

        $this->assertSame('finalized', $matrix[$employee->id][$finalizedDate]);
        $this->assertSame('draft', $matrix[$employee->id][$draftDate]);
        $this->assertSame('objection', $matrix[$employee->id][$objectionDate]);
        $this->assertSame('empty', $matrix[$employee->id][$emptyDate]);
    }

    public function test_net_minutes_aggregation_sums_entries_within_same_day(): void
    {
        $this->actingAs($this->managerA);

        $employee = $this->employeesTeamA[0];
        $monday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');
        $iso = $monday->toDateString();

        // Twee aparte diensten op dezelfde dag — verschillende start_at om
        // de UNIQUE-index `(employee_id, entry_date, start_at)` niet te
        // schenden, identiek aan hoe een dubbele dienst (split-shift) in
        // productie geregistreerd zou worden.
        $this->seedWorkEntry($employee, $iso, netMinutes: 240, startTime: '08:00:00', endTime: '12:30:00');
        $this->seedWorkEntry($employee, $iso, netMinutes: 120, startTime: '13:30:00', endTime: '15:30:00');

        $component = Livewire::test(WeekOverviewTable::class)
            ->assertOk();

        $this->assertSame(360, $component->instance()->getNetMinutesForCell($employee->id, $iso));
    }

    public function test_navigation_methods_change_week_start_by_seven_days(): void
    {
        $this->actingAs($this->managerA);

        $component = Livewire::test(WeekOverviewTable::class)
            ->assertOk();

        $start = $component->get('weekStart');

        $expectedPrev = Carbon::parse($start, 'Europe/Amsterdam')->subDays(7)->toDateString();
        $component->call('previousWeek')
            ->assertSet('weekStart', $expectedPrev);

        $expectedNext = Carbon::parse($expectedPrev, 'Europe/Amsterdam')->addDays(7)->toDateString();
        $component->call('nextWeek')
            ->assertSet('weekStart', $expectedNext);

        // Vandaag → terug naar de oorspronkelijke maandag.
        $today = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $component->call('goToToday')
            ->assertSet('weekStart', $today);
    }

    public function test_render_returns_200_with_nl_headings_and_buttons(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Weekoverzicht uren')
            ->assertSee('Vorige week')
            ->assertSee('Volgende week')
            ->assertSee('Vandaag')
            ->assertSee('Medewerker');
    }

    public function test_render_with_no_employees_shows_empty_state_in_dutch(): void
    {
        // Maak een lege team-C in org1, geef manager-C dat team, geen members.
        $managerC = User::create([
            'name' => 'Manager C',
            'full_name' => 'Cees Manager',
            'email' => 'mgr-c@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $teamC = Team::create([
            'organization_id' => $this->org1->id,
            'name' => 'Team Lege',
            'manager_id' => $managerC->id,
        ]);

        // Nadat het team bestaat geven we manager-C zijn eigen team_id.
        // Manager-C zelf is een manager-rol en zou normaal in de eigen
        // employees-lijst verschijnen, dus we gebruiken een aparte
        // manager met een aparte org-context: om écht 0 employees in de
        // scope te krijgen geven we manager-C een team waar niemand
        // anders in zit en filteren we uit door deactiveren.
        $managerC->update(['team_id' => $teamC->id, 'is_active' => false]);

        $this->actingAs($managerC);

        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Geen medewerkers gevonden voor de huidige scope.');
    }

    // ─── Copy-week tests (task 7.3) ─────────────────────────────────────

    public function test_copy_week_button_visible_for_owner(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Kopieer vorige week');
    }

    public function test_copy_week_button_visible_for_manager(): void
    {
        $this->actingAs($this->managerA);

        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Kopieer vorige week');
    }

    public function test_copy_week_button_hidden_for_boekhouder(): void
    {
        $this->actingAs($this->bookkeeper);

        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertDontSee('Kopieer vorige week');
    }

    public function test_copy_week_modal_opens_and_shows_week_labels(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSet('showCopyWeekModal', false)
            ->call('openCopyWeekModal')
            ->assertSet('showCopyWeekModal', true);

        // Verify the source and target week labels are accessible
        $sourceLabel = $component->instance()->getSourceWeekLabel();
        $targetLabel = $component->instance()->getTargetWeekLabel();

        $this->assertNotEmpty($sourceLabel);
        $this->assertNotEmpty($targetLabel);
        $this->assertNotSame($sourceLabel, $targetLabel);
    }

    public function test_copy_week_modal_closes(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(WeekOverviewTable::class)
            ->call('openCopyWeekModal')
            ->assertSet('showCopyWeekModal', true)
            ->call('closeCopyWeekModal')
            ->assertSet('showCopyWeekModal', false);
    }

    public function test_copy_week_empty_source_shows_info_toast(): void
    {
        $this->actingAs($this->owner);

        // No work entries in the previous week → info toast
        Livewire::test(WeekOverviewTable::class)
            ->call('executeCopyWeek')
            ->assertDispatched('toast', variant: 'info', message: 'Vorige week bevat geen werkregels om te kopiëren.');
    }

    public function test_copy_week_success_creates_entries_and_shows_success_toast(): void
    {
        $this->actingAs($this->owner);

        $employee = $this->employeesTeamA[0];
        $currentMonday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');
        $previousMonday = $currentMonday->copy()->subDays(7);

        // Seed a work entry in the previous week (Monday)
        $this->seedWorkEntry(
            $employee,
            $previousMonday->toDateString(),
            netMinutes: 480,
            isFinalized: true,
            startTime: '09:00:00',
            endTime: '17:30:00'
        );

        $component = Livewire::test(WeekOverviewTable::class)
            ->call('executeCopyWeek')
            ->assertDispatched('toast', variant: 'success');

        // Verify the entry was created in the current week
        $copiedEntry = WorkEntry::where('employee_id', $employee->id)
            ->whereDate('entry_date', $currentMonday->toDateString())
            ->first();

        $this->assertNotNull($copiedEntry, 'A work entry should have been copied to the current week.');
    }

    public function test_copy_week_can_copy_week_returns_false_for_employee(): void
    {
        $employee = $this->employeesTeamA[0];

        // Employee can't even access the component (403), but let's test
        // the canCopyWeek method directly on a bookkeeper who CAN access
        $this->actingAs($this->bookkeeper);

        $component = Livewire::test(WeekOverviewTable::class)->assertOk();
        $this->assertFalse($component->instance()->canCopyWeek());
    }

    // ─── Print tests (task 10.1) ────────────────────────────────────────

    public function test_print_button_is_visible_for_all_authorized_roles(): void
    {
        // Owner sees print button
        $this->actingAs($this->owner);
        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Printen');

        // Manager sees print button
        $this->actingAs($this->managerA);
        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Printen');

        // Boekhouder sees print button
        $this->actingAs($this->bookkeeper);
        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Printen');
    }

    public function test_print_header_contains_week_number_and_organization(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(WeekOverviewTable::class)
            ->assertOk();

        // The print header should contain the week number and organization name
        $weekNumber = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam')->isoWeek();
        $component->assertSee("Weekoverzicht — Week {$weekNumber} — LaVita Org Eén");
    }

    public function test_print_footer_contains_generated_by_text(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(WeekOverviewTable::class)
            ->assertOk()
            ->assertSee('Gegenereerd door La Vita Urenregistratie op');
    }
}
