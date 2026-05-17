<?php

namespace App\Services;

use App\Models\AtwViolation;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class AtwService
{
    /**
     * Mapping van AtwEngine signal-type naar publieke ATW-foutcode.
     * Alleen kritieke signalen die HTTP 422 moeten produceren staan in deze map.
     * `WEEKLY_WARNING` (severity warning) en `SIXTEEN_WEEK_AVERAGE` zijn bewust
     * niet opgenomen omdat zij non-blocking zijn (Req 4.2, 4.6, 4.9).
     *
     * @var array<string, string>
     */
    private const CRITICAL_SIGNAL_CODE_MAP = [
        'PAUSE_REQUIRED' => 'ATW_PAUSE_REQUIRED',
        'DAILY_LIMIT' => 'ATW_DAILY_MAX_EXCEEDED',
        'WEEKLY_LIMIT' => 'ATW_WEEKLY_MAX_EXCEEDED',
        'REST_PERIOD' => 'ATW_REST_PERIOD_VIOLATED',
    ];

    public function __construct(
        private readonly AtwEngine $engine,
        private readonly EmailOutboxService $emailOutboxService,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * Geef de publieke ATW-foutcode voor een AtwEngine signal-type, of `null`
     * wanneer het signaal non-blocking is (`WEEKLY_WARNING`, `SIXTEEN_WEEK_AVERAGE`).
     *
     * Wordt door {@see AtwModuleController::postInternalWorkEntriesValidateAtw}
     * aangeroepen zodat de validate-atw-response per signal dezelfde code
     * meegeeft als POST/PATCH zou retourneren bij een 422. `null` markeert
     * expliciet dat het signaal niet leidt tot een blokkering.
     *
     * Requirements: 4.8, 4.9
     */
    public function signalApiCode(string $type): ?string
    {
        return self::CRITICAL_SIGNAL_CODE_MAP[$type] ?? null;
    }

    /**
     * Werp HTTP 422 met de juiste ATW-foutcode wanneer kritieke signalen
     * (PAUSE_REQUIRED, DAILY_LIMIT, WEEKLY_LIMIT, REST_PERIOD) aanwezig zijn.
     *
     * Warnings (`WEEKLY_WARNING`) en `SIXTEEN_WEEK_AVERAGE` blijven non-blocking
     * en worden door deze helper genegeerd.
     *
     * Het response-formaat volgt het bestaande patroon van
     * `WorkEntriesService::buildPauseRequiredException`:
     *
     * ```json
     * {
     *   "error":  "<primary message>",
     *   "code":   "<primary code>",
     *   "errors": { "<code>": ["<message>"] },
     *   "meta":   { "signal_type": "...", "current_minutes": 0, "threshold_minutes": 0 }
     * }
     * ```
     *
     * Het "primaire" signaal is het eerste kritieke signaal in de input-volgorde.
     *
     * Wanneer `$context` `organization_id`, `actor_id` en `employee_id`
     * bevat, wordt vóór het throwen per kritiek signaal één audit-event
     * `ATW_VIOLATION_BLOCKED` geschreven via {@see AuditService::record}.
     * `target_id` is `null` op het create-pad (nog geen werkregel-id) en
     * wordt door de audit-laag opgeslagen als lege string omdat
     * `audit_events.target_id` non-NULL is. Bij update bevat het de
     * werkregel-id als string.
     *
     * Requirements: 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.9
     *
     * @param array<int, array{type?: string, severity?: string, message?: string, current_minutes?: int, threshold_minutes?: int}> $signals
     * @param array{organization_id?: int, actor_id?: int, employee_id?: int, target_id?: int|string|null} $context
     */
    public function throwOnCriticalSignals(array $signals, array $context = []): void
    {
        $critical = [];
        foreach ($signals as $signal) {
            $type = (string) ($signal['type'] ?? '');
            if ($type === '' || ! array_key_exists($type, self::CRITICAL_SIGNAL_CODE_MAP)) {
                continue;
            }

            $critical[] = $signal;
        }

        if ($critical === []) {
            return;
        }

        $this->recordBlockedSignals($critical, $context);

        $primary = $critical[0];
        $primaryType = (string) $primary['type'];
        $primaryCode = self::CRITICAL_SIGNAL_CODE_MAP[$primaryType];
        $primaryMessage = (string) ($primary['message'] ?? '');

        $errors = [];
        foreach ($critical as $signal) {
            $code = self::CRITICAL_SIGNAL_CODE_MAP[(string) $signal['type']];
            $errors[$code][] = (string) ($signal['message'] ?? '');
        }

        throw new HttpResponseException(
            response()->json([
                'error' => $primaryMessage,
                'code' => $primaryCode,
                'errors' => $errors,
                'meta' => [
                    'signal_type' => $primaryType,
                    'current_minutes' => (int) ($primary['current_minutes'] ?? 0),
                    'threshold_minutes' => (int) ($primary['threshold_minutes'] ?? 0),
                ],
            ], 422)
        );
    }

    /**
     * Schrijf één `ATW_VIOLATION_BLOCKED` audit-event per kritiek signaal.
     *
     * Wordt alleen uitgevoerd wanneer alle drie de verplichte
     * context-velden (`organization_id`, `actor_id`, `employee_id`)
     * aanwezig zijn — zo blijft de helper bruikbaar als pure validator
     * (bijvoorbeeld in unit-tests of vooraf-validatie zonder request-
     * context) zónder bijwerking op `audit_events`.
     *
     * Het `target_id`-veld accepteert zowel `null` (create-pad) als een
     * werkregel-id (update-pad). De {@see AuditService::record}-laag
     * cast de waarde naar string; voor `null` wordt dat een lege string
     * omdat de DB-kolom non-NULL is.
     *
     * Requirements: 4.7
     *
     * @param array<int, array{type?: string, current_minutes?: int, threshold_minutes?: int}> $criticalSignals
     * @param array{organization_id?: int, actor_id?: int, employee_id?: int, target_id?: int|string|null} $context
     */
    private function recordBlockedSignals(array $criticalSignals, array $context): void
    {
        if (! isset($context['organization_id'], $context['actor_id'], $context['employee_id'])) {
            return;
        }

        $organizationId = (int) $context['organization_id'];
        $actorId = (int) $context['actor_id'];
        $employeeId = (int) $context['employee_id'];
        $targetId = array_key_exists('target_id', $context) ? $context['target_id'] : null;
        $targetIdString = $targetId === null ? '' : (string) $targetId;

        foreach ($criticalSignals as $signal) {
            $this->auditService->record([
                'organization_id' => $organizationId,
                'actor_id' => $actorId,
                'action' => 'ATW_VIOLATION_BLOCKED',
                'target_type' => 'work_entry',
                'target_id' => $targetIdString,
                'before_data' => [
                    'signal_type' => (string) ($signal['type'] ?? ''),
                    'current_minutes' => (int) ($signal['current_minutes'] ?? 0),
                    'threshold_minutes' => (int) ($signal['threshold_minutes'] ?? 0),
                    'employee_id' => $employeeId,
                ],
                'after_data' => null,
            ]);
        }
    }

    public function validateProposedShift(array $input, int $requesterId): array
    {
        $requester = User::findOrFail($requesterId);
        $employee = User::findOrFail((int) $input['employee_id']);
        $this->assertCanAccessEmployee($requester, $employee);

        $org = Organization::findOrFail($employee->organization_id);

        $policy = [
            'daily_max_minutes' => $org->atw_daily_max_minutes,
            'weekly_max_minutes' => $org->atw_weekly_max_minutes,
            'weekly_warning_minutes' => $org->atw_weekly_warning_minutes,
            'average_16_week_minutes' => $org->atw_average_16_week_minutes,
        ];

        $proposedStart = Carbon::createFromFormat('Y-m-d H:i', $input['entry_date'].' '.$input['start_time'], 'Europe/Amsterdam')->utc();
        $proposedEnd = Carbon::createFromFormat('Y-m-d H:i', $input['entry_date'].' '.$input['end_time'], 'Europe/Amsterdam')->utc();
        $pauseMinutes = (int) ($input['pause_minutes'] ?? 0);
        $grossMinutes = (int) $proposedStart->diffInMinutes($proposedEnd);
        $netMinutes = max(0, $grossMinutes - $pauseMinutes);

        $lookbackStart = $proposedStart->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(15);
        $lookbackEnd = $proposedStart->copy()->endOfWeek(Carbon::SUNDAY);
        $existingShifts = WorkEntry::where('employee_id', $employee->id)
            ->where('start_at', '>=', $lookbackStart)
            ->where('start_at', '<=', $lookbackEnd)
            ->get()
            ->map(fn (WorkEntry $e) => [
                'id' => $e->id,
                'start_at' => $e->start_at->toIso8601String(),
                'end_at' => $e->end_at->toIso8601String(),
                'net_minutes' => $e->net_minutes,
            ])->all();

        $proposedShift = [
            'start_at' => $proposedStart->toIso8601String(),
            'end_at' => $proposedEnd->toIso8601String(),
            'net_minutes' => $netMinutes,
        ];

        $signals = $this->engine->evaluate($proposedShift, $existingShifts, $policy);

        return [
            'employee_id' => $employee->id,
            'entry_date' => $input['entry_date'],
            'net_minutes' => $netMinutes,
            'signals' => $signals,
            'has_critical' => count(array_filter($signals, fn ($s) => $s['severity'] === 'critical')) > 0,
        ];
    }

    public function getSignalsForUser(int $targetUserId, int $requesterId): array
    {
        $requester = User::findOrFail($requesterId);
        $user = User::findOrFail($targetUserId);
        $this->assertCanAccessEmployee($requester, $user);

        return AtwViolation::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn (AtwViolation $v) => [
                'id' => $v->id,
                'violation_type' => $v->violation_type,
                'severity' => $v->severity,
                'period_start' => $v->period_start instanceof \Carbon\Carbon
                    ? $v->period_start->toDateString()
                    : (string) $v->period_start,
                'period_end' => $v->period_end instanceof \Carbon\Carbon
                    ? $v->period_end->toDateString()
                    : (string) $v->period_end,
                'current_minutes' => $v->current_minutes,
                'threshold_minutes' => $v->threshold_minutes,
                'details' => $v->details,
            ])->all();
    }

    public function dispatchSignalsForCreatedEntry(User $employee, User $registrar, array $signals, int $workEntryId): void
    {
        if (empty($signals)) {
            return;
        }

        $owners = User::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->where('role', 'owner')
            ->get();

        $managers = User::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->where('role', 'manager')
            ->when($employee->team_id, fn ($q) => $q->where('team_id', $employee->team_id))
            ->get();

        foreach ($signals as $signal) {
            AtwViolation::query()->create([
                'organization_id' => (int) $employee->organization_id,
                'user_id' => (int) $employee->id,
                'work_entry_id' => $workEntryId,
                'violation_type' => (string) $signal['type'],
                'severity' => (string) $signal['severity'],
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
                'current_minutes' => (int) $signal['current_minutes'],
                'threshold_minutes' => (int) $signal['threshold_minutes'],
                'details' => substr((string) ($signal['message'] ?? ''), 0, 500),
            ]);

            $recipientMap = [];

            foreach ($owners as $owner) {
                $recipientMap[$owner->id] = $owner;
            }

            foreach ($managers as $manager) {
                $recipientMap[$manager->id] = $manager;
            }

            if (($signal['severity'] ?? null) === 'critical') {
                $recipientMap[$employee->id] = $employee;
            }

            foreach ($recipientMap as $recipient) {
                $this->emailOutboxService->dispatch([
                    'idempotency_key' => 'atw-signal-'.$workEntryId.'-'.$signal['type'].'-'.$recipient->id,
                    'organization_id' => (int) $employee->organization_id,
                    'user_id' => (int) $recipient->id,
                    'recipient' => (string) $recipient->email,
                    'subject' => 'ATW-signaal: '.$signal['type'],
                    'body_text' => 'ATW-signaal voor '.$employee->name.': '.$signal['message'].' Huidig: '.$signal['current_minutes'].' min, grens: '.$signal['threshold_minutes'].' min.',
                    'body_html' => '<p>ATW-signaal voor <strong>'.$employee->name.'</strong>.</p><p>'.$signal['message'].'</p><p>Huidig: <strong>'.$signal['current_minutes'].'</strong> min, grens: <strong>'.$signal['threshold_minutes'].'</strong> min.</p>',
                    'type' => 'atw_'.strtolower((string) $signal['type']),
                ], [
                    'actor_id' => (int) $registrar->id,
                    'organization_id' => (int) $registrar->organization_id,
                ]);
            }
        }
    }

    private function assertCanAccessEmployee(User $requester, User $employee): void
    {
        if ($requester->organization_id !== $employee->organization_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Medewerker behoort niet tot uw organisatie.',
            ]);
        }

        if ($requester->role === 'employee' && $requester->id !== $employee->id) {
            throw ValidationException::withMessages([
                'employee_id' => 'U mag alleen uw eigen ATW-gegevens opvragen.',
            ]);
        }

        if ($requester->role === 'manager') {
            if (!$requester->team_id) {
                throw ValidationException::withMessages([
                    'requester' => 'Manager moet gekoppeld zijn aan een team.',
                ]);
            }

            if ($requester->team_id !== $employee->team_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Manager mag alleen ATW-gegevens binnen eigen team opvragen.',
                ]);
            }
        }
    }
}
