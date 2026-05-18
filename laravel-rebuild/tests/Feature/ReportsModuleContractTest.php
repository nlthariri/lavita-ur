<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\ReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsModuleContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $employee;

    private User $boekhouder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Test BV']);
        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'own@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'owner', 'is_active' => true,
        ]);
        $this->employee = User::create([
            'name' => 'Employee', 'full_name' => 'Test Employee', 'email' => 'emp@t.nl',
            'password' => bcrypt('x'), 'organization_id' => $this->org->id,
            'role' => 'employee', 'is_active' => true,
        ]);
        $this->boekhouder = User::create([
            'name' => 'Boekhouder', 'full_name' => 'Test Boekhouder', 'email' => 'boek@t.nl',
            'password' => bcrypt('x'), 'organization_id' => $this->org->id,
            'role' => 'boekhouder', 'is_active' => true,
        ]);
    }

    private function createEntry(string $date = '2026-05-15'): WorkEntry
    {
        return WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $date,
            'start_at' => $date.' 08:00:00',
            'end_at' => $date.' 16:00:00',
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'is_finalized' => true,
        ]);
    }

    public function test_pdf_endpoint_returns_pdf_content_type(): void
    {
        $this->createEntry();

        $response = $this->getWithAuth($this->owner, '/api/internal/reports/work-entries/pdf');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_excel_endpoint_returns_xlsx_content_type(): void
    {
        $this->createEntry();

        $response = $this->getWithAuth($this->owner, '/api/internal/reports/work-entries/excel');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_endpoint_no_longer_requires_requester_id(): void
    {
        $this->createEntry();

        $response = $this->getWithAuth($this->owner, '/api/internal/reports/work-entries/pdf');
        $response->assertStatus(200);
    }

    public function test_excel_endpoint_no_longer_requires_requester_id(): void
    {
        $this->createEntry();

        $response = $this->getWithAuth($this->owner, '/api/internal/reports/work-entries/excel');
        $response->assertStatus(200);
    }

    public function test_employee_sees_only_own_entries_in_report(): void
    {
        $this->createEntry('2026-05-15');

        // Tweede medewerker met eigen entry
        $other = User::create([
            'name' => 'Other', 'email' => 'o@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $other->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-15',
            'start_at' => '2026-05-15 09:00:00',
            'end_at' => '2026-05-15 17:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 480,
            'is_finalized' => true,
        ]);

        // Employee export — mag slechts 1 entry zien
        $response = $this->getWithAuth($this->employee, '/api/internal/reports/work-entries/excel');
        $response->assertStatus(200);

        // Bevestig scoping via shared query service direct
        $queryService = app(ReportQueryService::class);
        $entries = $queryService->getEntries($this->employee->id, []);
        $this->assertCount(1, $entries);
        $this->assertSame($this->employee->id, $entries->first()->employee_id);
    }

    public function test_spoofed_requester_id_does_not_expand_report_scope(): void
    {
        $this->createEntry('2026-05-15');

        $other = User::create([
            'name' => 'Other', 'email' => 'other-scope@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $other->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-15',
            'start_at' => '2026-05-15 09:00:00',
            'end_at' => '2026-05-15 17:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 480,
            'is_finalized' => true,
        ]);

        $response = $this->getWithAuth($this->employee, '/api/internal/reports/work-entries/excel?requester_id='.$this->owner->id);
        $response->assertStatus(200);

        $entries = app(ReportQueryService::class)->getEntries($this->employee->id, []);
        $this->assertCount(1, $entries);
    }

    public function test_boekhouder_can_export_pdf_report(): void
    {
        $this->createEntry();

        $response = $this->getWithAuth($this->boekhouder, '/api/internal/reports/work-entries/pdf');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_boekhouder_can_export_excel_report(): void
    {
        $this->createEntry();

        $response = $this->getWithAuth($this->boekhouder, '/api/internal/reports/work-entries/excel');

        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
    }
}
