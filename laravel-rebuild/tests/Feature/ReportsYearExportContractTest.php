<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\ReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-contract-test voor het nieuwe endpoint
 * `GET /api/internal/reports/year-export?year=&employee_id=` (taak 12.2
 * spec lavita-urenregistratie — Requirement 6.7 jaaroverzicht-tab,
 * Requirement 14.5 endpoint, NFR-9 7-jaars retentie).
 *
 * Dekt:
 *  - Owner kan het endpoint aanroepen en krijgt 200 + PDF-content-type.
 *  - Boekhouder mag de fiscale export bekijken (parity met
 *    {@see ReportsModuleContractTest::test_boekhouder_can_export_pdf_report()}).
 *  - Year < 1900 → 422 met validation-error op `year`.
 *  - Year > 2099 → 422 met validation-error op `year`.
 *  - Employee-rol → 403 (gegooid door
 *    {@see ReportQueryService::yearExport()}).
 *  - Manager ziet alleen team-eigen werkregels (defensief getest via de
 *    onderliggende service-call zoals
 *    {@see ReportsModuleContractTest::test_employee_sees_only_own_entries_in_report()}).
 */
class ReportsYearExportContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $managerA;

    private User $managerB;

    private Team $teamA;

    private Team $teamB;

    private User $employeeA;

    private User $employeeB;

    private User $boekhouder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Year Export BV']);

        $this->owner = User::create([
            'name' => 'Owner YE',
            'email' => 'own-ye@t.nl',
            'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->managerA = User::create([
            'name' => 'Manager YE A',
            'email' => 'mgr-a-ye@t.nl',
            'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->managerB = User::create([
            'name' => 'Manager YE B',
            'email' => 'mgr-b-ye@t.nl',
            'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->teamA = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team YE Alfa',
            'manager_id' => $this->managerA->id,
        ]);
        $this->teamB = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team YE Beta',
            'manager_id' => $this->managerB->id,
        ]);
        $this->managerA->update(['team_id' => $this->teamA->id]);
        $this->managerB->update(['team_id' => $this->teamB->id]);

        $this->employeeA = User::create([
            'name' => 'Employee YE A',
            'full_name' => 'Test Employee YE A',
            'email' => 'emp-a-ye@t.nl',
            'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamA->id,
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->employeeB = User::create([
            'name' => 'Employee YE B',
            'full_name' => 'Test Employee YE B',
            'email' => 'emp-b-ye@t.nl',
            'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
            'team_id' => $this->teamB->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->boekhouder = User::create([
            'name' => 'Boekhouder YE',
            'full_name' => 'Test Boekhouder YE',
            'email' => 'boek-ye@t.nl',
            'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);
    }

    private function createEntry(User $employee, string $date, string $startTime = '08:00:00'): WorkEntry
    {
        return WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $employee->id,
            'team_id' => $employee->team_id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $date,
            'start_at' => $date.' '.$startTime,
            'end_at' => $date.' 16:00:00',
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'is_finalized' => true,
        ]);
    }

    public function test_owner_can_export_year_pdf(): void
    {
        $this->createEntry($this->employeeA, '2026-03-15');

        $response = $this->getWithAuth($this->owner, '/api/internal/reports/year-export?year=2026');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('jaaroverzicht-2026.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_boekhouder_can_export_year_pdf(): void
    {
        $this->createEntry($this->employeeA, '2026-03-15');

        $response = $this->getWithAuth($this->boekhouder, '/api/internal/reports/year-export?year=2026');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_year_too_low_returns_422(): void
    {
        // Accept: application/json zorgt dat Laravel 422 (i.p.v. 302
        // form-redirect) retourneert bij validatie-fouten op GET-endpoints.
        $response = $this->getWithAuth(
            $this->owner,
            '/api/internal/reports/year-export?year=1899',
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['year']);
    }

    public function test_year_too_high_returns_422(): void
    {
        $response = $this->getWithAuth(
            $this->owner,
            '/api/internal/reports/year-export?year=2100',
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['year']);
    }

    public function test_year_missing_returns_422(): void
    {
        $response = $this->getWithAuth(
            $this->owner,
            '/api/internal/reports/year-export',
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['year']);
    }

    public function test_employee_role_is_forbidden(): void
    {
        $this->createEntry($this->employeeA, '2026-03-15');

        $response = $this->getWithAuth($this->employeeA, '/api/internal/reports/year-export?year=2026');

        $response->assertStatus(403);
    }

    public function test_manager_only_sees_own_team_entries(): void
    {
        // 1 entry in team A, 1 entry in team B.
        $this->createEntry($this->employeeA, '2026-03-15');
        $this->createEntry($this->employeeB, '2026-03-16');

        // Defensieve assertie via de service: manager-A ziet 1 employee,
        // manager-B ziet 1 employee, owner ziet 2.
        $service = app(ReportQueryService::class);

        $managerAResult = $service->yearExport($this->managerA->id, 2026);
        $this->assertCount(1, $managerAResult['employees']);
        $this->assertSame($this->employeeA->id, $managerAResult['employees'][0]['employee_id']);

        $managerBResult = $service->yearExport($this->managerB->id, 2026);
        $this->assertCount(1, $managerBResult['employees']);
        $this->assertSame($this->employeeB->id, $managerBResult['employees'][0]['employee_id']);

        $ownerResult = $service->yearExport($this->owner->id, 2026);
        $this->assertCount(2, $ownerResult['employees']);

        // Bevestig daarnaast dat het HTTP-endpoint zelf 200 retourneert
        // voor manager-A.
        $response = $this->getWithAuth($this->managerA, '/api/internal/reports/year-export?year=2026');
        $response->assertStatus(200);
    }

    public function test_employee_id_filter_narrows_result(): void
    {
        // 2 medewerkers in team A en B met elk 1 entry in 2026.
        $this->createEntry($this->employeeA, '2026-03-15');
        $this->createEntry($this->employeeB, '2026-04-15');

        // Owner met employee_id=A → 200 + alleen A in de aggregatie.
        $response = $this->getWithAuth(
            $this->owner,
            '/api/internal/reports/year-export?year=2026&employee_id='.$this->employeeA->id,
        );
        $response->assertStatus(200);

        // Defensieve service-assertie omdat de PDF zelf opaque is.
        $service = app(ReportQueryService::class);
        $result = $service->yearExport($this->owner->id, 2026, $this->employeeA->id);
        $this->assertCount(1, $result['employees']);
        $this->assertSame($this->employeeA->id, $result['employees'][0]['employee_id']);
    }
}
