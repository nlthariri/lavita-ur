<?php

namespace App\Services;

use App\Models\AtwViolation;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AtwService
{
    public function __construct(
        private readonly AtwEngine $engine,
        private readonly EmailOutboxService $emailOutboxService,
    ) {
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
