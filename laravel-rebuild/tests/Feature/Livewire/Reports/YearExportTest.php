<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Reports;

use App\Livewire\Reports\YearExport;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Reports\YearExport` (taak 12.2
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Forbidden-pad voor employee-rol (req 6.7 — employees gebruiken
 *    /uren/mijn-week, niet /rapportages).
 *  - Forbidden-pad voor anonieme requests (defensief).
 *  - Default-jaar = huidig kalenderjaar in Europe/Amsterdam.
 *  - Owner kan de service aanroepen en krijgt een correcte
 *    medewerker-telling per jaar.
 *  - Jaartal-filter sluit andere jaren uit.
 *  - Employee-filter beperkt het resultaat tot één medewerker.
 *  - Validatie: `year < 1900` → validation-error op `year`.
 *  - PDF-download geeft een file-download terug met de correcte
 *    Content-Type en filename-extensie.
 *  - View toont NL-labels.
 */
final class YearExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'LaVita YearExport Org',
            'atw_daily_max_minutes' => 720,
            'atw_weekly_max_minutes' => 3600,
            'atw_weekly_warning_minutes' => 2880,
            'atw_average_16_week_minutes' => 2880,
        ]);

        $this->owner = User::create([
            'name' => 'Owner Year',
            'full_name' => 'Olivier Owner Year',
            'email' => 'owner-year@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->managerA = User::create([
            'name' => 'Manager Year A',
            'full_name' => 'Anneke Manager Year',
            'email' => 'mgr-a-year@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->managerB = User::create([
            'name' => 'Manager Year B',
            'full_name' => 'Bert Manager Year',
            'email' => 'mgr-b-year@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->teamA = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Year Alfa',
            'manager_id' => $this->managerA->id,
        ]);
        $this->teamB = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Year Beta',
            'manager_id' => $this->managerB->id,
        ]);
        $this->managerA->update(['team_id' => $this->teamA->id]);
        $this->managerB->update(['team_id' => $this->teamB->id]);

        for ($i = 1; $i <= 2; $i++) {
            $this->employeesTeamA[] = User::create([
                'name' => 'Emp Year A'.$i,
                'full_name' => 'Alpha '.$i.' Werknemer Year',
                'email' => 'emp-a'.$i.'-year@lavita.test',
                'password' => bcrypt('Wachtwoord1234'),
                'organization_id' => $this->org->id,
                'team_id' => $this->teamA->id,
                'role' => 'employee',
                'is_active' => true,
            ]);
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->employeesTeamB[] = User::create([
                'name' => 'Emp Year B'.$i,
                'full_name' => 'Beta '.$i.' Werknemer Year',
                'email' => 'emp-b'.$i.'-year@lavita.test',
                'password' => bcrypt('Wachtwoord1234'),
                'organization_id' => $this->org->id,
                'team_id' => $this->teamB->id,
                'role' => 'employee',
                'is_active' => true,
            ]);
        }

        $this->bookkeeper = User::create([
            'name' => 'Boekhouder Year',
            'full_name' => 'Bea Boekhouder Year',
            'email' => 'boek-year@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);
    }

    /**
     * Helper om een werkregel te seeden. De UNIQUE-index
     * `(employee_id, entry_date, start_at)` dwingt verschillende
     * `startTime`s af voor meerdere regels op dezelfde dag.
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
            'type' => $type,
            'is_finalized' => $isFinalized,
        ]);
    }

    public function test_employee_role_is_forbidden(): void
    {
        $employee = $this->employeesTeamA[0];

        $this->actingAs($employee);

        Livewire::test(YearExport::class)
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        // Defensief pad: zonder actingAs -> abort(403).
        Livewire::test(YearExport::class)
            ->assertForbidden();
    }

    public function test_default_year_is_current_year(): void
    {
        $this->actingAs($this->owner);

        $expectedYear = (int) Carbon::now('Europe/Amsterdam')->year;

        Livewire::test(YearExport::class)
            ->assertOk()
            ->assertSet('year', $expectedYear);
    }

    public function test_owner_can_call_year_export_service(): void
    {
        $this->actingAs($this->owner);

        // Drie verschillende werkregels in 2026 voor 2 medewerkers
        // (verschillende start_at om de UNIQUE-index te respecteren).
        $this->seedWorkEntry($this->employeesTeamA[0], '2026-03-01', 480, startTime: '08:00:00', endTime: '16:00:00');
        $this->seedWorkEntry($this->employeesTeamA[0], '2026-04-15', 240, startTime: '09:00:00', endTime: '13:00:00');
        $this->seedWorkEntry($this->employeesTeamB[0], '2026-06-01', 360, startTime: '10:00:00', endTime: '16:00:00');

        Livewire::test(YearExport::class)
            ->set('year', 2026)
            ->call('previewCount')
            ->assertSet('rowCount', 2) // 2 distinct employees
            ->assertSee('2 medewerkers gevonden voor 2026.');
    }

    public function test_year_filter_excludes_other_years(): void
    {
        $this->actingAs($this->owner);

        // 2025 entries voor employee A1, 2026 entries voor employee B1.
        $this->seedWorkEntry($this->employeesTeamA[0], '2025-06-15', 480, startTime: '08:00:00', endTime: '16:00:00');
        $this->seedWorkEntry($this->employeesTeamA[0], '2025-07-15', 480, startTime: '08:00:00', endTime: '16:00:00');
        $this->seedWorkEntry($this->employeesTeamB[0], '2026-06-15', 480, startTime: '08:00:00', endTime: '16:00:00');

        // Jaar 2025 → alleen employee A1.
        Livewire::test(YearExport::class)
            ->set('year', 2025)
            ->call('previewCount')
            ->assertSet('rowCount', 1);

        // Jaar 2026 → alleen employee B1.
        Livewire::test(YearExport::class)
            ->set('year', 2026)
            ->call('previewCount')
            ->assertSet('rowCount', 1);

        // Jaar zonder data → 0.
        Livewire::test(YearExport::class)
            ->set('year', 2024)
            ->call('previewCount')
            ->assertSet('rowCount', 0);
    }

    public function test_employee_filter_works(): void
    {
        $this->actingAs($this->owner);

        // 2 werkregels voor A1 en 1 voor A2 in 2026.
        $this->seedWorkEntry($this->employeesTeamA[0], '2026-02-01', 240, startTime: '08:00:00', endTime: '12:00:00');
        $this->seedWorkEntry($this->employeesTeamA[0], '2026-03-01', 240, startTime: '08:00:00', endTime: '12:00:00');
        $this->seedWorkEntry($this->employeesTeamA[1], '2026-04-01', 240, startTime: '08:00:00', endTime: '12:00:00');

        // Zonder employee-filter → 2 medewerkers.
        Livewire::test(YearExport::class)
            ->set('year', 2026)
            ->call('previewCount')
            ->assertSet('rowCount', 2);

        // Filter op A1 → 1 medewerker.
        Livewire::test(YearExport::class)
            ->set('year', 2026)
            ->set('employeeId', $this->employeesTeamA[0]->id)
            ->call('previewCount')
            ->assertSet('rowCount', 1);

        // Filter op A2 → 1 medewerker.
        Livewire::test(YearExport::class)
            ->set('year', 2026)
            ->set('employeeId', $this->employeesTeamA[1]->id)
            ->call('previewCount')
            ->assertSet('rowCount', 1);
    }

    public function test_invalid_year_returns_validation_error(): void
    {
        $this->actingAs($this->owner);

        // year=1899 → faalt op `min:1900`.
        Livewire::test(YearExport::class)
            ->set('year', 1899)
            ->call('previewCount')
            ->assertHasErrors(['year' => 'min']);

        // year=2100 → faalt op `max:2099`.
        Livewire::test(YearExport::class)
            ->set('year', 2100)
            ->call('previewCount')
            ->assertHasErrors(['year' => 'max']);
    }

    public function test_pdf_download_returns_file_download(): void
    {
        $this->actingAs($this->owner);

        $this->seedWorkEntry(
            $this->employeesTeamA[0],
            '2026-03-15',
            netMinutes: 480,
            startTime: '08:00:00',
            endTime: '16:00:00',
        );

        $component = Livewire::test(YearExport::class)
            ->set('year', 2026)
            ->call('downloadPdf');

        // Livewire 3 capteert StreamedResponse-downloads via de
        // SupportFileDownloads-feature; assertFileDownloaded() is de
        // canonieke assertie op de filename.
        $component->assertFileDownloaded();

        $effects = $component->effects;
        $this->assertArrayHasKey('download', $effects);
        $download = $effects['download'];
        $this->assertStringEndsWith('.pdf', (string) ($download['name'] ?? ''));
        $this->assertSame('application/pdf', $download['contentType'] ?? null);
        $this->assertStringContainsString('jaaroverzicht-2026', (string) ($download['name'] ?? ''));
    }

    public function test_render_shows_dutch_labels(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(YearExport::class)
            ->assertOk()
            ->assertSee('Jaaroverzicht')
            ->assertSee('Jaar')
            ->assertSee('Medewerker')
            ->assertSee('Toon aantal medewerkers')
            ->assertSee('Download PDF');
    }
}
