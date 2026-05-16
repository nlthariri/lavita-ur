<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectionsModuleContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $boekhouder;
    private WorkEntry $entry;
    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Test BV']);
        $this->team = Team::create(['organization_id' => $this->org->id, 'name' => 'Team A']);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'owner', 'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager', 'email' => 'mgr@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'team_id' => $this->team->id,
            'role' => 'manager', 'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee', 'email' => 'emp@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'team_id' => $this->team->id,
            'role' => 'employee', 'is_active' => true,
        ]);

        $this->boekhouder = User::create([
            'name' => 'Boekhouder', 'email' => 'boek@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id,
            'role' => 'boekhouder', 'is_active' => true,
        ]);

        $this->entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => '2026-05-15',
            'start_at' => '2026-05-15 08:00:00',
            'end_at' => '2026-05-15 12:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 240,
            'is_finalized' => true,
        ]);
    }

    public function test_employee_can_submit_objection_on_own_entry(): void
    {
        $response = $this->postWithAuth($this->employee, '/api/internal/objections', [
            'work_entry_id' => $this->entry->id,
            'motivation' => 'De begintijd klopt niet, ik was eerder aanwezig.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'OPEN')
            ->assertJsonPath('work_entry_id', $this->entry->id)
            ->assertJsonPath('submitted_by_id', $this->employee->id);

        $this->assertDatabaseHas('objections', [
            'work_entry_id' => $this->entry->id,
            'status' => 'OPEN',
        ]);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->owner->email,
            'type' => 'objection_submitted',
            'status' => 'queued',
        ]);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->manager->email,
            'type' => 'objection_submitted',
            'status' => 'queued',
        ]);
    }

    public function test_employee_cannot_submit_objection_on_other_employees_entry(): void
    {
        $other = User::create([
            'name' => 'Other', 'email' => 'o@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $response = $this->postWithAuth($other, '/api/internal/objections', [
            'work_entry_id' => $this->entry->id,
            'motivation' => 'Bezwaar op andermans uren.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.work_entry_id.0', 'U mag alleen bezwaar indienen op uw eigen urenregels.');
    }

    public function test_owner_cannot_submit_objection(): void
    {
        $response = $this->postWithAuth($this->owner, '/api/internal/objections', [
            'work_entry_id' => $this->entry->id,
            'motivation' => 'Eigenaar probeert bezwaar in te dienen.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.submitter.0', 'Alleen medewerkers kunnen bezwaar indienen.');
    }

    public function test_duplicate_open_objection_is_rejected(): void
    {
        Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Eerste bezwaar.',
            'status' => 'OPEN',
        ]);

        $response = $this->postWithAuth($this->employee, '/api/internal/objections', [
            'work_entry_id' => $this->entry->id,
            'motivation' => 'Tweede bezwaar op zelfde werkregel.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.work_entry_id.0', 'Er is al een openstaand bezwaar voor deze werkregel.');
    }

    public function test_owner_can_approve_open_objection(): void
    {
        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Foutieve starttijd.',
            'status' => 'OPEN',
        ]);

        $response = $this->postWithAuth($this->owner, "/api/internal/objections/{$objection->id}/review", [
            'decision' => 'APPROVED',
            'manager_response' => 'Correctie doorgevoerd.',
            'corrected_start_time' => '07:30',
            'corrected_end_time' => '12:00',
            'corrected_pause_minutes' => 30,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'APPROVED')
            ->assertJsonPath('reviewed_by_id', $this->owner->id);

        $this->assertDatabaseHas('objections', [
            'id' => $objection->id,
            'status' => 'APPROVED',
        ]);

        $this->assertDatabaseHas('work_entries', [
            'id' => $this->entry->id,
            'pause_minutes' => 30,
            'net_minutes' => 240,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'objection_approved_work_entry_corrected')
            ->where('target_type', 'work_entry')
            ->where('target_id', (string) $this->entry->id)
            ->first();
        $this->assertNotNull($audit);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->employee->email,
            'type' => 'objection_reviewed',
            'status' => 'queued',
        ]);
    }

    public function test_approval_requires_explicit_correction_payload(): void
    {
        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Foutieve starttijd.',
            'status' => 'OPEN',
        ]);

        $response = $this->postWithAuth($this->owner, "/api/internal/objections/{$objection->id}/review", [
            'decision' => 'APPROVED',
            'manager_response' => 'Akkoord.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.correction.0', 'Bij goedkeuring zijn corrected_start_time, corrected_end_time en corrected_pause_minutes verplicht.');
    }

    public function test_rejection_requires_manager_response(): void
    {
        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Foutieve eindtijd.',
            'status' => 'OPEN',
        ]);

        $response = $this->postWithAuth($this->owner, "/api/internal/objections/{$objection->id}/review", [
            'decision' => 'REJECTED',
            // manager_response ontbreekt — moet 422 geven
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.manager_response.0', 'Motivatie is verplicht bij afwijzing.');
    }

    public function test_review_of_already_reviewed_objection_is_idempotent_rejected(): void
    {
        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Al beoordeeld bezwaar.',
            'status' => 'APPROVED',
            'reviewed_by_id' => $this->owner->id,
            'reviewed_at' => now(),
        ]);

        $response = $this->postWithAuth($this->owner, "/api/internal/objections/{$objection->id}/review", [
            'decision' => 'REJECTED',
            'manager_response' => 'Tweede poging.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Dit bezwaar is al beoordeeld (status: APPROVED).');
    }

    public function test_employee_list_scoped_to_own_entries(): void
    {
        Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Mijn bezwaar.',
            'status' => 'OPEN',
        ]);

        $response = $this->getWithAuth($this->employee, '/api/internal/objections');

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonStructure(['data', 'count']);
    }

    public function test_submit_requires_minimum_motivation_length(): void
    {
        $response = $this->postWithAuth($this->employee, '/api/internal/objections', [
            'work_entry_id' => $this->entry->id,
            'motivation' => 'kort',  // < 10 tekens
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['motivation']);
    }

    public function test_spoofed_submitter_id_is_ignored_and_owner_cannot_submit_as_employee(): void
    {
        $response = $this->postWithAuth($this->owner, '/api/internal/objections', [
            'submitter_id' => $this->employee->id,
            'work_entry_id' => $this->entry->id,
            'motivation' => 'Ik probeer namens medewerker in te dienen.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.submitter.0', 'Alleen medewerkers kunnen bezwaar indienen.');
    }

    public function test_spoofed_reviewer_id_is_ignored_and_employee_cannot_review_as_owner(): void
    {
        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Review mij niet als medewerker.',
            'status' => 'OPEN',
        ]);

        $response = $this->postWithAuth($this->employee, "/api/internal/objections/{$objection->id}/review", [
            'reviewer_id' => $this->owner->id,
            'decision' => 'APPROVED',
            'manager_response' => 'gespoofte owner',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.reviewer.0', 'Alleen eigenaar of manager mag bezwaren beoordelen.');
    }

    public function test_boekhouder_cannot_submit_objection(): void
    {
        $response = $this->postWithAuth($this->boekhouder, '/api/internal/objections', [
            'work_entry_id' => $this->entry->id,
            'motivation' => 'Boekhouder probeert muterende actie uit te voeren.',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Boekhouder heeft alleen read-only rapportage toegang.');
    }

    public function test_boekhouder_cannot_review_objection(): void
    {
        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Review mij niet als boekhouder.',
            'status' => 'OPEN',
        ]);

        $response = $this->postWithAuth($this->boekhouder, "/api/internal/objections/{$objection->id}/review", [
            'decision' => 'REJECTED',
            'manager_response' => 'Onterecht als boekhouder.',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Boekhouder heeft alleen read-only rapportage toegang.');
    }

    // ─── Regressietests: e-mail triggers en cross-org isolatie ──────────────

    public function test_rejected_objection_dispatches_reviewed_email_to_employee(): void
    {
        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Ik was later vertrokken dan geregistreerd.',
            'status' => 'OPEN',
        ]);

        $this->postWithAuth($this->owner, "/api/internal/objections/{$objection->id}/review", [
            'decision' => 'REJECTED',
            'manager_response' => 'Tijdregistratie is correct, geen correctie nodig.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'REJECTED');

        // Medewerker moet ook bij REJECTED een e-mailnotificatie ontvangen
        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'recipient' => $this->employee->email,
            'type' => 'objection_reviewed',
            'status' => 'queued',
        ]);
    }

    public function test_cross_org_manager_cannot_review_foreign_objection(): void
    {
        $orgB = \App\Models\Organization::create(['name' => 'Org B']);
        $managerB = \App\Models\User::create([
            'name' => 'Manager B', 'email' => 'mgr-b@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $orgB->id, 'role' => 'manager', 'is_active' => true,
        ]);

        $objection = Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Bezwaar in org A.',
            'status' => 'OPEN',
        ]);

        $this->postWithAuth($managerB, "/api/internal/objections/{$objection->id}/review", [
            'decision' => 'REJECTED',
            'manager_response' => 'Onterecht cross-org review.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objection']);
    }

    public function test_cross_org_employee_cannot_see_foreign_objections(): void
    {
        $orgB = \App\Models\Organization::create(['name' => 'Org B']);
        $employeeB = \App\Models\User::create([
            'name' => 'Emp B', 'email' => 'emp-b@t.nl', 'password' => bcrypt('x'),
            'organization_id' => $orgB->id, 'role' => 'employee', 'is_active' => true,
        ]);

        Objection::create([
            'organization_id' => $this->org->id,
            'work_entry_id' => $this->entry->id,
            'submitted_by_id' => $this->employee->id,
            'motivation' => 'Bezwaar in org A dat org B niet mag zien.',
            'status' => 'OPEN',
        ]);

        $response = $this->getWithAuth($employeeB, '/api/internal/objections');

        $response->assertStatus(200)
            ->assertJsonPath('count', 0);
    }
}
