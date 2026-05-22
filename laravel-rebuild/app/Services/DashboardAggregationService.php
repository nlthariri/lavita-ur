<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AtwViolation;
use App\Models\AuditEvent;
use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DashboardAggregationService — berekent alle KPI-data voor het
 * manager/owner dashboard in één efficiënte query-batch.
 *
 * Scope-filtering:
 *  - Manager: data gefilterd op user.team_id (eigen team)
 *  - Owner/boekhouder: data gefilterd op organization_id (alle teams)
 *  - Optioneel team-filter voor owner om in te zoomen op één team
 *
 * Requirements: 1.2, 1.3, 1.4, 1.8
 * NFR-8: Eager loading, geen N+1 queries
 * NFR-9: Alle data gefilterd op organization_id
 */
class DashboardAggregationService
{
    /**
     * Activity feed event types die relevant zijn voor het dashboard.
     */
    private const ACTIVITY_FEED_ACTIONS = [
        'WORK_ENTRY_CREATED',
        'LEAVE_REQUESTED',
        'OBJECTION_SUBMITTED',
    ];

    /**
     * Bereken alle KPI-data voor het manager/owner dashboard.
     *
     * @param  User  $user  De ingelogde gebruiker (manager/owner/boekhouder)
     * @param  int|null  $teamFilter  Optioneel team-filter (alleen voor owner)
     * @return array{
     *   total_hours_this_week: int,
     *   total_hours_prev_week: int,
     *   attendance_percentage: int,
     *   pending_leave_count: int,
     *   atw_critical_count: int,
     *   atw_warning_count: int,
     *   open_objections_count: int,
     *   sick_percentage: float,
     *   chart_data: array<string, array<string, int>>,
     *   activity_feed: array,
     *   _present_count: int,
     *   _total_employees: int,
     * }
     */
    public function getKpiData(User $user, ?int $teamFilter = null): array
    {
        $organizationId = (int) $user->organization_id;
        $employeeIds = $this->resolveEmployeeIdsInScope($user, $teamFilter);
        $totalEmployees = count($employeeIds);

        // Weekgrenzen (ISO-week, Europe/Amsterdam)
        $currentMonday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY);
        $currentSunday = $currentMonday->copy()->addDays(6);
        $prevMonday = $currentMonday->copy()->subWeek();
        $prevSunday = $prevMonday->copy()->addDays(6);

        // Batch-queries voor performance (NFR-8)
        $totalHoursThisWeek = $this->sumNetMinutes($employeeIds, $currentMonday, $currentSunday);
        $totalHoursPrevWeek = $this->sumNetMinutes($employeeIds, $prevMonday, $prevSunday);
        $attendancePercentage = $this->calculateAttendancePercentage($employeeIds, $currentMonday, $currentSunday, $totalEmployees);
        $pendingLeaveCount = $this->countPendingLeave($employeeIds, $organizationId);
        [$atwCritical, $atwWarning] = $this->countAtwSignals($employeeIds);
        $openObjectionsCount = $this->countOpenObjections($user, $employeeIds);
        $sickPercentage = $this->calculateSickPercentage($employeeIds, $currentMonday, $currentSunday, $totalEmployees);
        $chartData = $this->buildChartData($user, $employeeIds, $currentMonday, $currentSunday, $teamFilter);
        $activityFeed = $this->getActivityFeed($organizationId, $employeeIds);

        // Calculate present count for backward compatibility with ManagerHome properties
        $presentCount = $this->countPresentEmployees($employeeIds, $currentMonday, $currentSunday);

        return [
            'total_hours_this_week' => $totalHoursThisWeek,
            'total_hours_prev_week' => $totalHoursPrevWeek,
            'attendance_percentage' => $attendancePercentage,
            'pending_leave_count' => $pendingLeaveCount,
            'atw_critical_count' => $atwCritical,
            'atw_warning_count' => $atwWarning,
            'open_objections_count' => $openObjectionsCount,
            'sick_percentage' => $sickPercentage,
            'chart_data' => $chartData,
            'activity_feed' => $activityFeed,
            '_present_count' => $presentCount,
            '_total_employees' => $totalEmployees,
        ];
    }

    /**
     * Bouw de set medewerker-IDs die binnen de zichtbare scope vallen.
     *
     * - Manager: alleen eigen team (team_id)
     * - Owner/boekhouder: alle teams in organisatie, optioneel gefilterd op team
     *
     * @return array<int, int>
     */
    private function resolveEmployeeIdsInScope(User $user, ?int $teamFilter = null): array
    {
        $query = User::query()
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('role', ['employee', 'manager', 'owner'])
            ->where('is_active', true);

        if ((string) $user->role === 'manager') {
            // Manager is altijd vastgepind op eigen team
            $query->where('team_id', $user->team_id);
        } elseif ($teamFilter !== null) {
            // Owner/boekhouder met optioneel team-filter
            $query->where('team_id', $teamFilter);
        }

        return $query->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * Som van netto-minuten voor een set medewerkers in een datumbereik.
     * Sluit soft-deleted entries uit.
     */
    private function sumNetMinutes(array $employeeIds, Carbon $from, Carbon $to): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        return (int) WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('deleted_at')
            ->sum('net_minutes');
    }

    /**
     * Tel distinct medewerkers met ≥1 entry in het datumbereik.
     */
    private function countPresentEmployees(array $employeeIds, Carbon $from, Carbon $to): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        return (int) WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('deleted_at')
            ->distinct()
            ->count('employee_id');
    }

    /**
     * Bereken aanwezigheidspercentage: distinct medewerkers met ≥1 entry
     * gedeeld door totaal actieve medewerkers in scope × 100.
     */
    private function calculateAttendancePercentage(array $employeeIds, Carbon $from, Carbon $to, int $totalEmployees): int
    {
        if ($totalEmployees <= 0 || $employeeIds === []) {
            return 0;
        }

        $presentCount = (int) WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('deleted_at')
            ->distinct()
            ->count('employee_id');

        return (int) floor(($presentCount / $totalEmployees) * 100);
    }

    /**
     * Tel openstaande verlofaanvragen (type=LEAVE, is_finalized=false, niet soft-deleted).
     */
    private function countPendingLeave(array $employeeIds, int $organizationId): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        return (int) WorkEntry::query()
            ->where('organization_id', $organizationId)
            ->whereIn('employee_id', $employeeIds)
            ->where('type', 'LEAVE')
            ->where('is_finalized', false)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Tel distinct medewerkers met ATW-violations (critical en warning),
     * niet-superseded.
     *
     * @return array{0: int, 1: int} [critical_count, warning_count]
     */
    private function countAtwSignals(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [0, 0];
        }

        $critical = (int) AtwViolation::query()
            ->whereIn('user_id', $employeeIds)
            ->whereNull('superseded_at')
            ->where('severity', 'critical')
            ->distinct()
            ->count('user_id');

        $warning = (int) AtwViolation::query()
            ->whereIn('user_id', $employeeIds)
            ->whereNull('superseded_at')
            ->where('severity', 'warning')
            ->distinct()
            ->count('user_id');

        return [$critical, $warning];
    }

    /**
     * Tel openstaande bezwaren binnen de scope.
     * Manager: alleen bezwaren op werkregels van eigen team.
     * Owner/boekhouder: alle bezwaren in de organisatie.
     */
    private function countOpenObjections(User $user, array $employeeIds): int
    {
        $query = Objection::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('status', 'OPEN');

        if ((string) $user->role === 'manager') {
            $teamId = $user->team_id;

            if ($teamId === null) {
                return 0;
            }

            // Filter op werkregels van het eigen team
            $entryIds = WorkEntry::query()
                ->where('organization_id', (int) $user->organization_id)
                ->where('team_id', (int) $teamId)
                ->pluck('id');

            if ($entryIds->isEmpty()) {
                return 0;
            }

            $query->whereIn('work_entry_id', $entryIds);
        }

        return (int) $query->count();
    }

    /**
     * Bereken ziekteverzuim-percentage: distinct medewerkers met ≥1 SICK entry
     * deze week gedeeld door totaal actieve medewerkers × 100.
     */
    private function calculateSickPercentage(array $employeeIds, Carbon $from, Carbon $to, int $totalEmployees): float
    {
        if ($totalEmployees <= 0 || $employeeIds === []) {
            return 0.0;
        }

        $sickCount = (int) WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('type', 'SICK')
            ->whereNull('deleted_at')
            ->distinct()
            ->count('employee_id');

        return round(($sickCount / $totalEmployees) * 100, 1);
    }

    /**
     * Bouw chart-data: uren per dag (ma-zo) voor de huidige week.
     *
     * - Owner: gegroepeerd per team (team_name => [dag => minuten])
     * - Manager: totaal per dag ([dag => minuten])
     *
     * @return array<string, array<string, int>>
     */
    private function buildChartData(User $user, array $employeeIds, Carbon $monday, Carbon $sunday, ?int $teamFilter = null): array
    {
        if ($employeeIds === []) {
            return $this->emptyChartData();
        }

        $dayLabels = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];

        // Owner zonder team-filter: groepeer per team
        if ((string) $user->role !== 'manager' && $teamFilter === null) {
            return $this->buildChartDataGroupedByTeam($employeeIds, $monday, $sunday, $dayLabels);
        }

        // Manager of owner met team-filter: totaal per dag
        return $this->buildChartDataTotal($employeeIds, $monday, $sunday, $dayLabels);
    }

    /**
     * Chart-data gegroepeerd per team (voor owner zonder filter).
     *
     * @return array<string, array<string, int>>
     */
    private function buildChartDataGroupedByTeam(array $employeeIds, Carbon $monday, Carbon $sunday, array $dayLabels): array
    {
        $rows = WorkEntry::query()
            ->select('team_id', 'entry_date', DB::raw('SUM(net_minutes) as total_minutes'))
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereNull('deleted_at')
            ->groupBy('team_id', 'entry_date')
            ->get();

        // Resolve team names
        $teamIds = $rows->pluck('team_id')->unique()->filter()->values();
        $teamNames = [];
        if ($teamIds->isNotEmpty()) {
            $teamNames = \App\Models\Team::whereIn('id', $teamIds)
                ->pluck('name', 'id')
                ->all();
        }

        $result = [];
        foreach ($rows as $row) {
            $teamName = $teamNames[$row->team_id] ?? 'Geen team';
            $dayIndex = Carbon::parse($row->entry_date)->dayOfWeekIso - 1; // 0=ma, 6=zo
            $dayLabel = $dayLabels[$dayIndex] ?? 'onbekend';

            if (! isset($result[$teamName])) {
                $result[$teamName] = array_fill_keys($dayLabels, 0);
            }

            $result[$teamName][$dayLabel] = (int) $row->total_minutes;
        }

        // Als er geen data is, return lege structuur
        if ($result === []) {
            return $this->emptyChartData();
        }

        return $result;
    }

    /**
     * Chart-data als totaal per dag (voor manager of owner met team-filter).
     *
     * @return array<string, array<string, int>>
     */
    private function buildChartDataTotal(array $employeeIds, Carbon $monday, Carbon $sunday, array $dayLabels): array
    {
        $rows = WorkEntry::query()
            ->select('entry_date', DB::raw('SUM(net_minutes) as total_minutes'))
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereNull('deleted_at')
            ->groupBy('entry_date')
            ->get();

        $data = array_fill_keys($dayLabels, 0);

        foreach ($rows as $row) {
            $dayIndex = Carbon::parse($row->entry_date)->dayOfWeekIso - 1;
            $dayLabel = $dayLabels[$dayIndex] ?? 'onbekend';
            $data[$dayLabel] = (int) $row->total_minutes;
        }

        return ['Totaal' => $data];
    }

    /**
     * Lege chart-data structuur.
     *
     * @return array<string, array<string, int>>
     */
    private function emptyChartData(): array
    {
        $dayLabels = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];

        return ['Totaal' => array_fill_keys($dayLabels, 0)];
    }

    /**
     * Haal de laatste 10 relevante activiteiten op voor de activity feed.
     *
     * Relevante acties: uren ingevoerd, verlof aangevraagd, bezwaar ingediend.
     * Gesorteerd op created_at aflopend.
     *
     * @return array<int, array{action: string, actor_name: string, target_type: string, target_id: int|null, created_at: string}>
     */
    private function getActivityFeed(int $organizationId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $events = AuditEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('action', self::ACTIVITY_FEED_ACTIONS)
            ->whereIn('actor_id', $employeeIds)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Eager-load actor names in one query to avoid N+1
        $actorIds = $events->pluck('actor_id')->unique()->filter()->values();
        $actorNames = [];
        if ($actorIds->isNotEmpty()) {
            $actorNames = User::whereIn('id', $actorIds)
                ->pluck('full_name', 'id')
                ->all();
        }

        return $events->map(function ($event) use ($actorNames) {
            $actorName = $actorNames[$event->actor_id] ?? 'Onbekend';

            return [
                'action' => $event->action,
                'actor_name' => (string) $actorName,
                'target_type' => $event->target_type,
                'target_id' => $event->target_id ? (int) $event->target_id : null,
                'created_at' => $event->created_at?->toIso8601String() ?? '',
                'description' => $this->describeAction($event->action, (string) $actorName),
            ];
        })->all();
    }

    /**
     * Genereer een Nederlandstalige beschrijving voor een audit-actie.
     */
    private function describeAction(string $action, string $actorName): string
    {
        return match ($action) {
            'WORK_ENTRY_CREATED' => "{$actorName} heeft uren ingevoerd",
            'LEAVE_REQUESTED' => "{$actorName} heeft verlof aangevraagd",
            'OBJECTION_SUBMITTED' => "{$actorName} heeft een bezwaar ingediend",
            default => "{$actorName} heeft een actie uitgevoerd",
        };
    }
}
