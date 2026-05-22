<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\DashboardAggregationService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Dashboard\ManagerHome`
 *
 * Manager/Owner/Boekhouder dashboard met KPI-cards, grafieken en activiteit-feed.
 *
 * Requirements: 1.1, 1.2, 1.6, 1.7, 1.8, 1.9
 *
 * Features:
 *  - 6 KPI-cards via `<x-ui.stat-card>` met trend-indicatoren
 *  - Persoonlijke begroeting met naam + datum (Nederlands formaat)
 *  - wire:poll.30s voor auto-refresh van KPI-data
 *  - Skeleton placeholders tijdens initieel laden
 *  - Snelactie-knoppen voor veelgebruikte acties
 *
 * Scope-filtering:
 *  - Manager: data gefilterd op user.team_id (eigen team)
 *  - Owner/boekhouder: data voor alle teams binnen de organisatie
 */
#[Layout('layouts.app')]
#[Title('Dashboard — LaVita Urenregistratie')]
final class ManagerHome extends Component
{
    /**
     * Volledige naam van de ingelogde gebruiker voor de begroeting.
     */
    public string $userFullName = '';

    /**
     * Naam van de organisatie van de ingelogde gebruiker.
     */
    public string $organizationName = '';

    /**
     * Of de KPI-data al geladen is (voor skeleton-state).
     */
    public bool $dataLoaded = false;

    /**
     * Aantal aanwezige medewerkers deze week (distinct employee_ids met ≥1 entry).
     * Behouden als public property voor backward compatibility met bestaande tests.
     */
    public int $presentEmployeesThisWeek = 0;

    /**
     * Totaal actieve medewerkers in scope.
     * Behouden als public property voor backward compatibility met bestaande tests.
     */
    public int $totalEmployeesInScope = 0;

    /**
     * Openstaande bezwaren count.
     * Behouden als public property voor backward compatibility met bestaande tests.
     */
    public int $openObjectionsCount = 0;

    /**
     * ATW critical violations count (distinct users).
     * Behouden als public property voor backward compatibility met bestaande tests.
     */
    public int $atwCriticalCount = 0;

    /**
     * ATW warning violations count (distinct users).
     * Behouden als public property voor backward compatibility met bestaande tests.
     */
    public int $atwWarningCount = 0;

    /**
     * KPI-data array van DashboardAggregationService.
     *
     * @var array<string, mixed>
     */
    public array $kpiData = [];

    /**
     * Mount-fase: resolve user, stel begroeting in, laad initiële KPI-data.
     */
    public function mount(DashboardAggregationService $aggregationService): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'employee') {
            $this->redirect('/dashboard/medewerker');
            return;
        }

        $this->userFullName = (string) ($user->full_name ?: $user->name ?: '');
        $this->organizationName = (string) ($user->organization?->name ?? '');

        // Laad KPI-data via de aggregation service
        $this->kpiData = $aggregationService->getKpiData($user);
        $this->syncKpiProperties();
        $this->dataLoaded = true;
    }

    /**
     * Polling-methode: ververs KPI-data elke 30 seconden.
     * Wordt aangeroepen door wire:poll.30s in de view.
     */
    public function refreshKpiData(DashboardAggregationService $aggregationService): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $this->kpiData = $aggregationService->getKpiData($user);
        $this->syncKpiProperties();
    }

    /**
     * Synchroniseer KPI-data naar individuele public properties.
     * Dit behoudt backward compatibility met bestaande tests die
     * direct op properties asserten.
     */
    private function syncKpiProperties(): void
    {
        $this->atwCriticalCount = $this->kpiData['atw_critical_count'] ?? 0;
        $this->atwWarningCount = $this->kpiData['atw_warning_count'] ?? 0;
        $this->openObjectionsCount = $this->kpiData['open_objections_count'] ?? 0;

        // Bereken presentEmployeesThisWeek en totalEmployeesInScope
        // uit de attendance_percentage en de underlying data
        $attendancePct = $this->kpiData['attendance_percentage'] ?? 0;

        // We need the actual counts — resolve from the service data
        // The service calculates attendance_percentage = floor(present/total * 100)
        // We store these separately for the legacy properties
        $this->presentEmployeesThisWeek = $this->kpiData['_present_count'] ?? 0;
        $this->totalEmployeesInScope = $this->kpiData['_total_employees'] ?? 0;
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
     * Bereken de trend-richting voor uren (this_week vs prev_week).
     */
    public function getHoursTrend(): string
    {
        $thisWeek = $this->kpiData['total_hours_this_week'] ?? 0;
        $prevWeek = $this->kpiData['total_hours_prev_week'] ?? 0;

        if ($thisWeek > $prevWeek) {
            return 'up';
        }
        if ($thisWeek < $prevWeek) {
            return 'down';
        }

        return 'neutral';
    }

    /**
     * Bereken de trend-waarde tekst voor uren.
     */
    public function getHoursTrendValue(): string
    {
        $thisWeek = $this->kpiData['total_hours_this_week'] ?? 0;
        $prevWeek = $this->kpiData['total_hours_prev_week'] ?? 0;
        $diff = $thisWeek - $prevWeek;

        if ($diff === 0) {
            return 'Gelijk aan vorige week';
        }

        $hours = intdiv(abs($diff), 60);
        $minutes = abs($diff) % 60;
        $formatted = $minutes > 0 ? "{$hours}u {$minutes}m" : "{$hours}u";

        return $diff > 0 ? "+{$formatted}" : "-{$formatted}";
    }

    /**
     * Formatteer netto-minuten naar "HH:mm" formaat.
     */
    public function formatMinutesToHours(int $minutes): string
    {
        $hours = intdiv(abs($minutes), 60);
        $mins = abs($minutes) % 60;

        return sprintf('%d:%02d', $hours, $mins);
    }

    /**
     * Geeft het aanwezigheidspercentage.
     * Behouden voor backward compatibility.
     */
    public function getPresencePercentage(): int
    {
        if ($this->totalEmployeesInScope <= 0) {
            return 0;
        }

        $ratio = $this->presentEmployeesThisWeek / $this->totalEmployeesInScope;

        return (int) floor($ratio * 100);
    }

    /**
     * Snelkoppelingen die in de view als knoppen worden gerenderd.
     *
     * @return array<int, array{label: string, url: string, description: string, owner_only: bool}>
     */
    public function getQuickLinks(): array
    {
        return [
            [
                'label' => 'Weekoverzicht uren',
                'url' => '/uren/week',
                'description' => 'Bekijk en bewerk de urenstaat van je team voor de huidige week.',
                'owner_only' => false,
            ],
            [
                'label' => 'ATW-statusdashboard',
                'url' => '/atw',
                'description' => 'Bekijk de ATW-meldingen per medewerker en limiet.',
                'owner_only' => false,
            ],
            [
                'label' => 'Rapportages',
                'url' => '/rapportages',
                'description' => 'Genereer overzichten en exports (PDF/Excel) per periode.',
                'owner_only' => false,
            ],
            [
                'label' => 'Bezwaren',
                'url' => '/bezwaren',
                'description' => 'Bekijk en beoordeel ingediende bezwaren op werkregels.',
                'owner_only' => false,
            ],
            [
                'label' => 'Accountbeheer',
                'url' => '/accounts',
                'description' => 'Beheer accounts, rollen en activatie binnen je organisatie.',
                'owner_only' => true,
            ],
        ];
    }

    /**
     * Geeft `true` wanneer de ingelogde gebruiker rol `owner` heeft.
     */
    public function getIsOwner(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user !== null && (string) $user->role === 'owner';
    }

    /**
     * Formatteer een ISO-8601 timestamp naar relatieve tijd in het Nederlands.
     * Voorbeeld: "2 uur geleden", "3 dagen geleden", "zojuist".
     */
    public function formatRelativeTime(string $isoTimestamp): string
    {
        if ($isoTimestamp === '') {
            return '';
        }

        try {
            $time = Carbon::parse($isoTimestamp, 'Europe/Amsterdam');
        } catch (\Exception) {
            return '';
        }

        $now = Carbon::now('Europe/Amsterdam');
        $diffInMinutes = (int) $now->diffInMinutes($time);
        $diffInHours = (int) $now->diffInHours($time);
        $diffInDays = (int) $now->diffInDays($time);

        if ($diffInMinutes < 1) {
            return 'zojuist';
        }

        if ($diffInMinutes < 60) {
            return $diffInMinutes === 1
                ? '1 minuut geleden'
                : "{$diffInMinutes} minuten geleden";
        }

        if ($diffInHours < 24) {
            return $diffInHours === 1
                ? '1 uur geleden'
                : "{$diffInHours} uur geleden";
        }

        if ($diffInDays < 7) {
            return $diffInDays === 1
                ? '1 dag geleden'
                : "{$diffInDays} dagen geleden";
        }

        if ($diffInDays < 30) {
            $weeks = intdiv($diffInDays, 7);
            return $weeks === 1
                ? '1 week geleden'
                : "{$weeks} weken geleden";
        }

        // Fallback: toon datum
        return $time->format('d-m-Y');
    }

    public function render(): View
    {
        return view('livewire.dashboard.manager-home');
    }
}
