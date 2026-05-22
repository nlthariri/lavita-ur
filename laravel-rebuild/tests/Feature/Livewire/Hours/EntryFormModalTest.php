<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Hours;

use App\Livewire\Hours\EntryFormModal;
use App\Models\CostCenter;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Hours\EntryFormModal` (taak 10.2
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Modal opent leeg en rendert geen formulier wanneer dicht.
 *  - Open-event vult `employeeId` + `entryDate` en zet `isOpen = true`.
 *  - {@see EntryFormModal::getNetMinutes()} = max(0, gross - pauze) over
 *    een dataset incl. negatief-gross-edge-case.
 *  - Live ATW-validatie zet `atwResult` met de juiste shape.
 *  - Kritieke ATW-signalen blokkeren submit (geen werkregel in DB,
 *    `atw`-error op de component).
 *  - Succesvolle submit roept `WorkEntriesService::create` aan,
 *    dispatched `entry-saved`, en sluit de modal.
 *  - Employee mag HOLIDAY-type niet kiezen (Req 7.2 view-side filter).
 *  - `closeModal()` reset alle invoer-velden naar default-waarden.
 *
 * Rationale waarom we Livewire::test direct aanroepen i.p.v. een HTTP-
 * route: het component leeft binnen `Hours\WeekOverviewTable` en kent
 * geen eigen route; `Livewire::test()` rendert het component met de
 * actieve user-sessie via `actingAs`.
 */
final class EntryFormModalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $team;

    private User $owner;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'LaVita Modal Org',
            // Leg de ATW-policy expliciet vast zodat de tests
            // deterministisch zijn ongeacht eventuele defaults op
            // `organizations` in latere migraties.
            'atw_daily_max_minutes' => 720,
            'atw_weekly_max_minutes' => 3600,
            'atw_weekly_warning_minutes' => 2880,
            'atw_average_16_week_minutes' => 2880,
        ]);

        $this->team = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Modal',
        ]);

        $this->owner = User::create([
            'name' => 'Owner Modal',
            'full_name' => 'Olivier Owner',
            'email' => 'owner-modal@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager Modal',
            'full_name' => 'Mira Manager',
            'email' => 'mgr-modal@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee Modal',
            'full_name' => 'Eva Employee',
            'email' => 'emp-modal@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Zet de manager als manager van het team zodat
        // `assertCanAccessEmployee` een team-binding heeft (anders levert
        // de service een 422 met "Manager moet gekoppeld zijn aan een
        // team").
        $this->team->update(['manager_id' => $this->manager->id]);
    }

    public function test_modal_starts_closed_and_renders_empty_block(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(EntryFormModal::class)
            ->assertOk()
            ->assertSet('isOpen', false)
            ->assertDontSee('Uurregel toevoegen');
    }

    public function test_open_event_sets_props_and_isopen_true(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(EntryFormModal::class)
            ->dispatch(
                'open-entry-form-modal',
                employeeId: $this->employee->id,
                entryDate: '2026-05-15'
            )
            ->assertSet('isOpen', true)
            ->assertSet('employeeId', $this->employee->id)
            ->assertSet('entryDate', '2026-05-15')
            ->assertSee('Uurregel toevoegen')
            ->assertSee('Datum')
            ->assertSee('Begintijd')
            ->assertSee('Eindtijd')
            ->assertSee('Pauze (in minuten)');

        // Default-waarden moeten ingesteld zijn.
        $component->assertSet('startTime', '08:00')
            ->assertSet('endTime', '17:00')
            ->assertSet('pauseMinutes', 30)
            ->assertSet('type', 'WORK');
    }

    /**
     * Bewust geen `@dataProvider`-docblock-tag: PHPUnit 11 vereist de
     * `#[DataProvider]`-attribuut in plaats van de docblock-syntax.
     */
    #[DataProvider('netMinutesDataset')]
    public function test_get_net_minutes_returns_max_zero_gross_minus_pauze(
        string $start,
        string $end,
        int $pause,
        int $expectedNet,
    ): void {
        $this->actingAs($this->manager);

        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15')
            // We gebruiken set() i.p.v. wire:model.live op de view zodat we
            // de pure rekenfunctie testen los van ATW-validatie. ATW gaat
            // door hetzelfde codepad maar haar response is hier niet de
            // assertie — de net-minutes-formule wel.
            ->set('startTime', $start)
            ->set('endTime', $end)
            ->set('pauseMinutes', $pause);

        $this->assertSame(
            $expectedNet,
            $component->instance()->getNetMinutes(),
            sprintf('start=%s end=%s pause=%d', $start, $end, $pause),
        );
    }

    /**
     * Dataset voor net-minutes-berekening.
     *
     * Cases:
     *  - 8u dienst, 30 min pauze = 510 - 30 = wait, 09:00..17:00 is 8u.
     *    08:00..17:00 = 9u = 540 min - 30 = 510. Adjust below.
     *  - 8u30 dienst, 30 min pauze = 480.
     *  - Bruto = pauze (perfect 0-netto).
     *  - Negatief bruto: end < start → 0.
     *  - Pauze > bruto → max(0, ...) = 0.
     *
     * @return array<string, array{0:string,1:string,2:int,3:int}>
     */
    public static function netMinutesDataset(): array
    {
        return [
            '8u-shift met 30 min pauze' => ['08:00', '17:00', 60, 480],
            '4u-shift zonder pauze' => ['09:00', '13:00', 0, 240],
            '5u-shift met 30 min pauze' => ['08:00', '13:00', 30, 270],
            'pauze gelijk aan bruto = 0 netto' => ['10:00', '11:00', 60, 0],
            'pauze groter dan bruto = 0 netto' => ['10:00', '11:00', 90, 0],
            'eindtijd voor begintijd = 0 netto' => ['08:00', '07:00', 0, 0],
            'gelijke tijden = 0 netto' => ['12:00', '12:00', 0, 0],
        ];
    }

    public function test_live_atw_validation_populates_atw_result_on_field_update(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15')
            // Wijzig één ATW-relevant veld → triggert `updated()` en
            // dus ATW-validatie via `validateAtwLive()`.
            ->set('startTime', '09:00');

        /** @var array<string, mixed>|null $result */
        $result = $component->get('atwResult');

        $this->assertIsArray($result, 'atwResult moet een array zijn na een live-update.');
        $this->assertArrayHasKey('has_critical', $result);
        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('net_minutes', $result);
        $this->assertSame($this->employee->id, $result['employee_id']);
        $this->assertSame('2026-05-15', $result['entry_date']);
        // Met een 09:00..17:00 shift en 30 min pauze is het net 7u30
        // (=450 min). Geen historische data → geen kritieke signalen.
        $this->assertFalse($result['has_critical']);
    }

    public function test_critical_signals_block_submit(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15')
            // 06:00..19:00 = 13u bruto, 0 pauze → 13u netto > 12u
            // dagmaximum (720 min). Backend geeft `DAILY_LIMIT` met
            // severity `critical`.
            ->set('startTime', '06:00')
            ->set('endTime', '19:00')
            ->set('pauseMinutes', 0)
            ->call('submit')
            ->assertHasErrors('atw');

        // Geen werkregel mag zijn aangemaakt.
        $this->assertDatabaseMissing('work_entries', [
            'employee_id' => $this->employee->id,
            'entry_date' => '2026-05-15',
        ]);

        // Modal blijft open zodat de gebruiker kan corrigeren.
        $component->assertSet('isOpen', true);

        // atwResult moet has_critical = true tonen.
        $result = $component->get('atwResult');
        $this->assertIsArray($result);
        $this->assertTrue($result['has_critical']);
    }

    public function test_successful_submit_calls_work_entries_service_and_dispatches_entry_saved(): void
    {
        $this->actingAs($this->manager);

        // Optionele project + cost-center zodat de selects gevuld zijn
        // en we tegelijk de happy-path-route-met-koppelingen testen.
        $project = Project::create([
            'organization_id' => $this->org->id,
            'code' => 'PROJ-1',
            'name' => 'Project Een',
            'is_active' => true,
        ]);
        $costCenter = CostCenter::create([
            'organization_id' => $this->org->id,
            'code' => 'KP-1',
            'name' => 'Kostenplaats Een',
            'is_active' => true,
        ]);

        Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-19')
            ->set('startTime', '09:00')
            ->set('endTime', '17:00')
            ->set('pauseMinutes', 30)
            ->set('projectId', $project->id)
            ->set('costCenterId', $costCenter->id)
            ->set('note', 'Standaard werkdag')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('entry-saved')
            ->assertSet('isOpen', false);

        // Werkregel moet in de database staan met de juiste netto-minuten
        // (8u bruto - 30 min pauze = 450 min). De `entry_date`-kolom is
        // een DATE die SQLite teruggeeft als `Y-m-d 00:00:00`, dus we
        // queryen via Eloquent voor een robuuste match.
        $entry = WorkEntry::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('entry_date', '2026-05-19')
            ->first();
        $this->assertNotNull($entry, 'Er moet een werkregel zijn aangemaakt.');
        $this->assertSame(30, (int) $entry->pause_minutes);
        $this->assertSame(450, (int) $entry->net_minutes);
        $this->assertSame($project->id, (int) $entry->project_id);
        $this->assertSame($costCenter->id, (int) $entry->cost_center_id);
        $this->assertTrue((bool) $entry->is_finalized);
    }

    public function test_employee_cannot_pick_holiday_type(): void
    {
        $this->actingAs($this->employee);

        $component = Livewire::test(EntryFormModal::class)
            // Employee opent voor zichzelf een uurregel.
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15');

        $html = $component->html();

        // Werk en Verlof zijn altijd zichtbaar.
        $this->assertStringContainsString('Werk', $html);
        $this->assertStringContainsString('Verlof', $html);
        // Feestdag-optie moet voor employee zijn weggefilterd in de Blade.
        $this->assertStringNotContainsString('Feestdag', $html);
    }

    public function test_close_modal_resets_fields_and_isopen(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15')
            ->set('startTime', '10:00')
            ->set('endTime', '15:30')
            ->set('pauseMinutes', 60)
            ->set('type', 'SICK')
            ->set('note', 'Vrije tekst')
            ->call('closeModal')
            ->assertSet('isOpen', false)
            ->assertSet('employeeId', null)
            ->assertSet('entryDate', '')
            ->assertSet('startTime', '08:00')
            ->assertSet('endTime', '17:00')
            ->assertSet('pauseMinutes', 30)
            ->assertSet('type', 'WORK')
            ->assertSet('note', '')
            ->assertSet('atwResult', null);
    }

    public function test_unauthenticated_request_is_forbidden_when_modal_opened(): void
    {
        // Defensief pad: modal opent fine zonder user (`isOpen=false`),
        // maar zodra er een open-event komt en validateAtwLive wordt
        // getriggerd, weigeren we toegang.
        Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15')
            ->set('startTime', '09:00')
            ->assertForbidden();
    }

    /**
     * Taak 8.1: Slimme defaults — placeholder toont vorige werkdag-tijden
     * wanneer de medewerker op de vorige werkdag een WORK-entry had.
     * Requirements: 6.2
     */
    public function test_smart_defaults_shows_previous_workday_placeholder(): void
    {
        $this->actingAs($this->manager);

        // Maak een werkregel aan voor woensdag 2026-05-20.
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => '2026-05-20',
            'start_at' => '2026-05-20 09:00:00',
            'end_at' => '2026-05-20 17:30:00',
            'pause_minutes' => 30,
            'net_minutes' => 480,
            'type' => 'WORK',
            'is_finalized' => true,
        ]);

        // Open modal voor donderdag 2026-05-21 → vorige werkdag is woensdag 20 mei.
        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-21');

        $placeholder = $component->get('previousDayPlaceholder');
        $this->assertSame('Vorige dag: 09:00 - 17:30', $placeholder);
        $component->assertSee('Vorige dag: 09:00 - 17:30');
    }

    /**
     * Taak 8.1: Slimme defaults — geen placeholder als er geen vorige
     * werkdag-entry is.
     * Requirements: 6.2
     */
    public function test_smart_defaults_null_when_no_previous_workday_entry(): void
    {
        $this->actingAs($this->manager);

        // Geen werkregels aangemaakt → placeholder moet null zijn.
        // Gebruik 2026-05-21 (donderdag) zodat vorige werkdag 2026-05-20 (woensdag) is.
        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-21');

        $this->assertNull($component->get('previousDayPlaceholder'));
        $component->assertDontSee('Vorige dag:');
    }

    /**
     * Taak 8.1: Slimme defaults — weekend wordt overgeslagen bij het
     * zoeken naar de vorige werkdag.
     * Requirements: 6.2
     */
    public function test_smart_defaults_skips_weekend(): void
    {
        $this->actingAs($this->manager);

        // Maak een werkregel aan voor vrijdag 2026-06-05.
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => '2026-06-05',
            'start_at' => '2026-06-05 08:30:00',
            'end_at' => '2026-06-05 16:30:00',
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'type' => 'WORK',
            'is_finalized' => true,
        ]);

        // Open modal voor maandag 2026-06-08 → vorige werkdag is vrijdag 5 juni.
        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-06-08');

        $placeholder = $component->get('previousDayPlaceholder');
        $this->assertSame('Vorige dag: 08:30 - 16:30', $placeholder);
    }

    /**
     * Taak 8.1: Toast wordt gedispatcht bij succesvol opslaan.
     * Requirements: 6.7
     */
    public function test_successful_submit_dispatches_toast(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-20')
            ->set('startTime', '09:00')
            ->set('endTime', '17:00')
            ->set('pauseMinutes', 30)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('toast', variant: 'success', message: 'Werkregel opgeslagen');
    }

    /**
     * Taak 8.1: Netto werktijd wordt in "X uur Y minuten" formaat getoond.
     * Requirements: 6.3
     */
    public function test_net_minutes_displayed_in_uur_minuten_format(): void
    {
        $this->actingAs($this->manager);

        // 09:00-17:00 met 30 min pauze = 7 uur 30 minuten
        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15')
            ->set('startTime', '09:00')
            ->set('endTime', '17:00')
            ->set('pauseMinutes', 30);

        $component->assertSee('Netto werktijd:');
        $component->assertSee('7 uur 30 minuten');
    }

    /**
     * Taak 8.1: Modal toont role="dialog" en aria-modal="true".
     * Requirements: 6.10
     */
    public function test_modal_has_dialog_role_and_aria_modal(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-15');

        $html = $component->html();
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="entry-modal-heading"', $html);
    }

    /**
     * Taak 8.1: Slimme defaults — alleen WORK-entries worden gebruikt,
     * niet SICK/LEAVE/HOLIDAY.
     * Requirements: 6.2
     */
    public function test_smart_defaults_only_uses_work_entries(): void
    {
        $this->actingAs($this->manager);

        // Maak een SICK-entry aan voor woensdag 2026-05-20.
        WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => '2026-05-20',
            'start_at' => '2026-05-20 09:00:00',
            'end_at' => '2026-05-20 17:00:00',
            'pause_minutes' => 0,
            'net_minutes' => 0,
            'type' => 'SICK',
            'is_finalized' => true,
        ]);

        // Open modal voor donderdag 2026-05-21 → SICK-entry wordt niet als placeholder getoond.
        $component = Livewire::test(EntryFormModal::class)
            ->dispatch('open-entry-form-modal', employeeId: $this->employee->id, entryDate: '2026-05-21');

        $this->assertNull($component->get('previousDayPlaceholder'));
    }
}
