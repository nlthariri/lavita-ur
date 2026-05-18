<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * ATW (Arbeidstijdenwet) signalen berekeningsservice.
 * Geport vanuit src/lib/atw/engine.ts
 *
 * Controles:
 * - PAUSE_REQUIRED: bruto werktijd > 5,5 uur (330 min) en pauze < 30 min
 * - DAILY_LIMIT: netto uren van de dag ≥ daglimiet (12 uur = 720 min)
 * - WEEKLY_WARNING: weektotaal nadert weeklimiet (48 uur = 2880 min)
 * - WEEKLY_LIMIT: weektotaal overschrijdt weeklimiet (60 uur = 3600 min)
 * - SIXTEEN_WEEK_AVERAGE: 16-weeks gemiddelde overschrijdt drempel (48 uur/week)
 * - REST_PERIOD: rustperiode tussen diensten < 11 uur (660 min)
 */
class AtwEngine
{
    private const MINIMUM_REST_MINUTES = 660; // 11 uur

    private const PAUSE_REQUIRED_GROSS_THRESHOLD_MINUTES = 330; // 5,5 uur

    private const MINIMUM_PAUSE_MINUTES = 30;

    /**
     * Entry types die niet meetellen voor ATW-werktijd-totalen.
     * Requirements: 7.8
     */
    private const EXCLUDED_ATW_TYPES = ['SICK', 'LEAVE', 'HOLIDAY', 'OTHER'];

    /**
     * @param  array{start_at: string, end_at: string, net_minutes: int}  $proposedShift
     * @param  array<int, array{id: int, start_at: string, end_at: string, net_minutes: int, type?: string}>  $existingShifts
     * @param  array{daily_max_minutes: int, weekly_max_minutes: int, weekly_warning_minutes: int, average_16_week_minutes: int}  $policy
     * @return array<int, array{type: string, severity: string, message: string, threshold_minutes: int, current_minutes: int}>
     */
    public function evaluate(array $proposedShift, array $existingShifts, array $policy): array
    {
        $signals = [];

        $proposedStart = Carbon::parse($proposedShift['start_at']);
        $proposedEnd = Carbon::parse($proposedShift['end_at']);
        $proposedNet = (int) $proposedShift['net_minutes'];

        // --- 0. Pauzeplicht (>5,5u bruto vereist ≥30 min pauze) ---
        $grossMinutes = (int) $proposedStart->diffInMinutes($proposedEnd);
        $pauseMinutes = max(0, $grossMinutes - $proposedNet);

        if ($grossMinutes > self::PAUSE_REQUIRED_GROSS_THRESHOLD_MINUTES && $pauseMinutes < self::MINIMUM_PAUSE_MINUTES) {
            $signals[] = [
                'type' => 'PAUSE_REQUIRED',
                'severity' => 'critical',
                'message' => 'Bij meer dan 5,5 uur werken is minimaal 30 minuten pauze verplicht.',
                'threshold_minutes' => self::MINIMUM_PAUSE_MINUTES,
                'current_minutes' => $pauseMinutes,
            ];
        }

        // --- 1. Daglimiet ---
        if ($proposedNet >= $policy['daily_max_minutes']) {
            $signals[] = [
                'type' => 'DAILY_LIMIT',
                'severity' => 'critical',
                'message' => 'Daglimiet bereikt of overschreden (12 uur).',
                'threshold_minutes' => $policy['daily_max_minutes'],
                'current_minutes' => $proposedNet,
            ];
        }

        // --- 2. Weeklimieten ---
        $weekStart = $proposedStart->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(7);

        $weeklyMinutes = $proposedNet;
        foreach ($existingShifts as $shift) {
            // SICK/LEAVE/HOLIDAY/OTHER dragen 0 minuten bij (Req 7.8)
            $shiftType = strtoupper((string) ($shift['type'] ?? 'WORK'));
            if (in_array($shiftType, self::EXCLUDED_ATW_TYPES, true)) {
                continue;
            }
            $shiftStart = Carbon::parse($shift['start_at']);
            if ($shiftStart->gte($weekStart) && $shiftStart->lt($weekEnd)) {
                $weeklyMinutes += (int) $shift['net_minutes'];
            }
        }

        if ($weeklyMinutes >= $policy['weekly_warning_minutes'] && $weeklyMinutes < $policy['weekly_max_minutes']) {
            $signals[] = [
                'type' => 'WEEKLY_WARNING',
                'severity' => 'warning',
                'message' => 'Naderende ATW-weeklimiet (48 uur of meer in huidige week).',
                'threshold_minutes' => $policy['weekly_warning_minutes'],
                'current_minutes' => $weeklyMinutes,
            ];
        }

        if ($weeklyMinutes >= $policy['weekly_max_minutes']) {
            $signals[] = [
                'type' => 'WEEKLY_LIMIT',
                'severity' => 'critical',
                'message' => 'ATW-weeklimiet overschreden (60 uur).',
                'threshold_minutes' => $policy['weekly_max_minutes'],
                'current_minutes' => $weeklyMinutes,
            ];
        }

        // --- 3. 16-weken gemiddelde (16 volledige ISO-weken incl. huidige week) ---
        $lookbackStart = $proposedStart->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(15);
        $lookbackEnd = $proposedStart->copy()->endOfWeek(Carbon::SUNDAY);
        $total16Weeks = $proposedNet;
        foreach ($existingShifts as $shift) {
            // SICK/LEAVE/HOLIDAY/OTHER dragen 0 minuten bij (Req 7.8)
            $shiftType = strtoupper((string) ($shift['type'] ?? 'WORK'));
            if (in_array($shiftType, self::EXCLUDED_ATW_TYPES, true)) {
                continue;
            }
            $shiftStart = Carbon::parse($shift['start_at']);
            if ($shiftStart->gte($lookbackStart) && $shiftStart->lte($lookbackEnd)) {
                $total16Weeks += (int) $shift['net_minutes'];
            }
        }
        $average16Weeks = (int) floor($total16Weeks / 16);

        if ($average16Weeks >= $policy['average_16_week_minutes']) {
            $signals[] = [
                'type' => 'SIXTEEN_WEEK_AVERAGE',
                'severity' => 'critical',
                'message' => 'Gemiddelde over 16 weken overschrijdt 48 uur per week.',
                'threshold_minutes' => $policy['average_16_week_minutes'],
                'current_minutes' => $average16Weeks,
            ];
        }

        // --- 4. Rustperiode ---
        // 4a. Backward check: vind de laatste shift die VOOR de voorgestelde shift eindigt
        $previousShift = null;
        foreach ($existingShifts as $shift) {
            $shiftEnd = Carbon::parse($shift['end_at']);
            if ($shiftEnd->lte($proposedStart)) {
                if ($previousShift === null || $shiftEnd->gt(Carbon::parse($previousShift['end_at']))) {
                    $previousShift = $shift;
                }
            }
        }

        if ($previousShift !== null) {
            $restMinutes = (int) Carbon::parse($previousShift['end_at'])->diffInMinutes($proposedStart);
            if ($restMinutes < self::MINIMUM_REST_MINUTES) {
                $signals[] = [
                    'type' => 'REST_PERIOD',
                    'severity' => 'critical',
                    'message' => 'Rusttijd tussen diensten is minder dan 11 uur.',
                    'threshold_minutes' => self::MINIMUM_REST_MINUTES,
                    'current_minutes' => $restMinutes,
                ];
            }
        }

        // 4b. Forward check: vind de eerste shift die NA de voorgestelde shift begint
        $nextShift = null;
        foreach ($existingShifts as $shift) {
            $shiftStart = Carbon::parse($shift['start_at']);
            if ($shiftStart->gte($proposedEnd)) {
                if ($nextShift === null || $shiftStart->lt(Carbon::parse($nextShift['start_at']))) {
                    $nextShift = $shift;
                }
            }
        }

        if ($nextShift !== null) {
            $restMinutesForward = (int) $proposedEnd->diffInMinutes(Carbon::parse($nextShift['start_at']));
            if ($restMinutesForward < self::MINIMUM_REST_MINUTES) {
                $signals[] = [
                    'type' => 'REST_PERIOD',
                    'severity' => 'critical',
                    'message' => 'Rusttijd tussen diensten is minder dan 11 uur.',
                    'threshold_minutes' => self::MINIMUM_REST_MINUTES,
                    'current_minutes' => $restMinutesForward,
                ];
            }
        }

        return $signals;
    }
}
