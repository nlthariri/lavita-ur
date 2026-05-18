<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kopieert alle WORK-werkregels van een bronweek naar een doelweek.
 *
 * Validaties:
 * - Alleen owner/manager mag kopiëren (FORBIDDEN_ROLE).
 * - Manager is beperkt tot eigen team (organisatie-scope).
 * - Beide datums moeten maandagen zijn.
 * - Bronweek mag niet leeg zijn (SOURCE_WEEK_EMPTY, 422).
 * - Duplicaten (zelfde employee_id + target_date + start_time) worden
 *   overgeslagen met reden DUPLICATE.
 * - ATW-critical-signalen worden overgeslagen met reden ATW_BLOCKED.
 *
 * Response: { created: WorkEntry[], skipped: { date, start_time, reason }[] }
 *
 * Requirements: 8.1, 8.2, 8.3, 8.4, 8.5
 */
class CopyWeekService
{
    private const ALLOWED_ROLES = ['owner', 'manager'];

    public function __construct(
        private readonly WorkEntriesService $workEntriesService,
    ) {}

    /**
     * Kopieer een werkweek van bron naar doel.
     *
     * @param  int  $employeeId  Het employee-account waarvoor gekopieerd wordt.
     * @param  string  $sourceMon  Maandag van bronweek (Y-m-d).
     * @param  string  $targetMon  Maandag van doelweek (Y-m-d).
     * @param  int  $registrarId  De gebruiker die de actie uitvoert.
     * @return array{created: array[], skipped: array[]}
     */
    public function copyWeek(int $employeeId, string $sourceMon, string $targetMon, int $registrarId): array
    {
        $registrar = User::findOrFail($registrarId);

        // --- Rolcontrole: alleen owner/manager ---
        if (! in_array($registrar->role, self::ALLOWED_ROLES, true)) {
            throw new HttpResponseException(
                response()->json([
                    'error' => 'Alleen eigenaar of manager mag werkweken kopiëren.',
                    'code' => 'FORBIDDEN_ROLE',
                ], 403)
            );
        }

        $employee = User::findOrFail($employeeId);

        // --- Organisatie-scope ---
        if ((int) $registrar->organization_id !== (int) $employee->organization_id) {
            throw new HttpResponseException(
                response()->json([
                    'error' => 'Medewerker behoort niet tot dezelfde organisatie.',
                    'code' => 'FORBIDDEN_ROLE',
                ], 403)
            );
        }

        // --- Team-scope voor manager ---
        if ($registrar->role === 'manager') {
            if (! $registrar->team_id || (int) $registrar->team_id !== (int) $employee->team_id) {
                throw new HttpResponseException(
                    response()->json([
                        'error' => 'Manager mag alleen werkweken kopiëren voor eigen team.',
                        'code' => 'FORBIDDEN_TEAM_SCOPE',
                    ], 403)
                );
            }
        }

        // --- Maandagcheck ---
        $sourceDate = Carbon::parse($sourceMon);
        $targetDate = Carbon::parse($targetMon);

        if ($sourceDate->dayOfWeek !== Carbon::MONDAY) {
            throw ValidationException::withMessages([
                'source_week_start' => 'source_week_start moet een maandag zijn.',
            ]);
        }

        if ($targetDate->dayOfWeek !== Carbon::MONDAY) {
            throw ValidationException::withMessages([
                'target_week_start' => 'target_week_start moet een maandag zijn.',
            ]);
        }

        // --- Bronweek ophalen (alleen WORK-entries, niet soft-deleted) ---
        $sourceSunday = $sourceDate->copy()->addDays(6)->toDateString();

        $sourceEntries = WorkEntry::where('employee_id', $employeeId)
            ->where('type', 'WORK')
            ->whereBetween('entry_date', [$sourceDate->toDateString(), $sourceSunday])
            ->orderBy('entry_date')
            ->orderBy('start_at')
            ->get();

        if ($sourceEntries->isEmpty()) {
            throw new HttpResponseException(
                response()->json([
                    'error' => 'Bronweek bevat geen werkregels.',
                    'code' => 'SOURCE_WEEK_EMPTY',
                ], 422)
            );
        }

        // --- Iteratie: kopieer elke bron-entry naar doelweek ---
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($sourceEntries, $sourceDate, $targetDate, $employeeId, $registrarId, &$created, &$skipped) {
            foreach ($sourceEntries as $sourceEntry) {
                $dayOffset = Carbon::parse($sourceEntry->entry_date)->diffInDays($sourceDate);
                $newEntryDate = $targetDate->copy()->addDays($dayOffset)->toDateString();

                // Bereken start_time en end_time vanuit de bestaande UTC-timestamps
                $originalStart = Carbon::parse($sourceEntry->start_at)->setTimezone('Europe/Amsterdam');
                $originalEnd = Carbon::parse($sourceEntry->end_at)->setTimezone('Europe/Amsterdam');
                $startTime = $originalStart->format('H:i');
                $endTime = $originalEnd->format('H:i');

                // --- Duplicate detection: same (employee_id, target_date, start_time) ---
                // Bereken de verwachte UTC start_at voor de doeldag om exact te
                // matchen op bestaande entries, ongeacht database-engine.
                $expectedStartAt = Carbon::createFromFormat(
                    'Y-m-d H:i',
                    $newEntryDate.' '.$startTime,
                    'Europe/Amsterdam'
                )->utc();

                $duplicate = WorkEntry::where('employee_id', $employeeId)
                    ->where('entry_date', $newEntryDate)
                    ->where('start_at', $expectedStartAt)
                    ->exists();

                if ($duplicate) {
                    $skipped[] = [
                        'date' => $newEntryDate,
                        'start_time' => $startTime,
                        'reason' => 'DUPLICATE',
                    ];
                    continue;
                }

                // --- Probeer aan te maken via WorkEntriesService::create ---
                $input = [
                    'employee_id' => $employeeId,
                    'entry_date' => $newEntryDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'pause_minutes' => (int) $sourceEntry->pause_minutes,
                    'type' => 'WORK',
                    'note' => $sourceEntry->note,
                    'project_id' => $sourceEntry->project_id,
                    'cost_center_id' => $sourceEntry->cost_center_id,
                ];

                try {
                    $entry = $this->workEntriesService->create($input, $registrarId);
                    $created[] = $entry;
                } catch (HttpResponseException $e) {
                    // ATW critical signals gooien HttpResponseException met 422
                    $response = $e->getResponse();
                    $statusCode = $response->getStatusCode();

                    if ($statusCode === 422) {
                        $skipped[] = [
                            'date' => $newEntryDate,
                            'start_time' => $startTime,
                            'reason' => 'ATW_BLOCKED',
                        ];
                    } elseif ($statusCode === 403) {
                        // Autorisatiefout: scope-overtreding. Niet maskeren
                        // als ATW — geef de werkelijke reden door.
                        $skipped[] = [
                            'date' => $newEntryDate,
                            'start_time' => $startTime,
                            'reason' => 'FORBIDDEN',
                        ];
                    } else {
                        // Onverwachte HTTP-fout: propageer zodat de caller
                        // het probleem kan diagnosticeren.
                        throw $e;
                    }
                } catch (ValidationException $e) {
                    // ValidationException kan ontstaan door ATW_PAUSE_REQUIRED
                    // of andere validatiefouten — skip met ATW_BLOCKED reden
                    $skipped[] = [
                        'date' => $newEntryDate,
                        'start_time' => $startTime,
                        'reason' => 'ATW_BLOCKED',
                    ];
                }
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }
}
