<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Reports;

use App\Livewire\Reports\Filters;
use App\Models\CostCenter;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\Feature\Livewire\Atw\StatusDashboardTest;
use Tests\Feature\Livewire\Hours\WeekOverviewTableTest;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Reports\Filters` (taak 12.1
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Forbidden-pad voor employee-rol (req 6.7 — employees gebruiken
 *    /uren/mijn-week, niet /rapportages).
 *  - Forbidden-pad voor anonieme requests (defensief).
 *  - Owner kan het filterscherm zien met NL-labels.
 *  - Default-periode = eerste/laatste van huidige maand
 *    (Europe/Amsterdam).
 *  - Medewerker-dropdown: owner ziet alle org-medewerkers, manager alleen
 *    eigen team, sentinel uit andere org nooit zichtbaar.
 *  - Preview-count zonder filter telt alle regels in de scope.
 *  - Preview-count respecteert `employee_id`-filter.
 *  - Preview-count respecteert `from`/`to`-periode.
 *  - Validatie: `dateTo < dateFrom` levert validation-error op `dateTo`.
 *  - PDF-download geeft een file-download terug met de correcte
 *    Content-Type en filename-extensie.
 *  - Excel-download geeft een file-download terug met de correcte
 *    Content-Type en filename-extensie.
 *
 * Rationale waarom we Livewire::test direct aanroepen i.p.v. een HTTP-
 * route: de web-route op `/rapportages` wordt in een latere taak
 * geregistreerd; taak 12.1 levert het component zelf, identiek aan de
 * keuze in {@see WeekOverviewTableTest}
 * en {@see StatusDashboardTest}.
 */
final class FiltersTest extends TestCase
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

    private Project $projectAlpha;

    private CostCenter $costCenterAlpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org1 = Organization::create([
            'name' => 'LaVita Reports Org Eén',
            // Pin de ATW-policy expliciet vast zodat eventuele defaults
            // op `organizations` in latere migraties de tests niet kunnen
            // breken (parity met EntryFormModalTest).
            'atw_daily_max_minutes' => 720,
            'atw_weekly_max_minutes' => 3600,
            'atw_weekly_warning_minutes' => 2880,
            'atw_average_16_week_minutes' => 2880,
        ]);
        $this->org2 = Organization::create(['name' => 'LaVita Reports Org Twee']);

        // Owner van org1.
        $this->owner = User::create([
            'name' => 'Owner Reports',
            'full_name' => 'Olivier Owner',
            'email' => 'owner-reports@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Twee teams in org1, elk met eigen manager.
        $this->managerA = User::create([
            'name' => 'Manager Reports A',
            'full_name' => 'Anneke Manager',
            'email' => 'mgr-a-reports@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->managerB = User::create([
            'name' => 'Manager Reports B',
            'full_name' => 'Bert Manager',
            'email' => 'mgr-b-reports@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->teamA = Team::create([
            'organization_id' => $this->org1->id,
            'name' => 'Team Reports Alfa',
            'manager_id' => $this->managerA->id,
        ]);
        $this->teamB = Team::create([
            'organization_id' => $this->org1->id,
            'name' => 'Team Reports Beta',
            'manager_id' => $this->managerB->id,
        ]);

        // Pin de managers aan hun eigen team.
        $this->managerA->update(['team_id' => $this->teamA->id]);
        $this->managerB->update(['team_id' => $this->teamB->id]);

        // 2 employees in team A.
        for ($i = 1; $i <= 2; $i++) {
            $this->employeesTeamA[] = User::create([
                'name' => 'Emp Reports A'.$i,
                'full_name' => 'Alpha '.$i.' Werknemer',
                'email' => 'emp-a'.$i.'-reports@lavita.test',
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
                'name' => 'Emp Reports B'.$i,
                'full_name' => 'Beta '.$i.' Werknemer',
                'email' => 'emp-b'.$i.'-reports@lavita.test',
                'password' => bcrypt('Wachtwoord1234'),
                'organization_id' => $this->org1->id,
                'team_id' => $this->teamB->id,
                'role' => 'employee',
                'is_active' => true,
            ]);
        }

        // Boekhouder in org1 (zonder team — req 3.8).
        $this->bookkeeper = User::create([
            'name' => 'Boekhouder Reports',
            'full_name' => 'Bea Boekhouder',
            'email' => 'boek-reports@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org1->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        // Sentinel employee in een andere organisatie — moet NOOIT
        // voorkomen in de scope van de owner/manager van org1.
        $this->sentinelEmployeeOtherOrg = User::create([
            'name' => 'Sentinel Reports Other',
            'full_name' => 'Sentinel Andere Org',
            'email' => 'sentinel-reports@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org2->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Eén actief project en één actieve kostenplaats binnen org1
        // zodat de selects niet leeg zijn in de tests die naar de
        // dropdown-bedrading kijken.
        $this->projectAlpha = Project::create([
            'organization_id' => $this->org1->id,
            'code' => 'PROJALPHA',
            'name' => 'Project Alpha',
            'description' => null,
            'hourly_rate' => '85.00',
            'is_active' => true,
            'archived_at' => null,
        ]);
        $this->costCenterAlpha = CostCenter::create([
            'organization_id' => $this->org1->id,
            'code' => 'KP-ALPHA',
            'name' => 'Kostenplaats Alpha',
            'description' => null,
            'is_active' => true,
            'archived_at' => null,
        ]);
    }

    /**
     * Kleine helper om een werkregel te seeden voor (employee, dag) op
     * een specifieke begintijd. De `(employee_id, entry_date, start_at)`
     * UNIQUE-index dwingt verschillende `startTime`s af voor meerdere
     * regels op dezelfde dag.
     */
    private function seedWorkEntry(
        User $employee,
        string $isoDate,
        int $netMinutes = 480,
        bool $isFinalized = true,
        ?User $registrar = null,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00'
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
            'type' => 'WORK',
            'is_finalized' => $isFinalized,
        ]);
    }

    public function test_employee_role_is_forbidden(): void
    {
        $employee = $this->employeesTeamA[0];

        $this->actingAs($employee);

        Livewire::test(Filters::class)
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        // Defensief pad: zonder actingAs -> abort(403).
        Livewire::test(Filters::class)
            ->assertForbidden();
    }

    public function test_owner_can_view_filters_screen(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Filters::class)
            ->assertOk()
            ->assertSee('Rapportages')
            ->assertSee('Medewerker')
            ->assertSee('Team')
            ->assertSee('Project')
            ->assertSee('Kostenplaats')
            ->assertSee('Begindatum')
            ->assertSee('Einddatum');
    }

    public function test_default_date_range_is_current_month(): void
    {
        $this->actingAs($this->owner);

        $expectedFrom = Carbon::now('Europe/Amsterdam')->startOfMonth()->toDateString();
        $expectedTo = Carbon::now('Europe/Amsterdam')->endOfMonth()->toDateString();

        Livewire::test(Filters::class)
            ->assertOk()
            ->assertSet('dateFrom', $expectedFrom)
            ->assertSet('dateTo', $expectedTo);
    }

    public function test_employee_dropdown_includes_org_employees_and_excludes_other_orgs(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(Filters::class)
            ->assertOk();

        /** @var Collection<int, User> $employees */
        $employees = $component->instance()->getEmployeesInScope();
        $emails = $employees->pluck('email')->all();

        // Owner ziet zichzelf en alle managers + employees binnen org1.
        $this->assertContains($this->owner->email, $emails);
        $this->assertContains($this->managerA->email, $emails);
        $this->assertContains($this->managerB->email, $emails);
        $this->assertContains($this->employeesTeamA[0]->email, $emails);
        $this->assertContains($this->employeesTeamB[0]->email, $emails);

        // Boekhouder verschijnt niet in de medewerker-dropdown
        // (rol heeft geen werkregels).
        $this->assertNotContains($this->bookkeeper->email, $emails);

        // Sentinel uit andere org mag NOOIT lekken.
        $this->assertNotContains($this->sentinelEmployeeOtherOrg->email, $emails);
    }

    public function test_manager_dropdown_only_includes_own_team_employees(): void
    {
        $this->actingAs($this->managerA);

        $component = Livewire::test(Filters::class)
            ->assertOk();

        $emails = $component->instance()->getEmployeesInScope()->pluck('email')->all();

        // Manager A zelf zit erin (manager-rol → toegestaan), plus de
        // twee team-A-employees.
        $this->assertContains($this->managerA->email, $emails);
        $this->assertContains($this->employeesTeamA[0]->email, $emails);
        $this->assertContains($this->employeesTeamA[1]->email, $emails);

        // Team B en owner (andere team_id) horen niet zichtbaar te zijn.
        $this->assertNotContains($this->managerB->email, $emails);
        $this->assertNotContains($this->employeesTeamB[0]->email, $emails);
        $this->assertNotContains($this->employeesTeamB[1]->email, $emails);

        // Manager A ziet maar één team in de dropdown (zijn eigen).
        /** @var Collection<int, Team> $teams */
        $teams = $component->instance()->getTeamsInScope();
        $this->assertCount(1, $teams, 'Manager mag maar 1 team in de filter-dropdown zien.');
        $this->assertSame($this->teamA->id, (int) $teams->first()->id);
    }

    public function test_preview_count_populates_row_count(): void
    {
        $this->actingAs($this->owner);

        $today = Carbon::now('Europe/Amsterdam');
        $iso = $today->copy()->toDateString();

        // Drie verschillende werkregels (verschillende start_at om de
        // UNIQUE-index `(employee_id, entry_date, start_at)` te respecteren).
        $this->seedWorkEntry($this->employeesTeamA[0], $iso, 480, startTime: '08:00:00', endTime: '16:00:00');
        $this->seedWorkEntry($this->employeesTeamA[1], $iso, 240, startTime: '09:00:00', endTime: '13:00:00');
        $this->seedWorkEntry($this->employeesTeamB[0], $iso, 360, startTime: '10:00:00', endTime: '16:00:00');

        Livewire::test(Filters::class)
            ->assertOk()
            ->call('previewCount')
            ->assertSet('rowCount', 3)
            ->assertSee('3 regels gevonden voor de huidige filters.');
    }

    public function test_preview_count_respects_employee_filter(): void
    {
        $this->actingAs($this->owner);

        $today = Carbon::now('Europe/Amsterdam');
        $iso = $today->copy()->toDateString();

        // 3 entries voor employee-A1, 2 voor employee-A2.
        $this->seedWorkEntry($this->employeesTeamA[0], $iso, 240, startTime: '08:00:00', endTime: '12:00:00');
        $this->seedWorkEntry($this->employeesTeamA[0], $iso, 240, startTime: '13:00:00', endTime: '17:00:00');
        $this->seedWorkEntry($this->employeesTeamA[0], $today->copy()->subDay()->toDateString(), 240, startTime: '08:00:00', endTime: '12:00:00');
        $this->seedWorkEntry($this->employeesTeamA[1], $iso, 240, startTime: '08:00:00', endTime: '12:00:00');
        $this->seedWorkEntry($this->employeesTeamA[1], $iso, 240, startTime: '13:00:00', endTime: '17:00:00');

        // Filter op employee-A1: 3 verwacht.
        Livewire::test(Filters::class)
            ->set('employeeId', $this->employeesTeamA[0]->id)
            ->call('previewCount')
            ->assertSet('rowCount', 3);

        // Filter op employee-A2: 2 verwacht.
        Livewire::test(Filters::class)
            ->set('employeeId', $this->employeesTeamA[1]->id)
            ->call('previewCount')
            ->assertSet('rowCount', 2);
    }

    public function test_preview_count_respects_date_range(): void
    {
        $this->actingAs($this->owner);

        // Drie diensten op drie verschillende dagen rond een vaste pivot.
        $pivot = Carbon::create(2026, 5, 15, 0, 0, 0, 'Europe/Amsterdam');

        $this->seedWorkEntry($this->employeesTeamA[0], $pivot->copy()->toDateString(), 240, startTime: '08:00:00', endTime: '12:00:00');
        $this->seedWorkEntry($this->employeesTeamA[0], $pivot->copy()->addDays(7)->toDateString(), 240, startTime: '08:00:00', endTime: '12:00:00');
        $this->seedWorkEntry($this->employeesTeamA[0], $pivot->copy()->subDays(35)->toDateString(), 240, startTime: '08:00:00', endTime: '12:00:00');

        // Strikt: alleen de week 2026-05-11 t/m 2026-05-17 (1 hit).
        Livewire::test(Filters::class)
            ->set('dateFrom', '2026-05-11')
            ->set('dateTo', '2026-05-17')
            ->call('previewCount')
            ->assertSet('rowCount', 1);

        // Twee weken (15 mei + 22 mei): 2 hits.
        Livewire::test(Filters::class)
            ->set('dateFrom', '2026-05-11')
            ->set('dateTo', '2026-05-24')
            ->call('previewCount')
            ->assertSet('rowCount', 2);
    }

    public function test_invalid_date_range_returns_validation_error(): void
    {
        $this->actingAs($this->owner);

        // dateTo strikt vóór dateFrom → after_or_equal-regel faalt op dateTo.
        Livewire::test(Filters::class)
            ->set('dateFrom', '2026-05-15')
            ->set('dateTo', '2026-05-01')
            ->call('previewCount')
            ->assertHasErrors(['dateTo' => 'after_or_equal']);
    }

    public function test_render_shows_dutch_buttons(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Filters::class)
            ->assertOk()
            ->assertSee('Toon aantal regels')
            ->assertSee('Download PDF')
            ->assertSee('Download Excel');
    }

    public function test_pdf_download_returns_file_download(): void
    {
        $this->actingAs($this->owner);

        $today = Carbon::now('Europe/Amsterdam');
        $iso = $today->copy()->toDateString();

        $this->seedWorkEntry(
            $this->employeesTeamA[0],
            $iso,
            netMinutes: 480,
            startTime: '08:00:00',
            endTime: '16:00:00',
        );

        $component = Livewire::test(Filters::class)
            ->assertOk()
            // Default-periode is huidige maand → omvat $iso → 1 regel
            // verwacht in de export.
            ->call('downloadPdf');

        // Livewire 3 capteert StreamedResponse-downloads via de
        // SupportFileDownloads-feature; assertFileDownloaded() is de
        // canonieke assertie op de filename.
        $component->assertFileDownloaded();

        // Filename moet de correcte extensie hebben — we gebruiken een
        // partial match omdat de daadwerkelijke filename ook de
        // periode bevat (`werkregels-YYYY-MM-DD-YYYY-MM-DD.pdf`).
        $effects = $component->effects;
        $this->assertArrayHasKey('download', $effects);
        $download = $effects['download'];
        $this->assertStringEndsWith('.pdf', (string) ($download['name'] ?? ''));
        $this->assertSame('application/pdf', $download['contentType'] ?? null);
    }

    public function test_excel_download_returns_file_download(): void
    {
        $this->actingAs($this->owner);

        $today = Carbon::now('Europe/Amsterdam');
        $iso = $today->copy()->toDateString();

        $this->seedWorkEntry(
            $this->employeesTeamA[0],
            $iso,
            netMinutes: 240,
            startTime: '08:00:00',
            endTime: '12:00:00',
        );

        $component = Livewire::test(Filters::class)
            ->assertOk()
            ->call('downloadExcel');

        $component->assertFileDownloaded();

        $effects = $component->effects;
        $this->assertArrayHasKey('download', $effects);
        $download = $effects['download'];
        $this->assertStringEndsWith('.xlsx', (string) ($download['name'] ?? ''));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $download['contentType'] ?? null,
        );
    }
}
