<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Objections;

use App\Livewire\Objections\NewObjectionForm;
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
 * Feature-tests voor Livewire-component `Objections\NewObjectionForm`
 * (taak 10.3 spec lavita-urenregistratie).
 *
 * Dekt:
 *  - Modal start dicht en rendert geen formulier wanneer dicht.
 *  - Open-event vult `workEntryId` en zet `isOpen = true`.
 *  - Validatie op `motivation` (min 10, max 2000).
 *  - Succesvolle submit roept `ObjectionsService::submit` aan, schrijft
 *    een `objections`-rij met status `OPEN`, dispatcht
 *    `objection-submitted` en sluit de modal.
 *  - Bestaande open bezwaar op dezelfde regel → service throwt
 *    ValidationException, mapt op `motivation`-foutveld.
 *  - Werkregel van een ándere medewerker → NL-foutmelding op `motivation`.
 *  - `closeModal` reset alle state.
 */
final class NewObjectionFormTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Team $team;

    private User $owner;

    private User $manager;

    private User $employee1;

    private User $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'LaVita Objection Org',
        ]);

        $this->team = Team::create([
            'organization_id' => $this->org->id,
            'name' => 'Team Objection',
        ]);

        $this->owner = User::create([
            'name' => 'Owner OBJ',
            'full_name' => 'Olga Owner',
            'email' => 'owner-obj@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager OBJ',
            'full_name' => 'Mira Manager',
            'email' => 'mgr-obj@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->employee1 = User::create([
            'name' => 'Employee OBJ 1',
            'full_name' => 'Eva Een',
            'email' => 'emp1-obj@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee2 = User::create([
            'name' => 'Employee OBJ 2',
            'full_name' => 'Erik Twee',
            'email' => 'emp2-obj@lavita.test',
            'password' => bcrypt('Wachtwoord1234'),
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->team->update(['manager_id' => $this->manager->id]);
    }

    /** Helper voor het seeden van een werkregel. */
    private function seedFinalizedEntry(User $employee, string $isoDate, string $startTime = '08:00:00', string $endTime = '16:00:00'): WorkEntry
    {
        return WorkEntry::create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'team_id' => $employee->team_id,
            'registered_by_id' => $this->owner->id,
            'entry_date' => $isoDate,
            'start_at' => $isoDate.' '.$startTime,
            'end_at' => $isoDate.' '.$endTime,
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'is_finalized' => true,
        ]);
    }

    public function test_modal_starts_closed(): void
    {
        $this->actingAs($this->employee1);

        Livewire::test(NewObjectionForm::class)
            ->assertOk()
            ->assertSet('isOpen', false)
            ->assertSet('workEntryId', null)
            ->assertSet('motivation', '')
            // Wanneer dicht moet er géén dialog-paneel met de heading zijn.
            ->assertDontSee('Bezwaar indienen');
    }

    public function test_open_event_sets_workentry_and_isopen(): void
    {
        $this->actingAs($this->employee1);

        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedFinalizedEntry($this->employee1, $isoDate);

        Livewire::test(NewObjectionForm::class)
            ->dispatch('open-new-objection', workEntryId: $entry->id)
            ->assertSet('isOpen', true)
            ->assertSet('workEntryId', $entry->id)
            ->assertSet('motivation', '')
            ->assertSet('confirmation', null)
            ->assertSee('Bezwaar indienen')
            ->assertSee('Motivatie')
            ->assertSee('0 / 2000 tekens');
    }

    public function test_motivation_under_10_chars_triggers_validation_error(): void
    {
        $this->actingAs($this->employee1);

        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedFinalizedEntry($this->employee1, $isoDate);

        Livewire::test(NewObjectionForm::class)
            ->dispatch('open-new-objection', workEntryId: $entry->id)
            ->set('motivation', 'kort')
            ->call('submit')
            ->assertHasErrors(['motivation' => 'min']);

        $this->assertDatabaseMissing('objections', [
            'work_entry_id' => $entry->id,
        ]);
    }

    public function test_motivation_over_2000_chars_triggers_validation_error(): void
    {
        $this->actingAs($this->employee1);

        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedFinalizedEntry($this->employee1, $isoDate);

        $tooLong = str_repeat('a', 2001);

        Livewire::test(NewObjectionForm::class)
            ->dispatch('open-new-objection', workEntryId: $entry->id)
            ->set('motivation', $tooLong)
            ->call('submit')
            ->assertHasErrors(['motivation' => 'max']);

        $this->assertDatabaseMissing('objections', [
            'work_entry_id' => $entry->id,
        ]);
    }

    public function test_successful_submit_calls_objections_service_and_creates_open_objection(): void
    {
        $this->actingAs($this->employee1);

        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedFinalizedEntry($this->employee1, $isoDate);

        $motivation = 'Ik heb hier echt langer gewerkt dan geregistreerd staat.';

        Livewire::test(NewObjectionForm::class)
            ->dispatch('open-new-objection', workEntryId: $entry->id)
            ->set('motivation', $motivation)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('objection-submitted')
            // closeModal reset isOpen + state na succesvolle submit.
            ->assertSet('isOpen', false)
            ->assertSet('workEntryId', null)
            ->assertSet('motivation', '');

        $this->assertDatabaseHas('objections', [
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $this->employee1->id,
            'status' => 'OPEN',
            'motivation' => $motivation,
        ]);
    }

    public function test_duplicate_open_objection_returns_validation_error(): void
    {
        $this->actingAs($this->employee1);

        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedFinalizedEntry($this->employee1, $isoDate);

        Objection::create([
            'organization_id' => $this->employee1->organization_id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $this->employee1->id,
            'motivation' => 'Eerste bezwaar staat al open.',
            'status' => 'OPEN',
            'submitted_at' => now(),
        ]);

        $component = Livewire::test(NewObjectionForm::class)
            ->dispatch('open-new-objection', workEntryId: $entry->id)
            ->set('motivation', 'Tweede bezwaar mag niet doorgaan want eerste staat nog open.')
            ->call('submit')
            ->assertHasErrors('motivation');

        $errors = $component->errors()->get('motivation');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString(
            'openstaand bezwaar',
            $errors[0],
            'NL-foutmelding voor reeds bestaand open bezwaar moet zichtbaar zijn op het motivatie-veld.'
        );

        // Modal blijft open zodat de gebruiker kan annuleren of corrigeren.
        $component->assertSet('isOpen', true);
    }

    public function test_cannot_submit_objection_on_other_employees_entry(): void
    {
        $this->actingAs($this->employee1);

        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        // Werkregel van employee 2.
        $foreignEntry = $this->seedFinalizedEntry($this->employee2, $isoDate);

        $component = Livewire::test(NewObjectionForm::class)
            ->dispatch('open-new-objection', workEntryId: $foreignEntry->id)
            ->set('motivation', 'Ik probeer bezwaar in te dienen op een uurregel van iemand anders.')
            ->call('submit')
            ->assertHasErrors('motivation');

        $errors = $component->errors()->get('motivation');
        $this->assertNotEmpty($errors);
        $this->assertSame(
            'U mag alleen bezwaar indienen op uw eigen urenregels.',
            $errors[0],
        );

        $this->assertDatabaseMissing('objections', [
            'work_entry_id' => $foreignEntry->id,
        ]);
    }

    public function test_close_modal_resets_state(): void
    {
        $this->actingAs($this->employee1);

        $isoDate = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $entry = $this->seedFinalizedEntry($this->employee1, $isoDate);

        Livewire::test(NewObjectionForm::class)
            ->dispatch('open-new-objection', workEntryId: $entry->id)
            ->set('motivation', 'tussenstand')
            ->call('closeModal')
            ->assertSet('isOpen', false)
            ->assertSet('workEntryId', null)
            ->assertSet('motivation', '')
            ->assertSet('confirmation', null);
    }
}
