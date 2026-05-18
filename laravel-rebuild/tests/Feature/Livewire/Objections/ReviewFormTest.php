<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Objections;

use App\Livewire\Objections\ReviewForm;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\WorkEntriesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Atw\StatusDashboardTest;
use Tests\Feature\Livewire\Hours\WeekOverviewTableTest;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Objections\ReviewForm` (taak 11.2
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Forbidden-pad voor employee + boekhouder + anonieme requests.
 *  - 403-pad bij bezwaar van een andere organisatie.
 *  - Mount populeert `entryDate`, `employeeName`, originele tijden +
 *    pauze + netto, en de submitter-motivatie.
 *  - Reject met motivatie <10 tekens → veldfout, geen DB-mutatie.
 *  - Reject met geldige motivatie → status REJECTED in DB,
 *    `objection-reviewed` event gedispatched.
 *  - Accept met corrected times → werkregel bijgewerkt, status APPROVED.
 *  - Accept met end_time ≤ start_time → veldfout op `correctedEndTime`.
 *  - Reeds beoordeeld bezwaar → NL "al beoordeeld"-block + form
 *    gedeactiveerd.
 *  - View toont NL labels en knoppen.
 *
 * Rationale waarom we Livewire::test direct aanroepen i.p.v. een HTTP-route:
 *  - De web-route op `/bezwaren/{id}` wordt in een latere taak geregistreerd;
 *    taak 11.2 levert het component zelf, identiek aan de keuze in
 *    {@see StatusDashboardTest} en
 *    {@see WeekOverviewTableTest}.
 */
final class ReviewFormTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private Team $team;

    private User $owner;

    private User $manager;

    private User $employee;

    private User $employee2;

    private User $boekhouder;

    private User $otherOrgOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'LaVita Review Org',
        ]);

        $this->otherOrg = Organization::create([
            'name' => 'LaVita Andere Org',
        ]);

        $this->team = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Review',
        ]);

        $this->owner = User::create([
            'name' => 'Owner Review',
            'full_name' => 'Olga Owner',
            'email' => 'owner-review@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager Review',
            'full_name' => 'Mira Manager',
            'email' => 'mgr-review@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee Review',
            'full_name' => 'Eva Eén',
            'email' => 'emp1-review@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee2 = User::create([
            'name' => 'Employee Review 2',
            'full_name' => 'Erik Twee',
            'email' => 'emp2-review@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->boekhouder = User::create([
            'name' => 'Boekhouder Review',
            'full_name' => 'Bea Boekhouder',
            'email' => 'boek-review@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        $this->otherOrgOwner = User::create([
            'name' => 'Owner Andere Org',
            'full_name' => 'Otto Andere',
            'email' => 'owner-other-review@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->otherOrg->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->team->update(['manager_id' => $this->manager->id]);
    }

    /**
     * Seed een werkregel + open bezwaar in de gegeven organisatie. De
     * defaults vormen een 8u-shift met 30 min pauze (netto 450 min).
     *
     * Tijden worden geïnterpreteerd als Europe/Amsterdam-lokale tijd
     * (zelfde conventie als {@see WorkEntriesService::create()}),
     * en vervolgens naar UTC geconverteerd vóór opslag — zodat de
     * `originalStartTime`/`originalEndTime`-display in `Europe/Amsterdam`
     * weer terug naar dezelfde lokale tijd resulteert.
     */
    private function seedFinalizedEntryWithObjection(
        User $employee,
        string $isoDate,
        string $startTime = '08:00',
        string $endTime = '16:00',
        int $pauseMinutes = 30,
        int $netMinutes = 450,
        string $submitterMotivation = 'Ik heb hier echt langer gewerkt dan geregistreerd staat.',
    ): array {
        $startAtUtc = Carbon::createFromFormat('Y-m-d H:i', $isoDate.' '.$startTime, 'Europe/Amsterdam')->utc();
        $endAtUtc = Carbon::createFromFormat('Y-m-d H:i', $isoDate.' '.$endTime, 'Europe/Amsterdam')->utc();

        $entry = WorkEntry::create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'team_id' => $employee->team_id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $isoDate,
            'start_at' => $startAtUtc,
            'end_at' => $endAtUtc,
            'pause_minutes' => $pauseMinutes,
            'net_minutes' => $netMinutes,
            'is_finalized' => true,
        ]);

        $objection = Objection::create([
            'organization_id' => $employee->organization_id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $employee->id,
            'motivation' => $submitterMotivation,
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        return ['entry' => $entry, 'objection' => $objection];
    }

    public function test_employee_role_is_forbidden(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        $this->actingAs($this->employee);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->assertForbidden();
    }

    public function test_boekhouder_role_is_forbidden(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        $this->actingAs($this->boekhouder);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        // Geen actingAs -> abort(403) via mount-guard.
        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->assertForbidden();
    }

    public function test_objection_from_other_org_returns_403(): void
    {
        // Bezwaar in de andere organisatie.
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $foreignEmployee = User::create([
            'name' => 'Foreign Emp',
            'full_name' => 'Frans Vreemd',
            'email' => 'foreign-review@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->otherOrg->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $foreignEntry = WorkEntry::create([
            'organization_id' => $this->otherOrg->id,
            'employee_id' => $foreignEmployee->id,
            'registered_by_id' => $this->otherOrgOwner->id,
            'entry_date' => $isoDate,
            'start_at' => $isoDate.' 08:00:00',
            'end_at' => $isoDate.' 16:00:00',
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'is_finalized' => true,
        ]);

        $foreignObjection = Objection::create([
            'organization_id' => $this->otherOrg->id,
            'work_entry_id' => $foreignEntry->id,
            'submitted_by_id' => $foreignEmployee->id,
            'motivation' => 'Buiten-org bezwaar.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        // Owner van $this->org probeert het te beoordelen → 403.
        $this->actingAs($this->owner);

        Livewire::test(ReviewForm::class, ['objectionId' => $foreignObjection->id])
            ->assertForbidden();
    }

    public function test_mount_loads_original_entry_and_motivation(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection(
            $this->employee,
            $isoDate,
            '09:15',
            '17:45',
            30,
            480,
            'Ik miste een uur in de registratie.',
        );

        $this->actingAs($this->manager);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->assertOk()
            ->assertSet('objectionId', $seed['objection']->id)
            ->assertSet('entryDate', $isoDate)
            ->assertSet('employeeName', 'Eva Eén')
            ->assertSet('originalStartTime', '09:15')
            ->assertSet('originalEndTime', '17:45')
            ->assertSet('originalPauseMinutes', 30)
            ->assertSet('originalNetMinutes', 480)
            ->assertSet('submitterMotivation', 'Ik miste een uur in de registratie.')
            ->assertSet('status', 'OPEN')
            // Default corrected_*-velden moeten op de originele waarden staan.
            ->assertSet('correctedStartTime', '09:15')
            ->assertSet('correctedEndTime', '17:45')
            ->assertSet('correctedPauseMinutes', 30)
            // NL-labels en de submitter-motivatie zichtbaar in de view.
            ->assertSee('Bezwaar beoordelen')
            ->assertSee('Eva Eén')
            ->assertSee('Ik miste een uur in de registratie.')
            ->assertSee('Beoordeling motivatie')
            ->assertSee('Oorspronkelijke werkregel')
            ->assertSee('Status: Open');
    }

    public function test_reject_with_short_motivation_shows_error(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        $this->actingAs($this->manager);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->set('managerResponse', 'kort')
            ->call('reject')
            ->assertHasErrors('managerResponse');

        // Geen status-mutatie in DB.
        $this->assertDatabaseHas('objections', [
            'id' => $seed['objection']->id,
            'status' => 'OPEN',
        ]);
    }

    public function test_reject_with_valid_motivation_calls_service_and_sets_status(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        $managerResponse = 'Bezwaar afgewezen omdat registratie correct is.';

        $this->actingAs($this->manager);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->set('managerResponse', $managerResponse)
            ->call('reject')
            ->assertHasNoErrors()
            ->assertSet('status', 'REJECTED')
            ->assertDispatched('objection-reviewed');

        $this->assertDatabaseHas('objections', [
            'id' => $seed['objection']->id,
            'status' => 'REJECTED',
            'reviewed_by_id' => $this->manager->id,
        ]);

        // Werkregel mag NIET zijn aangepast bij REJECTED.
        $this->assertDatabaseHas('work_entries', [
            'id' => $seed['entry']->id,
            'pause_minutes' => 30,
            'net_minutes' => 450,
        ]);
    }

    public function test_accept_with_corrections_calls_service_and_updates_work_entry(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection(
            $this->employee,
            $isoDate,
            '08:00',
            '16:00',
            30,
            450,
        );

        $this->actingAs($this->manager);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->set('correctedStartTime', '08:00')
            ->set('correctedEndTime', '17:00')
            ->set('correctedPauseMinutes', 30)
            ->set('managerResponse', '')
            ->call('accept')
            ->assertHasNoErrors()
            ->assertSet('status', 'APPROVED')
            ->assertDispatched('objection-reviewed');

        $this->assertDatabaseHas('objections', [
            'id' => $seed['objection']->id,
            'status' => 'APPROVED',
            'reviewed_by_id' => $this->manager->id,
            'corrected_pause_minutes' => 30,
        ]);

        // Werkregel moet de gecorrigeerde tijden bevatten. Gross 9u −
        // 30 min pauze = 510 min netto.
        $entry = WorkEntry::find($seed['entry']->id);
        $this->assertNotNull($entry);
        $this->assertSame(30, (int) $entry->pause_minutes);
        $this->assertSame(510, (int) $entry->net_minutes);
        $this->assertSame('17:00', $entry->end_at?->copy()->setTimezone('Europe/Amsterdam')->format('H:i'));
    }

    public function test_accept_with_invalid_corrected_times_shows_field_error(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        $this->actingAs($this->manager);

        // End vóór start → service throwt ValidationException op
        // `corrected_end_time`, view mapt naar `correctedEndTime`.
        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->set('correctedStartTime', '17:00')
            ->set('correctedEndTime', '08:00')
            ->set('correctedPauseMinutes', 30)
            ->call('accept')
            ->assertHasErrors('correctedEndTime');

        // Status moet OPEN gebleven zijn.
        $this->assertDatabaseHas('objections', [
            'id' => $seed['objection']->id,
            'status' => 'OPEN',
        ]);
    }

    public function test_already_reviewed_objection_shows_status_block_and_disables_form(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        // Pre-set status op APPROVED zodat mount het herkent.
        $seed['objection']->update([
            'status' => 'APPROVED',
            'reviewed_by_id' => $this->owner->id,
            'reviewed_at' => now(),
            'manager_response' => 'Eerder al beoordeeld.',
        ]);

        $this->actingAs($this->manager);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->assertOk()
            ->assertSet('status', 'APPROVED')
            ->assertSee('al beoordeeld')
            ->assertSee('Geaccepteerd');
    }

    public function test_render_shows_dutch_labels_and_buttons(): void
    {
        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $seed = $this->seedFinalizedEntryWithObjection($this->employee, $isoDate);

        $this->actingAs($this->manager);

        Livewire::test(ReviewForm::class, ['objectionId' => $seed['objection']->id])
            ->assertOk()
            ->assertSee('Bezwaar beoordelen')
            ->assertSee('Beoordeling motivatie')
            ->assertSee('Oorspronkelijke werkregel')
            ->assertSee('Begintijd')
            ->assertSee('Eindtijd')
            ->assertSee('Pauze')
            ->assertSee('Netto')
            ->assertSee('Motivatie van medewerker')
            ->assertSee('Gecorrigeerde begintijd')
            ->assertSee('Gecorrigeerde eindtijd')
            ->assertSee('Gecorrigeerde pauze (min)')
            ->assertSee('Afwijzen')
            ->assertSee('Accepteren')
            ->assertSee('0 / 1000 tekens');
    }
}
