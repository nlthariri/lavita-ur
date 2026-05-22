<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Service voor het berekenen van verlof-saldo met ondersteuning voor
 * half-dag verlof en verlof-types.
 *
 * Berekent opgenomen verlofdagen (hele en halve dagen) en bepaalt
 * de status van het saldo (ok/warning/danger/unconfigured).
 *
 * Requirements: 9.3, 9.4, 10.4, 11.6
 */
class LeaveBalanceService
{
    /**
     * Half-dag ochtend: start 00:00, eind 12:30 (Amsterdam tijd).
     */
    private const HALF_DAY_MORNING_START = '00:00';

    private const HALF_DAY_MORNING_END = '12:30';

    /**
     * Half-dag middag: start 12:30, eind 23:59 (Amsterdam tijd).
     */
    private const HALF_DAY_AFTERNOON_START = '12:30';

    private const HALF_DAY_AFTERNOON_END = '23:59';

    /**
     * Haal het volledige verlof-saldo op voor een gebruiker in een bepaald jaar.
     *
     * @return array{
     *   annual_days: int|null,
     *   taken_days: float,
     *   remaining_days: float|null,
     *   status: 'ok'|'warning'|'danger'|'unconfigured',
     *   breakdown: array<string, float>,
     * }
     */
    public function getBalance(int $userId, int $year): array
    {
        $user = User::find($userId);

        $annualDays = $this->resolveAnnualLeaveDays($user);

        $takenDays = $this->calculateTakenDays($userId, $year);
        $breakdown = $this->calculateBreakdown($userId, $year);

        $remainingDays = $annualDays !== null ? (float) $annualDays - $takenDays : null;

        $status = $this->determineStatus($annualDays, $remainingDays);

        return [
            'annual_days' => $annualDays,
            'taken_days' => $takenDays,
            'remaining_days' => $remainingDays,
            'status' => $status,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Bereken het totaal aantal opgenomen verlofdagen voor een gebruiker in een jaar.
     *
     * Telt alleen entries met:
     * - type = LEAVE
     * - is_finalized = true
     * - deleted_at IS NULL (soft-delete)
     * - leave_type.counts_towards_balance = true (indien leave_types tabel bestaat)
     * - entry_date in het opgegeven jaar
     *
     * Half-dag detectie:
     * - start=00:00, end=12:30 (Amsterdam) → 0.5 dag
     * - start=12:30, end=23:59 (Amsterdam) → 0.5 dag
     * - Alle andere combinaties → 1.0 dag
     */
    public function calculateTakenDays(int $userId, int $year): float
    {
        $entries = $this->getLeaveEntries($userId, $year);

        $total = 0.0;

        foreach ($entries as $entry) {
            $total += $this->isHalfDay($entry) ? 0.5 : 1.0;
        }

        return $total;
    }

    /**
     * Bereken de breakdown per verlof-type.
     *
     * @return array<string, float>
     */
    private function calculateBreakdown(int $userId, int $year): array
    {
        $entries = $this->getLeaveEntries($userId, $year, includeNonBalance: true);
        $breakdown = [];

        foreach ($entries as $entry) {
            $typeName = $this->resolveLeaveTypeName($entry);
            $days = $this->isHalfDay($entry) ? 0.5 : 1.0;

            if (! isset($breakdown[$typeName])) {
                $breakdown[$typeName] = 0.0;
            }
            $breakdown[$typeName] += $days;
        }

        return $breakdown;
    }

    /**
     * Haal verlof-entries op voor een gebruiker in een bepaald jaar.
     *
     * @return \Illuminate\Support\Collection<int, WorkEntry>
     */
    private function getLeaveEntries(int $userId, int $year, bool $includeNonBalance = false): \Illuminate\Support\Collection
    {
        $query = WorkEntry::query()
            ->where('employee_id', $userId)
            ->where('type', 'LEAVE')
            ->where('is_finalized', true)
            ->whereNull('deleted_at')
            ->whereYear('entry_date', $year);

        // Filter op counts_towards_balance als de leave_types tabel bestaat
        // en we niet expliciet alle types willen (voor breakdown)
        if (! $includeNonBalance && $this->leaveTypesTableExists()) {
            $query->where(function ($q) {
                // Entries zonder leave_type_id tellen standaard mee
                $q->whereNull('leave_type_id')
                    ->orWhereHas('leaveType', function ($subQ) {
                        $subQ->where('counts_towards_balance', true);
                    });
            });
        }

        return $query->get();
    }

    /**
     * Bepaal of een entry een halve dag is op basis van start/eind-tijden.
     *
     * Ochtend half-dag: start=00:00, end=12:30 (Amsterdam)
     * Middag half-dag: start=12:30, end=23:59 (Amsterdam)
     */
    private function isHalfDay(WorkEntry $entry): bool
    {
        $startAt = Carbon::parse($entry->start_at)->timezone('Europe/Amsterdam');
        $endAt = Carbon::parse($entry->end_at)->timezone('Europe/Amsterdam');

        $startTime = $startAt->format('H:i');
        $endTime = $endAt->format('H:i');

        // Ochtend half-dag
        if ($startTime === self::HALF_DAY_MORNING_START && $endTime === self::HALF_DAY_MORNING_END) {
            return true;
        }

        // Middag half-dag
        if ($startTime === self::HALF_DAY_AFTERNOON_START && $endTime === self::HALF_DAY_AFTERNOON_END) {
            return true;
        }

        return false;
    }

    /**
     * Bepaal de status van het verlof-saldo.
     */
    private function determineStatus(?int $annualDays, ?float $remainingDays): string
    {
        if ($annualDays === null) {
            return 'unconfigured';
        }

        if ($remainingDays <= 0) {
            return 'danger';
        }

        if ($remainingDays <= 3) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Resolve de naam van het verlof-type voor een entry.
     * Valt terug op 'Verlof' als er geen leave_type gekoppeld is.
     */
    private function resolveLeaveTypeName(WorkEntry $entry): string
    {
        if (! $this->leaveTypesTableExists()) {
            return 'Verlof';
        }

        if ($entry->leave_type_id === null) {
            return 'Verlof';
        }

        // Lazy-load de relatie als die bestaat
        if ($entry->relationLoaded('leaveType') && $entry->leaveType !== null) {
            return $entry->leaveType->name;
        }

        // Probeer de relatie te laden
        try {
            $entry->load('leaveType');

            return $entry->leaveType?->name ?? 'Verlof';
        } catch (\Exception) {
            return 'Verlof';
        }
    }

    /**
     * Check of de leave_types tabel bestaat.
     * De tabel wordt aangemaakt in task 12.1 en bestaat mogelijk nog niet.
     */
    private function leaveTypesTableExists(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = Schema::hasTable('leave_types')
                && Schema::hasColumn('work_entries', 'leave_type_id');
        }

        return $exists;
    }

    /**
     * Resolve annual_leave_days van de gebruiker.
     * Handelt graceful af als de kolom nog niet bestaat.
     */
    private function resolveAnnualLeaveDays(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        try {
            $value = $user->annual_leave_days;

            return $value !== null ? (int) $value : null;
        } catch (\Exception) {
            return null;
        }
    }
}
