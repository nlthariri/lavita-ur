<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Dashboard\EmployeeHome`
 *
 * Dashboard voor medewerkers (employee-rol). Toont:
 * - Persoonlijke begroeting met naam + datum (Nederlands formaat)
 * - Progress_Bar "Mijn uren deze week" (netto-minuten vs contracturen)
 * - Verlof-saldo Progress_Bar met opgenomen/resterend + waarschuwingskleuren
 * - Lijst openstaande bezwaren met status-badge
 * - Snelactie-knoppen: "Uren invoeren", "Verlof aanvragen"
 * - Mini-weekoverzicht met horizontale balken per dag + Color_Coding
 * - Skeleton placeholders tijdens laden
 * - Notificaties bij verlof goedkeuring/afwijzing of werkregel-wijziging
 *
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10
 */
#[Layout('layouts.app')]
#[Title('Dashboard — LaVita Urenregistratie')]
final class EmployeeHome extends Component
{
    // --- Begroeting ---
    public string $userFullName = '';
    public string $organizationName = '';

    // --- Uren deze week ---
    public int $totalMinutesThisWeek = 0;
    public int $daysWorkedThisWeek = 0;
    public ?int $contractMinutesPerWeek = null;

    // --- Verlof-saldo ---
    /** @var array{annual_days: int|null, taken_days: float, remaining_days: float|null, status: string, breakdown: array<string, float>}|null */
    public ?array $leaveBalance = null;

    // --- Bezwaren ---
    public int $openObjectionsCount = 0;
    /** @var array<int, array{id: int, status: string, submitted_at: string, motivation: string}> */
    public array $objections = [];

    // --- Mini-weekoverzicht ---
    /** @var array<int, array{day_name: string, date: string, entries: array<int, array{type: string, net_minutes: int, start_at: string, end_at: string}>}> */
    public array $weekOverview = [];

    // --- Notificaties ---
    /** @var array<int, array{message: string, type: string, date: string}> */
    public array $notifications = [];

    // --- Loading state ---
    public bool $dataLoaded = false;

    public function mount(LeaveBalanceService $leaveBalanceService): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $this->userFullName = (string) ($user->full_name ?: $user->name ?: '');
        $this->organizationName = (string) ($user->organization?->name ?? '');

        // Contracturen ophalen (nullable — verberg widget als null)
        $this->contractMinutesPerWeek = $this->resolveContractMinutes($user);

        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY);
        $sunday = $monday->copy()->addDays(6);

        // Uren deze week
        $entries = WorkEntry::query()
            ->where('employee_id', (int) $user->id)
            ->whereBetween('entry_date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereNull('deleted_at')
            ->get(['net_minutes', 'entry_date', 'type', 'start_at', 'end_at']);

        $this->totalMinutesThisWeek = (int) $entries->sum('net_minutes');
        $this->daysWorkedThisWeek = $entries->pluck('entry_date')->unique()->count();

        // Mini-weekoverzicht opbouwen
        $this->weekOverview = $this->buildWeekOverview($entries, $monday);

        // Verlof-saldo via LeaveBalanceService
        $this->leaveBalance = $leaveBalanceService->getBalance(
            (int) $user->id,
            (int) Carbon::now('Europe/Amsterdam')->year
        );

        // Eigen openstaande bezwaren (met details voor de lijst)
        $objectionRecords = Objection::query()
            ->where('submitted_by_id', (int) $user->id)
            ->orderByDesc('submitted_at')
            ->limit(10)
            ->get(['id', 'status', 'submitted_at', 'motivation']);

        $this->openObjectionsCount = $objectionRecords->where('status', 'OPEN')->count();
        $this->objections = $objectionRecords->map(fn ($obj) => [
            'id' => (int) $obj->id,
            'status' => (string) $obj->status,
            'submitted_at' => $obj->submitted_at?->format('d-m-Y') ?? '',
            'motivation' => \Illuminate\Support\Str::limit((string) $obj->motivation, 60),
        ])->toArray();

        // Notificaties: recente verlof-goedkeuringen/afwijzingen en werkregel-wijzigingen
        $this->notifications = $this->loadNotifications($user);

        $this->dataLoaded = true;
    }

    /**
     * Genereer de persoonlijke begroeting op basis van het tijdstip.
     * Formaat: "Goedemorgen/Goedemiddag/Goedenavond, [naam]"
     */
    public function getGreeting(): string
    {
        $hour = (int) Carbon::now('Europe/Amsterdam')->format('H');

        if ($hour < 12) {
            $greeting = 'Goedemorgen';
        } elseif ($hour < 18) {
            $greeting = 'Goedemiddag';
        } else {
            $greeting = 'Goedenavond';
        }

        if ($this->userFullName !== '') {
            return "{$greeting}, {$this->userFullName}";
        }

        return $greeting;
    }

    /**
     * Geeft de huidige datum in Nederlands formaat: "dag DD maand JJJJ".
     * Voorbeeld: "dinsdag 15 mei 2026"
     */
    public function getFormattedDate(): string
    {
        $now = Carbon::now('Europe/Amsterdam');

        $days = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
        $months = [
            1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
            5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
            9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
        ];

        $dayName = $days[$now->dayOfWeek];
        $day = $now->day;
        $monthName = $months[$now->month];
        $year = $now->year;

        return "{$dayName} {$day} {$monthName} {$year}";
    }

    /**
     * Formatteer minuten als "Xu Ymin" formaat.
     */
    public function getFormattedHours(): string
    {
        $hours = intdiv($this->totalMinutesThisWeek, 60);
        $minutes = $this->totalMinutesThisWeek % 60;

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}u {$minutes}min";
        }
        if ($hours > 0) {
            return "{$hours}u";
        }

        return "{$minutes}min";
    }

    /**
     * Formatteer minuten als "HH:mm" formaat.
     */
    public function formatMinutes(int $minutes): string
    {
        $h = intdiv(abs($minutes), 60);
        $m = abs($minutes) % 60;

        return sprintf('%d:%02d', $h, $m);
    }

    /**
     * Bepaal de variant voor de verlof-saldo progress bar.
     */
    public function getLeaveBalanceVariant(): string
    {
        if ($this->leaveBalance === null) {
            return 'success';
        }

        return match ($this->leaveBalance['status']) {
            'danger' => 'danger',
            'warning' => 'warning',
            default => 'success',
        };
    }

    /**
     * Of de uren progress bar getoond moet worden (alleen als contracturen geconfigureerd).
     */
    public function getShowHoursProgress(): bool
    {
        return $this->contractMinutesPerWeek !== null && $this->contractMinutesPerWeek > 0;
    }

    /**
     * Of de verlof-saldo widget getoond moet worden.
     */
    public function getShowLeaveBalance(): bool
    {
        if ($this->leaveBalance === null) {
            return false;
        }

        return $this->leaveBalance['status'] !== 'unconfigured';
    }

    public function render(): View
    {
        return view('livewire.dashboard.employee-home');
    }

    // --- Private helpers ---

    /**
     * Bouw het mini-weekoverzicht op met entries per dag.
     *
     * @param Collection<int, WorkEntry> $entries
     * @return array<int, array{day_name: string, date: string, entries: array<int, array{type: string, net_minutes: int, start_at: string, end_at: string}>}>
     */
    private function buildWeekOverview(Collection $entries, Carbon $monday): array
    {
        $dayNames = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
        $overview = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->copy()->addDays($i);
            $dateStr = $date->toDateString();

            $dayEntries = $entries->filter(function ($entry) use ($dateStr) {
                $entryDate = $entry->entry_date;
                if ($entryDate instanceof Carbon) {
                    return $entryDate->toDateString() === $dateStr;
                }
                return (string) $entryDate === $dateStr;
            });

            $overview[] = [
                'day_name' => $dayNames[$i],
                'date' => $date->format('d-m'),
                'entries' => $dayEntries->map(fn ($entry) => [
                    'type' => (string) $entry->type,
                    'net_minutes' => (int) $entry->net_minutes,
                    'start_at' => $entry->start_at ? Carbon::parse($entry->start_at)->format('H:i') : '00:00',
                    'end_at' => $entry->end_at ? Carbon::parse($entry->end_at)->format('H:i') : '00:00',
                ])->values()->toArray(),
            ];
        }

        return $overview;
    }

    /**
     * Laad recente notificaties voor de medewerker.
     * Controleert recente verlof-goedkeuringen/afwijzingen en werkregel-wijzigingen door manager.
     *
     * @return array<int, array{message: string, type: string, date: string}>
     */
    private function loadNotifications(User $user): array
    {
        $notifications = [];

        // Recente verlof-entries die goedgekeurd zijn (is_finalized=true, type=LEAVE, recent)
        $recentLeave = WorkEntry::query()
            ->where('employee_id', (int) $user->id)
            ->where('type', 'LEAVE')
            ->where('is_finalized', true)
            ->whereNull('deleted_at')
            ->where('updated_at', '>=', Carbon::now('Europe/Amsterdam')->subDays(7))
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['entry_date', 'updated_at']);

        foreach ($recentLeave as $entry) {
            $notifications[] = [
                'message' => 'Verlof goedgekeurd voor ' . Carbon::parse($entry->entry_date)->format('d-m-Y'),
                'type' => 'success',
                'date' => $entry->updated_at?->format('d-m-Y H:i') ?? '',
            ];
        }

        // Recente werkregels gewijzigd door een manager (registered_by_id != employee_id)
        $recentChanges = WorkEntry::query()
            ->where('employee_id', (int) $user->id)
            ->where('registered_by_id', '!=', (int) $user->id)
            ->whereNull('deleted_at')
            ->where('updated_at', '>=', Carbon::now('Europe/Amsterdam')->subDays(7))
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['entry_date', 'type', 'updated_at']);

        foreach ($recentChanges as $entry) {
            $notifications[] = [
                'message' => 'Werkregel gewijzigd door manager voor ' . Carbon::parse($entry->entry_date)->format('d-m-Y'),
                'type' => 'info',
                'date' => $entry->updated_at?->format('d-m-Y H:i') ?? '',
            ];
        }

        // Sorteer op datum (nieuwste eerst) en beperk tot 5
        usort($notifications, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return array_slice($notifications, 0, 5);
    }

    /**
     * Resolve contracturen per week van de gebruiker.
     * Retourneert null als niet geconfigureerd (kolom bestaat niet of waarde is null).
     */
    private function resolveContractMinutes(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        try {
            // Check of de kolom bestaat
            if (! Schema::hasColumn('users', 'contract_hours_per_week')) {
                return null;
            }

            $value = $user->contract_hours_per_week;

            return $value !== null ? (int) $value * 60 : null;
        } catch (\Exception) {
            return null;
        }
    }
}
