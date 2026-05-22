<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Hours;

use App\Livewire\Hours\MyWeek;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Hours\MyWeek` (taak 10.3 spec
 * lavita-urenregistratie).
 *
 * Dekt:
 *  - Boekhouder krijgt 403 bij dit scherm (geen eigen urenregels).
 *  - Employee ziet alleen eigen werkregels (cross-employee-isolatie).
 *  - Finalized regel zonder open bezwaar → "Bezwaar indienen"-knop.
 *  - Finalized regel met open bezwaar → "Bezwaar open"-badge en geen knop.
 *  - Niet-finalized regel → "Concept"-badge en geen knop.
 *  - Week-navigatie verschuift `weekStart` met 7 dagen.
 *  - Render geeft 200 + NL-headings.
 *  - Lege week toont NL-melding.
 *  - Bezwaarknop dispatched het juiste event met workEntryId payload.
 */
final class MyWeekTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $team;

    private User $owner;

    private User $manager;

    private User $employee1;

    private User $employee2;

    private User $bookkeeper;

    private string $weekStartIso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->weekStartIso = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();

        $this->org = Organization::create([
            'name' => 'LaVita MyWeek Org',
        ]);

        $this->team = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team MyWeek',
        ]);

        $this->owner = User::create([
            'name' => 'Owner MW',
            'full_name' => 'Olaf Owner',
            'email' => 'owner-mw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager MW',
            'full_name' => 'Mira Manager',
            'email' => 'mgr-mw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->employee1 = User::create([
            'name' => 'Employee MW 1',
            'full_name' => 'Eva Een',
            'email' => 'emp1-mw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee2 = User::create([
            'name' => 'Employee MW 2',
            'full_name' => 'Erik Twee',
            'email' => 'emp2-mw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->bookkeeper = User::create([
            'name' => 'Bookkeeper MW',
            'full_name' => 'Bea Boekhouder',
            'email' => 'boek-mw@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        $this->team->update(['manager_id' => $this->manager->id]);
    }

    /**
     * Helper voor het seeden van een werkregel. UNIQUE-index op
     * `(employee_id, entry_date, start_at)` betekent dat meerdere
     * entries op dezelfde dag verschillende start-tijden moeten hebben.
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
            'is_finalized' => $isFinalized,
        ]);
    }

    public function test_boekhouder_is_forbidden(): void
    {
        $this->actingAs($this->bookkeeper);

        Livewire::test(MyWeek::class)->assertForbidden();
    }

    public function test_unauthenticated_request_is_forbidden(): void
    {
        // Defensief pad — zonder actingAs verwacht de mount() abort(403).
        Livewire::test(MyWeek::class)->assertForbidden();
    }

    public function test_employee_sees_only_their_own_entries(): void
    {
        $monday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');

        // Employee 1 heeft één regel; employee 2 ook (maar een andere
        // dienst zodat we de cross-isolation kunnen testen).
        $myEntry = $this->seedWorkEntry($this->employee1, $monday->toDateString(), 480);
        $foreignEntry = $this->seedWorkEntry($this->employee2, $monday->toDateString(), 360);

        $this->actingAs($this->employee1);

        $component = Livewire::test(MyWeek::class)->assertOk();

        $grouped = $component->instance()->getEntriesGroupedByDay();
        $allIds = [];
        foreach ($grouped as $day) {
            foreach ($day as $row) {
                $allIds[] = (int) $row['id'];
            }
        }

        $this->assertContains($myEntry->id, $allIds, 'Eigen werkregel moet zichtbaar zijn.');
        $this->assertNotContains($foreignEntry->id, $allIds, 'Werkregel van andere medewerker moet onzichtbaar blijven.');
    }

    public function test_finalized_entry_without_objection_shows_objection_button(): void
    {
        $monday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');
        $entry = $this->seedWorkEntry($this->employee1, $monday->toDateString(), 480, isFinalized: true);

        $this->actingAs($this->employee1);

        // In the timeline design, the objection button is in the expandable detail panel
        Livewire::test(MyWeek::class)
            ->assertOk()
            ->call('toggleDetail', $entry->id)
            ->assertSee('Bezwaar indienen')
            ->assertDontSee('Bezwaar open');
    }

    public function test_finalized_entry_with_open_objection_shows_warning_badge_not_button(): void
    {
        $monday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');
        $entry = $this->seedWorkEntry($this->employee1, $monday->toDateString(), 480, isFinalized: true);

        Objection::create([
            'organization_id' => $this->employee1->organization_id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $this->employee1->id,
            'motivation' => 'Onjuiste tijden geregistreerd.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->employee1);

        $component = Livewire::test(MyWeek::class)
            ->assertOk();

        // The objection icon with title "Bezwaar open" should be visible in the timeline
        $html = $component->html();
        $this->assertStringContainsString(
            'Bezwaar open',
            $html,
            'De bezwaar-icoon met title "Bezwaar open" moet zichtbaar zijn in de tijdlijn.'
        );

        // Expand the detail panel - the objection button should NOT be shown
        $component->call('toggleDetail', $entry->id);
        $html = $component->html();

        $this->assertStringNotContainsString(
            "open-new-objection', { workEntryId: {$entry->id} }",
            $html,
            'De bezwaarknop met deze entry-id mag niet renderen wanneer er al een open bezwaar is.'
        );
    }

    public function test_draft_entry_shows_concept_badge(): void
    {
        $monday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');
        $entry = $this->seedWorkEntry($this->employee1, $monday->toDateString(), 480, isFinalized: false);

        $this->actingAs($this->employee1);

        // In the timeline design, status is shown in the expandable detail panel
        Livewire::test(MyWeek::class)
            ->assertOk()
            ->call('toggleDetail', $entry->id)
            ->assertSee('Concept')
            ->assertDontSee('Bezwaar indienen');
    }

    public function test_navigation_methods_change_week_start_by_seven_days(): void
    {
        $this->actingAs($this->employee1);

        $component = Livewire::test(MyWeek::class)->assertOk();

        $start = $component->get('weekStart');

        $expectedPrev = Carbon::parse($start, 'Europe/Amsterdam')->subDays(7)->toDateString();
        $component->call('previousWeek')->assertSet('weekStart', $expectedPrev);

        $expectedNext = Carbon::parse($expectedPrev, 'Europe/Amsterdam')->addDays(7)->toDateString();
        $component->call('nextWeek')->assertSet('weekStart', $expectedNext);

        $today = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $component->call('goToToday')->assertSet('weekStart', $today);
    }

    public function test_render_returns_200_with_nl_headings(): void
    {
        $this->actingAs($this->employee1);

        Livewire::test(MyWeek::class)
            ->assertOk()
            ->assertSee('Mijn week')
            ->assertSee('Vorige week')
            ->assertSee('Vandaag')
            ->assertSee('Volgende week');
    }

    public function test_empty_week_shows_dutch_empty_state(): void
    {
        $this->actingAs($this->employee1);

        Livewire::test(MyWeek::class)
            ->assertOk()
            ->assertSee('Er zijn nog geen uren voor deze week.');
    }

    public function test_dispatch_open_new_objection_event_when_button_clicked(): void
    {
        // We toetsen niet de daadwerkelijke JS-dispatch (Livewire-3
        // PHP-tests exposen dat niet direct), maar wel dat de rendered
        // HTML het juiste `wire:click="$dispatch(...)"`-attribuut bevat
        // met het correcte workEntryId-payload.
        $monday = Carbon::parse($this->weekStartIso, 'Europe/Amsterdam');
        $entry = $this->seedWorkEntry($this->employee1, $monday->toDateString(), 480, isFinalized: true);

        $this->actingAs($this->employee1);

        // Expand the detail panel first (timeline design requires click to expand)
        $component = Livewire::test(MyWeek::class)
            ->assertOk()
            ->call('toggleDetail', $entry->id);

        $html = $component->html();

        // Patroon dat zowel single als double quotes accepteert (Livewire
        // kan het attribuut quoten met enkelvoudige of dubbelvoudige
        // quotes na escape-rendering).
        $this->assertMatchesRegularExpression(
            '/\$dispatch\(\s*[\'\"]?\&\#039\;?open-new-objection\&\#039\;?[\'\"]?|\$dispatch\(\s*[\'\"]?open-new-objection[\'\"]?/u',
            $html,
            'Render moet het open-new-objection-event in een wire:click bevatten.'
        );

        $this->assertStringContainsString(
            (string) $entry->id,
            $html,
            'De entry-id moet in de wire:click-payload terugkomen.'
        );
    }
}
