<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Hours;

use App\Livewire\Hours\LeaveForm;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\WorkEntriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature-tests voor Livewire-component `Hours\LeaveForm` (taak 10.4
 * spec lavita-urenregistratie).
 *
 * Dekt:
 *  - 403 voor boekhouder (read-only rol, geen verlof-invoer).
 *  - Default-state voor employee-rol (type=LEAVE, employeeId=self).
 *  - View-side filter: HOLIDAY niet getoond aan employee-rol.
 *  - Client-side `INVALID_TYPE_FOR_ROLE`-blok wanneer employee
 *    `set('type', 'HOLIDAY')` direct overschrijft (deze flow zou
 *    pas in taak 14.6 backend-422 moeten worden, dus testen we
 *    hier alleen het client-side gedrag).
 *  - Verplichte motivatie voor employee-rol.
 *  - Employee-rol mag niet voor andere medewerker indienen.
 *  - Manager kan één-dag- en meerdaags-verlof indienen → 1 of 3
 *    werkregels in DB met `type=LEAVE` en `pause_minutes=0`.
 *  - `dateTo < dateFrom` levert validatie-error op `dateTo`.
 *  - `dateTo - dateFrom > 60 dagen` wordt geweigerd met NL-melding.
 *  - Manager mag wél HOLIDAY indienen.
 *  - Render toont alle NL-labels + knoppen.
 *
 * Rationale: de tests die de werkregel-DB-write valideren, gebruiken
 * de manager-rol als actor. {@see WorkEntriesService::create()}
 * weigert vandaag een employee-rol-registrar via
 * `assertAllowedRegistrar`; die check wordt door taak 14.6 verruimd
 * voor SICK/LEAVE-self-submit. Tot die tijd dekken we alle employee-
 * specifieke regels uitsluitend op het client-side-gedrag van de
 * Livewire-component (geen service-call, dus geen DB-write nodig).
 *
 * Daarnaast stuurt de component vandaag een werkdag-window
 * (09:00–17:00, 480 net min) i.p.v. 00:00–23:59 zodat
 * `AtwService::throwOnCriticalSignals` niet onmiddellijk
 * `ATW_DAILY_MAX_EXCEEDED` werpt — taak 14.7 verruimt
 * `AtwEngine::evaluate()` zodat non-WORK types worden overgeslagen,
 * waarna we naar de literale defaults uit req 7.1 kunnen omschakelen.
 */
final class LeaveFormTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $team;

    private User $owner;

    private User $manager;

    private User $employee;

    private User $employee2;

    private User $boekhouder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'LaVita Verlof Org',
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
            'name' => 'Team Verlof',
        ]);

        $this->owner = User::create([
            'name' => 'Owner Verlof',
            'full_name' => 'Olivier Owner',
            'email' => 'owner-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager Verlof',
            'full_name' => 'Mira Manager',
            'email' => 'mgr-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'name' => 'Employee Verlof',
            'full_name' => 'Eva Employee',
            'email' => 'emp-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee2 = User::create([
            'name' => 'Tweede Employee',
            'full_name' => 'Frank Field',
            'email' => 'emp2-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->boekhouder = User::create([
            'name' => 'Boekhouder Verlof',
            'full_name' => 'Bas Bookkeeper',
            'email' => 'bk-leave@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        // Manager wordt teamleider zodat assertTeamScope een binding heeft
        // (anders levert WorkEntriesService een ValidationException
        // "Manager moet gekoppeld zijn aan een team"-pad — niet hier,
        // maar voor consistentie met EntryFormModalTest).
        $this->team->update(['manager_id' => $this->manager->id]);
    }

    public function test_boekhouder_is_forbidden(): void
    {
        $this->actingAs($this->boekhouder);

        Livewire::test(LeaveForm::class)
            ->assertForbidden();
    }

    public function test_employee_default_type_is_leave_and_self(): void
    {
        $this->actingAs($this->employee);

        Livewire::test(LeaveForm::class)
            ->assertOk()
            ->assertSet('type', 'LEAVE')
            ->assertSet('employeeId', $this->employee->id);
    }

    public function test_employee_cannot_choose_holiday_type(): void
    {
        $this->actingAs($this->employee);

        $component = Livewire::test(LeaveForm::class);

        // De helper-array bevat alle drie types; dat is correct want
        // de filter zit in de view, niet in de service-laag.
        $availableTypes = $component->instance()->getAvailableTypes();
        $this->assertArrayHasKey('HOLIDAY', $availableTypes);

        // De gerenderde HTML moet "Feestdag" niet bevatten — dat is
        // de NL-label voor HOLIDAY en de view-filter haalt 'm weg
        // voor employee-rol.
        $html = $component->html();
        $this->assertStringContainsString('Verlof', $html);
        $this->assertStringContainsString('Ziek', $html);
        $this->assertStringNotContainsString('Feestdag', $html);
    }

    public function test_employee_holiday_type_shows_validation_error_on_submit(): void
    {
        $this->actingAs($this->employee);

        $tomorrow = now('Europe/Amsterdam')->addDay()->toDateString();

        Livewire::test(LeaveForm::class)
            // Bypass de view-filter door direct de property te zetten —
            // dat simuleert een aanvaller die het type-veld manipuleert
            // en zorgt dat de server-side check (req 7.2) hetzelfde
            // weigert als de UI-filter.
            ->set('type', 'HOLIDAY')
            ->set('dateFrom', $tomorrow)
            ->set('dateTo', $tomorrow)
            ->set('note', 'Probeerballon')
            ->call('submit')
            ->assertHasErrors(['type'])
            ->assertSet('createdCount', 0);

        $this->assertDatabaseMissing('work_entries', [
            'employee_id' => $this->employee->id,
            'type' => 'HOLIDAY',
        ]);
    }

    public function test_employee_must_supply_note(): void
    {
        $this->actingAs($this->employee);

        $tomorrow = now('Europe/Amsterdam')->addDay()->toDateString();

        Livewire::test(LeaveForm::class)
            ->set('type', 'LEAVE')
            ->set('dateFrom', $tomorrow)
            ->set('dateTo', $tomorrow)
            ->set('note', '')
            ->call('submit')
            ->assertHasErrors(['note'])
            ->assertSet('createdCount', 0);
    }

    public function test_employee_cannot_register_for_other_employee(): void
    {
        $this->actingAs($this->employee);

        $tomorrow = now('Europe/Amsterdam')->addDay()->toDateString();

        Livewire::test(LeaveForm::class)
            ->set('type', 'LEAVE')
            // Override default (self) naar een collega — dat is
            // expliciet niet toegestaan (req 7.2).
            ->set('employeeId', $this->employee2->id)
            ->set('dateFrom', $tomorrow)
            ->set('dateTo', $tomorrow)
            ->set('note', 'Sorry alvast')
            ->call('submit')
            ->assertHasErrors(['employeeId'])
            ->assertSet('createdCount', 0);

        $this->assertDatabaseMissing('work_entries', [
            'employee_id' => $this->employee2->id,
        ]);
    }

    public function test_one_day_leave_creates_one_work_entry(): void
    {
        // Manager-as-registrar voor het happy-pad omdat
        // WorkEntriesService::create vandaag alleen owner/manager
        // accepteert (taak 14.6 staat queued).
        $this->actingAs($this->manager);

        $oneDay = now('Europe/Amsterdam')->addDays(2)->toDateString();

        $component = Livewire::test(LeaveForm::class)
            ->set('type', 'LEAVE')
            ->set('employeeId', $this->employee->id)
            ->set('dateFrom', $oneDay)
            ->set('dateTo', $oneDay)
            // Manager mag een blanco motivatie indienen.
            ->set('note', '')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('createdCount', 1);

        $this->assertSame(
            'Verlof/ziekte ingediend voor 1 dag(en).',
            $component->get('confirmation')
        );

        $entry = WorkEntry::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('entry_date', $oneDay)
            ->first();

        $this->assertNotNull($entry, 'Er moet één werkregel zijn aangemaakt.');
        $this->assertSame('LEAVE', (string) $entry->type);
        $this->assertSame(0, (int) $entry->pause_minutes);
    }

    public function test_multi_day_leave_creates_one_entry_per_day(): void
    {
        $this->actingAs($this->manager);

        $start = now('Europe/Amsterdam')->addDays(3)->toDateString();
        $end = now('Europe/Amsterdam')->addDays(5)->toDateString();

        Livewire::test(LeaveForm::class)
            ->set('type', 'SICK')
            ->set('employeeId', $this->employee->id)
            ->set('dateFrom', $start)
            ->set('dateTo', $end)
            ->set('note', 'Griep')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('createdCount', 3);

        // We filteren op employee + datum-range. NB: `whereBetween` op
        // een Eloquent DATE-cast-kolom kan in SQLite de bovenste grens
        // excluden zodra de string-vergelijking met de DATE-cast
        // botst. We filteren daarom expliciet met `>=` / `<=` op de
        // string-representatie via `whereDate`, wat in alle drivers
        // (SQLite + MySQL) consistent een DATE-vergelijking doet.
        $entries = WorkEntry::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('entry_date', '>=', $start)
            ->whereDate('entry_date', '<=', $end)
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(3, $entries, 'Er moeten 3 werkregels zijn (één per dag).');
        foreach ($entries as $entry) {
            $this->assertSame('SICK', (string) $entry->type);
            $this->assertSame(0, (int) $entry->pause_minutes);
        }

        // Datums moeten aaneengesloten zijn.
        $dates = $entries->map(fn ($e) => $e->entry_date->toDateString())->all();
        $this->assertSame(
            [
                $start,
                now('Europe/Amsterdam')->addDays(4)->toDateString(),
                $end,
            ],
            $dates,
        );
    }

    public function test_date_to_before_date_from_triggers_validation_error(): void
    {
        $this->actingAs($this->manager);

        $start = now('Europe/Amsterdam')->addDays(5)->toDateString();
        $end = now('Europe/Amsterdam')->addDays(3)->toDateString();

        Livewire::test(LeaveForm::class)
            ->set('type', 'LEAVE')
            ->set('employeeId', $this->employee->id)
            ->set('dateFrom', $start)
            ->set('dateTo', $end)
            ->call('submit')
            ->assertHasErrors(['dateTo' => 'after_or_equal'])
            ->assertSet('createdCount', 0);
    }

    public function test_range_over_60_days_is_rejected(): void
    {
        $this->actingAs($this->manager);

        $start = now('Europe/Amsterdam')->addDays(1)->toDateString();
        $end = now('Europe/Amsterdam')->addDays(91)->toDateString();

        $component = Livewire::test(LeaveForm::class)
            ->set('type', 'LEAVE')
            ->set('employeeId', $this->employee->id)
            ->set('dateFrom', $start)
            ->set('dateTo', $end)
            ->set('note', 'Lang weg')
            ->call('submit')
            ->assertHasErrors(['dateTo'])
            ->assertSet('createdCount', 0);

        // Verifieer dat de NL-melding over de cap doorgegeven wordt.
        $errorBag = $component->errors();
        $this->assertNotNull($errorBag, 'Error-bag mag niet leeg zijn.');
        $message = (string) ($errorBag->first('dateTo') ?? '');
        $this->assertStringContainsString(
            'maximaal 60 dagen',
            $message,
            'Foutmelding moet de 60-dagen-cap noemen.'
        );

        // Geen werkregels in DB.
        $this->assertSame(0, WorkEntry::query()
            ->where('employee_id', $this->employee->id)
            ->count());
    }

    public function test_manager_can_register_holiday(): void
    {
        $this->actingAs($this->manager);

        $oneDay = now('Europe/Amsterdam')->addDays(7)->toDateString();

        Livewire::test(LeaveForm::class)
            ->set('type', 'HOLIDAY')
            ->set('employeeId', $this->employee->id)
            ->set('dateFrom', $oneDay)
            ->set('dateTo', $oneDay)
            // Manager mag zonder motivatie indienen.
            ->set('note', '')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('createdCount', 1);

        $entry = WorkEntry::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('entry_date', $oneDay)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('HOLIDAY', (string) $entry->type);
    }

    public function test_render_shows_dutch_labels_and_buttons(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(LeaveForm::class)
            ->assertSee('Verlof / ziekte registreren')
            ->assertSee('Vanaf')
            ->assertSee('Tot en met')
            ->assertSee('Indienen');

        // Manager-rol moet een employee-select zien (geen self-only-hint).
        $html = $component->html();
        $this->assertStringContainsString('Medewerker', $html);
    }

    // ─── Verlof-annulering tests (taak 13.2) ───────────────────────────

    public function test_cancel_button_shown_for_pending_leave(): void
    {
        $this->actingAs($this->employee);

        // Maak een PENDING verlofaanvraag aan
        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        $component = Livewire::test(LeaveForm::class);
        $html = $component->html();

        $this->assertStringContainsString('cancel-leave-btn-'.$entry->id, $html);
        $this->assertStringContainsString('Annuleren', $html);
    }

    public function test_cancel_button_hidden_for_approved_leave(): void
    {
        $this->actingAs($this->employee);

        // Maak een GOEDGEKEURD verlofaanvraag aan
        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => true,
        ]);

        $component = Livewire::test(LeaveForm::class);
        $html = $component->html();

        // De annuleren-knop mag NIET getoond worden bij goedgekeurd verlof
        $this->assertStringNotContainsString('cancel-leave-btn-'.$entry->id, $html);
    }

    public function test_confirm_cancel_opens_modal(): void
    {
        $this->actingAs($this->employee);

        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id)
            ->assertSet('cancellingEntryId', $entry->id);
    }

    public function test_dismiss_cancel_modal_resets_state(): void
    {
        $this->actingAs($this->employee);

        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id)
            ->assertSet('cancellingEntryId', $entry->id)
            ->call('dismissCancelModal')
            ->assertSet('cancellingEntryId', null);
    }

    public function test_cancel_leave_soft_deletes_pending_entry(): void
    {
        $this->actingAs($this->employee);

        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id)
            ->call('cancelLeave')
            ->assertSet('cancellingEntryId', null)
            ->assertDispatched('toast', variant: 'success', message: 'Verlofaanvraag geannuleerd.')
            ->assertDispatched('leave-cancelled');

        // Verify soft-delete
        $this->assertSoftDeleted('work_entries', ['id' => $entry->id]);
    }

    public function test_cancel_leave_creates_audit_event(): void
    {
        $this->actingAs($this->employee);

        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id)
            ->call('cancelLeave');

        // Verify audit event
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $this->org->id,
            'actor_id' => $this->employee->id,
            'action' => 'LEAVE_CANCELLED',
            'target_type' => 'work_entry',
            'target_id' => $entry->id,
        ]);
    }

    public function test_cancel_approved_leave_returns_409(): void
    {
        $this->actingAs($this->employee);

        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => true,
        ]);

        Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id)
            ->call('cancelLeave')
            ->assertStatus(409);

        // Entry should NOT be soft-deleted
        $this->assertDatabaseHas('work_entries', [
            'id' => $entry->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cancel_leave_updates_balance(): void
    {
        $this->actingAs($this->employee);

        // Configureer verlofrecht
        $this->employee->update(['annual_leave_days' => 25]);

        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        // Bereken saldo vóór annulering — de entry is PENDING (is_finalized=false)
        // LeaveBalanceService telt alleen is_finalized=true, dus pending entries
        // tellen niet mee in het saldo. Na soft-delete is de entry sowieso weg.
        $balanceService = app(\App\Services\LeaveBalanceService::class);
        $balanceBefore = $balanceService->getBalance($this->employee->id, (int) date('Y'));

        Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id)
            ->call('cancelLeave');

        // Na annulering: entry is soft-deleted, dus niet meer zichtbaar
        $this->assertSoftDeleted('work_entries', ['id' => $entry->id]);

        // Saldo moet ongewijzigd zijn (pending entries tellen niet mee)
        $balanceAfter = $balanceService->getBalance($this->employee->id, (int) date('Y'));
        $this->assertEquals($balanceBefore['taken_days'], $balanceAfter['taken_days']);
    }

    public function test_cancel_other_users_entry_fails(): void
    {
        $this->actingAs($this->employee);

        // Maak een entry aan voor employee2
        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee2->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id)
            ->call('cancelLeave')
            ->assertDispatched('toast', variant: 'error', message: 'Verlofaanvraag niet gevonden.');

        // Entry should NOT be soft-deleted
        $this->assertDatabaseHas('work_entries', [
            'id' => $entry->id,
            'deleted_at' => null,
        ]);
    }

    public function test_view_shows_confirmation_modal_text(): void
    {
        $this->actingAs($this->employee);

        $entry = WorkEntry::create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'team_id' => $this->team->id,
            'registered_by_id' => $this->manager->id,
            'entry_date' => now()->addDays(5)->toDateString(),
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(14, 30),
            'pause_minutes' => 0,
            'net_minutes' => 330,
            'type' => 'LEAVE',
            'is_finalized' => false,
        ]);

        $component = Livewire::test(LeaveForm::class)
            ->call('confirmCancelLeave', $entry->id);

        $html = $component->html();
        $this->assertStringContainsString('Weet je zeker dat je deze verlofaanvraag wilt annuleren?', $html);
        $this->assertStringContainsString('Ja, annuleren', $html);
        $this->assertStringContainsString('Nee, behouden', $html);
    }
}
