<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidaysService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Settings\HolidaysManager`
 *
 * Beheerpagina voor feestdagen per jaar. Alleen toegankelijk voor de owner-rol.
 *
 * Functionaliteit:
 *  - Jaarselector (huidig jaar + volgend jaar)
 *  - Overzicht van feestdagen uit de database voor het geselecteerde jaar
 *  - Genereren van standaard Nederlandse feestdagen (upsert op year+date)
 *  - Handmatig toevoegen van aangepaste feestdagen
 *  - Verwijderen van individuele feestdagen
 *  - Nationale feestdagen worden gemarkeerd met een badge
 */
#[Layout('layouts.app')]
#[Title('Feestdagen — LaVita Urenregistratie')]
final class HolidaysManager extends Component
{
    public int $selectedYear;

    /** @var array<int, array{id: int, date: string, name: string, is_national: bool}> */
    public array $holidays = [];

    // Form fields voor nieuw feestdag
    public string $newDate = '';

    public string $newName = '';

    public bool $newIsNational = false;

    // Feedback
    public ?string $confirmation = null;

    public ?string $error = null;

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            abort(403, 'Geen toegang tot feestdagenbeheer.');
        }

        $this->selectedYear = (int) now()->year;
        $this->loadHolidays();
    }

    /**
     * Wissel van jaar en herlaad de feestdagen.
     */
    public function selectYear(int $year): void
    {
        $this->resetFeedback();

        $currentYear = (int) now()->year;
        $allowedYears = [$currentYear, $currentYear + 1];

        if (! in_array($year, $allowedYears, true)) {
            $this->error = 'Ongeldig jaar geselecteerd.';

            return;
        }

        $this->selectedYear = $year;
        $this->loadHolidays();
    }

    /**
     * Genereer standaard Nederlandse feestdagen voor het geselecteerde jaar.
     * Gebruikt upsert op year+date om duplicaten te voorkomen.
     */
    public function generateDefaults(HolidaysService $holidaysService): void
    {
        $this->resetFeedback();
        $this->authorizeOwner();

        $computed = $holidaysService->computeNlHolidaysForYear($this->selectedYear);

        $rows = array_map(fn (array $holiday) => [
            'year' => $this->selectedYear,
            'date' => $holiday['date'],
            'name' => $holiday['name'],
            'is_national' => $holiday['is_national'],
        ], $computed);

        Holiday::upsert(
            $rows,
            ['year', 'date'],
            ['name', 'is_national']
        );

        $this->loadHolidays();
        $this->confirmation = 'Standaard feestdagen gegenereerd voor ' . $this->selectedYear . '.';
    }

    /**
     * Voeg een aangepaste feestdag toe.
     */
    public function addHoliday(): void
    {
        $this->resetFeedback();
        $this->resetErrorBag();
        $this->authorizeOwner();

        $dateTrimmed = trim($this->newDate);
        $nameTrimmed = trim($this->newName);

        if ($dateTrimmed === '') {
            $this->addError('newDate', 'Datum is verplicht.');

            return;
        }

        if ($nameTrimmed === '') {
            $this->addError('newName', 'Naam is verplicht.');

            return;
        }

        if (mb_strlen($nameTrimmed) > 80) {
            $this->addError('newName', 'Naam mag maximaal 80 tekens bevatten.');

            return;
        }

        // Valideer dat de datum in het geselecteerde jaar valt
        try {
            $parsedDate = \Carbon\Carbon::parse($dateTrimmed);
        } catch (\Throwable) {
            $this->addError('newDate', 'Ongeldige datum.');

            return;
        }

        if ((int) $parsedDate->year !== $this->selectedYear) {
            $this->addError('newDate', 'Datum moet in het jaar ' . $this->selectedYear . ' vallen.');

            return;
        }

        // Upsert om duplicaten op year+date te voorkomen
        Holiday::upsert(
            [[
                'year' => $this->selectedYear,
                'date' => $parsedDate->format('Y-m-d'),
                'name' => $nameTrimmed,
                'is_national' => $this->newIsNational,
            ]],
            ['year', 'date'],
            ['name', 'is_national']
        );

        // Reset formulier
        $this->newDate = '';
        $this->newName = '';
        $this->newIsNational = false;

        $this->loadHolidays();
        $this->confirmation = 'Feestdag toegevoegd.';
    }

    /**
     * Verwijder een individuele feestdag.
     */
    public function deleteHoliday(int $id): void
    {
        $this->resetFeedback();
        $this->authorizeOwner();

        $holiday = Holiday::find($id);

        if ($holiday === null) {
            $this->error = 'Feestdag niet gevonden.';

            return;
        }

        if ((int) $holiday->year !== $this->selectedYear) {
            $this->error = 'Feestdag behoort niet tot het geselecteerde jaar.';

            return;
        }

        $holiday->delete();

        $this->loadHolidays();
        $this->confirmation = 'Feestdag verwijderd.';
    }

    /**
     * Beschikbare jaren voor de selector.
     *
     * @return array<int, int>
     */
    public function getAvailableYears(): array
    {
        $currentYear = (int) now()->year;

        return [$currentYear, $currentYear + 1];
    }

    public function render(): View
    {
        return view('livewire.settings.holidays-manager');
    }

    /**
     * Laad feestdagen uit de database voor het geselecteerde jaar.
     */
    private function loadHolidays(): void
    {
        $this->holidays = Holiday::forYear($this->selectedYear)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => [
                'id' => (int) $h->id,
                'date' => $h->date->format('Y-m-d'),
                'name' => (string) $h->name,
                'is_national' => (bool) $h->is_national,
            ])
            ->all();
    }

    /**
     * Controleer of de huidige gebruiker de owner-rol heeft.
     */
    private function authorizeOwner(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null || (string) $user->role !== 'owner') {
            abort(403, 'Geen toegang.');
        }
    }

    private function resetFeedback(): void
    {
        $this->confirmation = null;
        $this->error = null;
    }
}
