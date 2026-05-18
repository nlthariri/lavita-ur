<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Atw;

use App\Livewire\Atw\StatusDashboard;
use App\Models\AtwViolation;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\Feature\Livewire\Hours\WeekOverviewTableTest;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Atw\StatusDashboard` (taak 11.1
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Forbidden-pad voor employee-rol (req 6.5 — employees zien hun eigen
 *    ATW-feedback via /uren/mijn-week + EntryFormModal).
 *  - Forbidden-pad voor anonieme requests (defensief).
 *  - Manager-team-scope-isolatie (manager ziet alleen eigen team).
 *  - Owner default-scope (alle teams + alleen eigen organisatie + geen
 *    sentinel uit andere org).
 *  - Team-filter wisselen voor owner.
 *  - Status-matrix `severity = ok` voor cellen zonder violation.
 *  - Mapping `DAILY_LIMIT/critical` → kolom DAILY_LIMIT met danger-variant.
 *  - Mapping `WEEKLY_WARNING/warning` → kolom WEEKLY met warning-variant.
 *  - Most-recent violation wint per cel (dezelfde kolom, twee leeftijden).
 *  - NL-kolomkoppen renderen op de pagina.
 *
 * Rationale waarom we Livewire::test direct aanroepen i.p.v. een HTTP-route:
 *  - De web-route op `/atw` wordt in een latere taak geregistreerd; taak
 *    11.1 levert het component zelf, identiek aan de keuze in
 *    {@see WeekOverviewTableTest}.
 */
final class StatusDashboardTest extends TestCase
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

        // Owner van org1.
        $this->owner = User::create([
            'name' => 'Owner Eén',
            'full_name' => 'Owner Eén',
            'email' => 'owner1-atw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Twee teams in org1, elk met eigen manager.
        $this->managerA = User::create([
            'name' => 'Manager A',
            'full_name' => 'Anneke Manager',
            'email' => 'mgr-a-atw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->managerB = User::create([
            'name' => 'Manager B',
            'full_name' => 'Bert Manager',
            'email' => 'mgr-b-atw@lavita.test',
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
                'email' => 'emp-a'.$i.'-atw@lavita.test',
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
            'email' => 'emp-b1-atw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'team_id' => $this->teamB->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Boekhouder in org1 (zonder team — req 3.8).
        $this->bookkeeper = User::create([
            'name' => 'Boekhouder',
            'full_name' => 'Bea Boekhouder',
            'email' => 'boek1-atw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        // Sentinel employee in een andere organisatie — moet NOOIT
        // voorkomen in de scope van de owner/manager van org1, en zijn
        // eventuele violations mogen niet lekken in de matrix.
        $this->sentinelEmployeeOtherOrg = User::create([
            'name' => 'Sentinel Other',
            'full_name' => 'Sentinel Andere Org',
            'email' => 'sentinel-atw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org2->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    /**
     * Hulp om een AtwViolation te seeden. Gebruikt expliciete
     * `created_at` zodat tests met "meest-recente-wint" deterministisch zijn.
     */
    private function seedViolation(
        User $user,
        string $violationType,
        string $severity,
        int $currentMinutes = 0,
        int $thresholdMinutes = 0,
        ?Carbon $createdAt = null,
    ): AtwViolation {
        $createdAt ??= now();

        $row = AtwViolation::create([
            'organization_id' => (int) $user->organization_id,
            'user_id' => (int) $user->id,
            'work_entry_id' => null,
            'violation_type' => $violationType,
            'severity' => $severity,
            'period_start' => $createdAt->toDateString(),
            'period_end' => $createdAt->toDateString(),
            'current_minutes' => $currentMinutes,
            'threshold_minutes' => $thresholdMinutes,
            'details' => 'Test violation '.$violationType,
        ]);

        // `created_at` heeft DB-default `useCurrent()`; we forceren een
        // expliciete waarde voor deterministische ordering bij
        // most-recent-tests.
        AtwViolation::query()
            ->where('id', $row->id)
            ->update(['created_at' => $createdAt]);

        return $row->fresh();
    }

    public function test_employee_role_is_forbidden(): void
    {
        $employee = $this->employeesTeamA[0];

        $this->actingAs($employee);

        Livewire::test(StatusDashboard::class)
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        // Defensief pad: zonder actingAs -> abort(403).
        Livewire::test(StatusDashboard::class)
            ->assertForbidden();
    }

    public function test_manager_sees_only_own_team_employees(): void
    {
        $this->actingAs($this->managerA);

        $component = Livewire::test(StatusDashboard::class)
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

        // Boekhouder hoort niet als rij — heeft geen werkregels.
        $this->assertNotContains($this->bookkeeper->email, $emails);

        // Sentinel uit andere org mag nooit verschijnen.
        $this->assertNotContains($this->sentinelEmployeeOtherOrg->email, $emails);

        /** @var Collection $teams */
        $teams = $component->instance()->getAvailableTeams();
        $this->assertCount(1, $teams, 'Manager mag maar 1 team in de filter-dropdown zien.');
        $this->assertSame($this->teamA->id, (int) $teams->first()->id);
    }

    public function test_owner_sees_all_employees_in_org(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(StatusDashboard::class)
            ->assertOk();

        $emails = $component->instance()->getEmployees()->pluck('email')->all();

        // Owner ziet alle managers en employees binnen org1.
        $this->assertContains($this->owner->email, $emails);
        $this->assertContains($this->managerA->email, $emails);
        $this->assertContains($this->managerB->email, $emails);
        $this->assertContains($this->employeesTeamA[0]->email, $emails);
        $this->assertContains($this->employeesTeamA[1]->email, $emails);
        $this->assertContains($this->employeesTeamB[0]->email, $emails);

        // Boekhouder rendert niet als rij.
        $this->assertNotContains($this->bookkeeper->email, $emails);
    }

    public function test_owner_sees_no_employees_from_other_org(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(StatusDashboard::class)
            ->assertOk();

        $emails = $component->instance()->getEmployees()->pluck('email')->all();

        // Sentinel uit org2 mag nooit verschijnen in de scope van owner van org1.
        $this->assertNotContains($this->sentinelEmployeeOtherOrg->email, $emails);
    }

    public function test_team_filter_for_owner_works(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(StatusDashboard::class)
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

    public function test_status_matrix_renders_ok_for_no_violations(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(StatusDashboard::class)
            ->assertOk();

        $matrix = $component->instance()->getStatusMatrix();

        // Iedere zichtbare medewerker krijgt een rij; iedere kolom in elke
        // rij krijgt severity 'ok' wanneer er geen violations zijn.
        $columnKeys = array_keys($component->instance()->getColumnTypes());
        $this->assertSame(
            ['DAILY_LIMIT', 'WEEKLY', 'SIXTEEN_WEEK_AVERAGE', 'REST_PERIOD', 'PAUSE_REQUIRED'],
            $columnKeys,
            'Kolomvolgorde moet vast zijn voor consistente UI.'
        );

        $employeeIds = $component->instance()->getEmployees()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotEmpty($employeeIds);

        foreach ($employeeIds as $eid) {
            $this->assertArrayHasKey($eid, $matrix);
            foreach ($columnKeys as $columnKey) {
                $this->assertArrayHasKey($columnKey, $matrix[$eid]);
                $this->assertSame('ok', $matrix[$eid][$columnKey]['severity'],
                    sprintf('Cel (%d, %s) moet OK zijn zonder violations.', $eid, $columnKey)
                );
            }
        }
    }

    public function test_status_matrix_renders_critical_for_daily_limit(): void
    {
        $employee = $this->employeesTeamA[0];

        $this->seedViolation(
            $employee,
            violationType: 'DAILY_LIMIT',
            severity: 'critical',
            currentMinutes: 720,
            thresholdMinutes: 660,
        );

        $this->actingAs($this->owner);

        $component = Livewire::test(StatusDashboard::class)
            ->assertOk();

        $matrix = $component->instance()->getStatusMatrix();
        $this->assertArrayHasKey($employee->id, $matrix);
        $cell = $matrix[$employee->id]['DAILY_LIMIT'];

        $this->assertSame('critical', $cell['severity']);
        $this->assertSame(720, $cell['current_minutes']);
        $this->assertSame(660, $cell['threshold_minutes']);
        $this->assertSame('DAILY_LIMIT', $cell['violation_type']);

        // De andere kolommen blijven OK.
        $this->assertSame('ok', $matrix[$employee->id]['WEEKLY']['severity']);
        $this->assertSame('ok', $matrix[$employee->id]['SIXTEEN_WEEK_AVERAGE']['severity']);
        $this->assertSame('ok', $matrix[$employee->id]['REST_PERIOD']['severity']);
        $this->assertSame('ok', $matrix[$employee->id]['PAUSE_REQUIRED']['severity']);

        // Variant-mapping: critical → 'danger'.
        $this->assertSame('danger', $component->instance()->getStatusBadgeVariantFor('critical'));
    }

    public function test_status_matrix_renders_warning_for_weekly_warning(): void
    {
        $employee = $this->employeesTeamA[1];

        // `WEEKLY_WARNING` is een warning-severity die in de UI de
        // WEEKLY-kolom laat oplichten met geel (variant 'warning').
        $this->seedViolation(
            $employee,
            violationType: 'WEEKLY_WARNING',
            severity: 'warning',
            currentMinutes: 2880,
            thresholdMinutes: 2880,
        );

        $this->actingAs($this->owner);

        $component = Livewire::test(StatusDashboard::class)
            ->assertOk();

        $matrix = $component->instance()->getStatusMatrix();
        $cell = $matrix[$employee->id]['WEEKLY'];

        $this->assertSame('warning', $cell['severity']);
        $this->assertSame('WEEKLY_WARNING', $cell['violation_type']);
        $this->assertSame(2880, $cell['current_minutes']);

        // Variant-mapping: warning → 'warning' (geel).
        $this->assertSame('warning', $component->instance()->getStatusBadgeVariantFor('warning'));

        // OK-mapping blijft 'success' (groen).
        $this->assertSame('success', $component->instance()->getStatusBadgeVariantFor('ok'));
    }

    public function test_status_matrix_uses_most_recent_violation_per_cell(): void
    {
        $employee = $this->employeesTeamA[0];

        $now = Carbon::now();

        // Oudere kritieke violation (van 3 dagen geleden) op WEEKLY-kolom.
        $this->seedViolation(
            $employee,
            violationType: 'WEEKLY_LIMIT',
            severity: 'critical',
            currentMinutes: 3700,
            thresholdMinutes: 3600,
            createdAt: $now->copy()->subDays(3),
        );

        // Nieuwere warning (van vandaag) op dezelfde WEEKLY-kolom — de
        // dashboard moet dáár de UI op baseren omdat 'meest recent wint'.
        $this->seedViolation(
            $employee,
            violationType: 'WEEKLY_WARNING',
            severity: 'warning',
            currentMinutes: 2900,
            thresholdMinutes: 2880,
            createdAt: $now->copy(),
        );

        $this->actingAs($this->owner);

        $component = Livewire::test(StatusDashboard::class)
            ->assertOk();

        $matrix = $component->instance()->getStatusMatrix();
        $cell = $matrix[$employee->id]['WEEKLY'];

        $this->assertSame('warning', $cell['severity'],
            'Bij twee violations op dezelfde kolom moet de meest-recente winnen.'
        );
        $this->assertSame('WEEKLY_WARNING', $cell['violation_type']);
        $this->assertSame(2900, $cell['current_minutes']);
    }

    public function test_render_includes_nl_column_headers(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(StatusDashboard::class)
            ->assertOk()
            ->assertSee('ATW-dashboard')
            ->assertSee('Medewerker')
            ->assertSee('Daglimiet')
            ->assertSee('Weeklimiet')
            ->assertSee('16-weken-gem.')
            ->assertSee('Rusttijd')
            ->assertSee('Pauzeplicht');
    }
}
