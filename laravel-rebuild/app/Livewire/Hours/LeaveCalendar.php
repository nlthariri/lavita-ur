<?php

declare(strict_types=1);

namespace App\Livewire\Hours;

use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\HolidaysService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Hours\LeaveCalendar` (taak 14.1 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 8.1-8.10 → Verlofkalender maandweergave
 *  - requirements.md 11.10    → Legenda met actieve verlof-types
 *  - design.md § Components   → Hours\LeaveCalendar op `/verlof/kalender`
 *
 * Verantwoordelijkheid:
 *  - Maandweergave grid: rijen = medewerkers, kolommen = dagen van de maand.
 *  - Color_Coding: SICK=rood, LEAVE=blauw, HOLIDAY=grijs, leeg=wit.
 *  - Scope-filtering: manager=eigen team, owner=alle teams (optioneel team-filter).
 *  - Feestdagen markeren in kolomheader (grijze achtergrond + tooltip).
 *  - Klik op lege cel → dispatch event voor snelle verlof-invoer-modal.
 *  - Maand-navigatie (vorige/volgende, vandaag-knop) + keyboard shortcuts (←/→).
 *  - Verticaal scrollbaar bij >20 medewerkers, sticky header-rij.
 *  - Totaal-kolom per medewerker (verlofdagen in maand).
 *  - Lazy-load via Livewire #[Lazy].
 *  - HTTP 403 voor employee-rol.
 *  - Legenda met actieve verlof-types + kleurcodering.
 */
#[Lazy]
#[Layout('layouts.app')]
#[Title('Verlofkalender — LaVita Urenregistratie')]
final class LeaveCalendar extends Component
{
    /**
     * Eerste dag van de zichtbare maand (Y-m-d formaat).
     */
    public string $monthStart = '';

    /**
     * Optionele team-scope-filter voor owners/boekhouders.
     * null = alle teams van de organisatie.
     */
    public ?int $teamFilter = null;

    /**
     * Naam van de organisatie voor de header.
     */
    public string $organizationName = '';

    /**
     * In-memory cache van de leave-matrix [employee_id => [Y-m-d => type]].
     * Type is 'SICK', 'LEAVE', 'HOLIDAY' of null (leeg).
     *
     * @var array<int, array<string, string|null>>|null
     */
    private ?array $leaveMatrixCache = null;

    /**
     * In-memory cache van leave_type_id per cel [employee_id => [Y-m-d => leave_type_id|null]].
     *
     * @var array<int, array<string, int|null>>|null
     */
    private ?array $leaveTypeIdMatrixCache = null;

    /**
     * Mount-fase: autorisatie + initialisatie.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        // Requirement 8.8: employee krijgt HTTP 403
        if ((string) $user->role === 'employee') {
            abort(403, 'Geen toegang tot de verlofkalender.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');

        // Initialiseer op de eerste dag van de huidige maand
        $this->monthStart = Carbon::now('Europe/Amsterdam')
            ->startOfMonth()
            ->toDateString();
    }

    /**
     * Placeholder voor lazy-loading (Requirement 8.10).
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-4">
            <div class="rounded-card border border-hairline bg-canvas p-6">
                <div class="animate-pulse rounded bg-surface h-[120px] w-full"></div>
                <div class="mt-4 space-y-3">
                    <div class="animate-pulse rounded bg-surface h-4 w-full"></div>
                    <div class="animate-pulse rounded bg-surface h-4 w-4/5"></div>
                    <div class="animate-pulse rounded bg-surface h-4 w-3/5"></div>
                    <div class="animate-pulse rounded bg-surface h-4 w-full"></div>
                    <div class="animate-pulse rounded bg-surface h-4 w-4/5"></div>
                    <div class="animate-pulse rounded bg-surface h-4 w-3/5"></div>
                    <div class="animate-pulse rounded bg-surface h-4 w-full"></div>
                    <div class="animate-pulse rounded bg-surface h-4 w-4/5"></div>
                </div>
            </div>
        </div>
        HTML;
    }

    /**
     * Listener voor het `entry-saved` event — ververs de matrix.
     */
    #[On('entry-saved')]
    public function onEntrySaved(): void
    {
        $this->resetMatrixCache();
    }

    /**
     * Listener voor het `leave-cancelled` event — ververs de matrix.
     */
    #[On('leave-cancelled')]
    public function onLeaveCancelled(): void
    {
        $this->resetMatrixCache();
    }

    /**
     * Ga naar de vorige maand.
     */
    public function previousMonth(): void
    {
        $this->monthStart = Carbon::parse($this->monthStart, 'Europe/Amsterdam')
            ->subMonth()
            ->startOfMonth()
            ->toDateString();

        $this->resetMatrixCache();
    }

    /**
     * Ga naar de volgende maand.
     */
    public function nextMonth(): void
    {
        $this->monthStart = Carbon::parse($this->monthStart, 'Europe/Amsterdam')
            ->addMonth()
            ->startOfMonth()
            ->toDateString();

        $this->resetMatrixCache();
    }

    /**
     * Spring naar de huidige maand.
     */
    public function goToToday(): void
    {
        $this->monthStart = Carbon::now('Europe/Amsterdam')
            ->startOfMonth()
            ->toDateString();

        $this->resetMatrixCache();
    }

    /**
     * Stel de team-scope-filter in.
     */
    public function setTeamFilter(?int $teamId): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        if ($teamId === null) {
            $this->teamFilter = null;
            $this->resetMatrixCache();

            return;
        }

        // Manager: alleen eigen team toestaan
        if ((string) $user->role === 'manager') {
            if ($user->team_id !== null && $teamId === (int) $user->team_id) {
                $this->teamFilter = $teamId;
                $this->resetMatrixCache();
            }

            return;
        }

        // Owner / boekhouder: team moet binnen eigen organisatie liggen
        $exists = Team::where('organization_id', (int) $user->organization_id)
            ->where('id', $teamId)
            ->exists();

        if ($exists) {
            $this->teamFilter = $teamId;
            $this->resetMatrixCache();
        }
    }

    /**
     * Geeft alle dagen van de zichtbare maand als Carbon-instances.
     *
     * @return array<int, Carbon>
     */
    public function getMonthDates(): array
    {
        $start = Carbon::parse($this->monthStart, 'Europe/Amsterdam')->startOfMonth();
        $daysInMonth = $start->daysInMonth;

        $dates = [];
        for ($i = 0; $i < $daysInMonth; $i++) {
            $dates[] = $start->copy()->addDays($i);
        }

        return $dates;
    }

    /**
     * Geeft het maand/jaar label voor de header.
     */
    public function getMonthLabel(): string
    {
        $date = Carbon::parse($this->monthStart, 'Europe/Amsterdam');
        $months = [
            1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
            5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
            9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
        ];

        return $months[$date->month] . ' ' . $date->year;
    }

    /**
     * Bouw de medewerker-collectie voor de zichtbare scope.
     * Requirement 8.3: manager=eigen team, owner=alle teams.
     */
    public function getEmployees(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        $query = User::query()
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('role', ['employee', 'manager', 'owner'])
            ->where('is_active', true);

        if ((string) $user->role === 'manager') {
            // Manager altijd vastgepind op eigen team
            $query->where('team_id', $user->team_id);
        } elseif ($this->teamFilter !== null) {
            $query->where('team_id', $this->teamFilter);
        }

        return $query
            ->orderByRaw('COALESCE(full_name, name) ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Beschikbare teams voor de team-filter-dropdown.
     */
    public function getAvailableTeams(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        if ((string) $user->role === 'manager') {
            if ($user->team_id === null) {
                return collect();
            }

            return Team::where('id', (int) $user->team_id)
                ->where('organization_id', (int) $user->organization_id)
                ->get();
        }

        return Team::where('organization_id', (int) $user->organization_id)
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Haal feestdagen op voor de zichtbare maand.
     *
     * @return array<string, string> [Y-m-d => naam]
     */
    public function getHolidaysForMonth(): array
    {
        $start = Carbon::parse($this->monthStart, 'Europe/Amsterdam');
        $year = (int) $start->year;

        /** @var HolidaysService $service */
        $service = app(HolidaysService::class);
        $holidays = $service->forYear($year);

        // Maand kan twee jaren overlappen (onwaarschijnlijk maar defensief)
        $end = $start->copy()->endOfMonth();
        if ($end->year !== $year) {
            $holidays = array_merge($holidays, $service->forYear((int) $end->year));
        }

        // Filter op de zichtbare maand
        $monthDates = [];
        $daysInMonth = $start->daysInMonth;
        for ($i = 0; $i < $daysInMonth; $i++) {
            $monthDates[] = $start->copy()->addDays($i)->toDateString();
        }

        $map = [];
        foreach ($holidays as $holiday) {
            if (in_array($holiday['date'], $monthDates, true)) {
                $map[$holiday['date']] = $holiday['name'];
            }
        }

        return $map;
    }

    /**
     * Haal het type op voor een cel (SICK, LEAVE, HOLIDAY of null).
     * Requirement 8.2: Color_Coding.
     */
    public function getTypeForCell(int $employeeId, string $isoDate): ?string
    {
        if ($this->leaveMatrixCache === null) {
            $this->buildLeaveMatrix();
        }

        return $this->leaveMatrixCache[$employeeId][$isoDate] ?? null;
    }

    /**
     * Geeft de CSS-klassen voor kleurcodering van een cel in de verlofkalender.
     * Requirement 8.2: SICK=rood, LEAVE=blauw, HOLIDAY=grijs, leeg=wit.
     *
     * @return array{bg: string, text: string}
     */
    public static function getCalendarColorClasses(?string $type): array
    {
        return match ($type) {
            'SICK' => ['bg' => 'bg-red-100', 'text' => 'text-red-800'],
            'LEAVE' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800'],
            'HOLIDAY' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'],
            default => ['bg' => 'bg-white', 'text' => 'text-ink'],
        };
    }

    /**
     * Bereken het totaal aantal verlofdagen voor een medewerker in de zichtbare maand.
     * Requirement 8.9: totaal-kolom per medewerker.
     * Telt SICK + LEAVE entries (niet HOLIDAY, dat zijn feestdagen voor iedereen).
     */
    public function getLeaveDaysForEmployee(int $employeeId): int
    {
        if ($this->leaveMatrixCache === null) {
            $this->buildLeaveMatrix();
        }

        $employeeData = $this->leaveMatrixCache[$employeeId] ?? [];
        $count = 0;

        foreach ($employeeData as $type) {
            if ($type === 'SICK' || $type === 'LEAVE') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Haal actieve verlof-types op voor de legenda.
     * Requirement 11.10: legenda met actieve verlof-types + kleurcodering.
     */
    public function getActiveLeaveTypes(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        return LeaveType::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('is_active', true)
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Bepaalt of de huidige gebruiker verlof mag invoeren (owner/manager).
     */
    public function canCreateLeave(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return in_array((string) $user->role, ['owner', 'manager'], true);
    }

    /**
     * Render de component.
     */
    public function render(): View
    {
        return view('livewire.hours.leave-calendar');
    }

    /**
     * Bouw de leave-matrix op: één query voor alle entries in de maand.
     * Haalt SICK, LEAVE en HOLIDAY entries op voor alle medewerkers in scope.
     */
    private function buildLeaveMatrix(): void
    {
        $this->leaveMatrixCache = [];
        $this->leaveTypeIdMatrixCache = [];

        $employees = $this->getEmployees();
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($employeeIds === []) {
            return;
        }

        $start = Carbon::parse($this->monthStart, 'Europe/Amsterdam');
        $end = $start->copy()->endOfMonth();

        // Haal alle relevante entries op in één query (NFR-8: geen N+1)
        $selectColumns = ['employee_id', 'entry_date', 'type'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('work_entries', 'leave_type_id')) {
            $selectColumns[] = 'leave_type_id';
        }

        $entries = WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('type', ['SICK', 'LEAVE', 'HOLIDAY'])
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereNull('deleted_at')
            ->select($selectColumns)
            ->get();

        // Initialiseer lege arrays voor alle medewerkers
        foreach ($employeeIds as $id) {
            $this->leaveMatrixCache[$id] = [];
            $this->leaveTypeIdMatrixCache[$id] = [];
        }

        // Vul de matrix
        foreach ($entries as $entry) {
            $empId = (int) $entry->employee_id;
            $date = $entry->entry_date->format('Y-m-d');
            $this->leaveMatrixCache[$empId][$date] = $entry->type;
            $this->leaveTypeIdMatrixCache[$empId][$date] = $entry->leave_type_id;
        }
    }

    /**
     * Reset de matrix-cache zodat de volgende render verse data ophaalt.
     */
    private function resetMatrixCache(): void
    {
        $this->leaveMatrixCache = null;
        $this->leaveTypeIdMatrixCache = null;
    }
}
