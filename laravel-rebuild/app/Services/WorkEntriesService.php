<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkEntriesService
{
    private const ALLOWED_ROLES = ['owner', 'manager'];
    private const MIN_PAUSE_FOR_LONG_SHIFT_MINUTES = 60;
    private const LONG_SHIFT_THRESHOLD_MINUTES = 330;

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly AtwService $atwService,
    ) {
    }

    public function create(array $input, int $registrarId): array
    {
        $registrar = User::findOrFail($registrarId);
        $this->assertAllowedRegistrar($registrar);

        $employee = User::findOrFail($input['employee_id']);
        $this->assertSameOrganization($registrar, $employee);
        $this->assertTeamScope($registrar, $employee);

        $startAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $input['entry_date'].' '.$input['start_time'],
            'Europe/Amsterdam'
        )->utc();

        $endAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $input['entry_date'].' '.$input['end_time'],
            'Europe/Amsterdam'
        )->utc();

        if ($endAt->lte($startAt)) {
            throw ValidationException::withMessages([
                'end_time' => 'Eindtijd moet na begintijd liggen.',
            ]);
        }

        $grossMinutes = (int) $startAt->diffInMinutes($endAt);
        $pauseMinutes = (int) ($input['pause_minutes'] ?? 0);

        if ($grossMinutes > self::LONG_SHIFT_THRESHOLD_MINUTES
            && $pauseMinutes < self::MIN_PAUSE_FOR_LONG_SHIFT_MINUTES
        ) {
            throw ValidationException::withMessages([
                'pause_minutes' => 'Bij meer dan 5,5 uur werken is minimaal 60 minuten pauze verplicht.',
            ]);
        }

        $netMinutes = max(0, $grossMinutes - $pauseMinutes);
        $entryDate = Carbon::parse($input['entry_date'])->toDateString();
        $atwValidation = $this->atwService->validateProposedShift([
            'employee_id' => (int) $employee->id,
            'entry_date' => $entryDate,
            'start_time' => $input['start_time'],
            'end_time' => $input['end_time'],
            'pause_minutes' => $pauseMinutes,
        ], (int) $registrar->id);

        return DB::transaction(function () use ($input, $registrar, $employee, $startAt, $endAt, $pauseMinutes, $netMinutes, $entryDate, $atwValidation): array {
            $entry = WorkEntry::create([
                'organization_id' => $registrar->organization_id,
                'employee_id' => $employee->id,
                'team_id' => $employee->team_id,
                'registered_by_id' => $registrar->id,
                'entry_date' => $entryDate,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'pause_minutes' => $pauseMinutes,
                'net_minutes' => $netMinutes,
                'type' => $input['type'] ?? 'WORK',
                'note' => isset($input['note']) ? substr(trim($input['note']), 0, 500) : null,
                'is_finalized' => true,
            ]);

            $this->emailOutboxService->dispatch([
                'idempotency_key' => 'work-entry-finalized-'.$entry->id,
                'organization_id' => (int) $registrar->organization_id,
                'user_id' => (int) $employee->id,
                'recipient' => (string) $employee->email,
                'subject' => 'Uren zijn vastgesteld',
                'body_text' => 'Uw uren voor '.$entryDate.' zijn vastgesteld. Netto minuten: '.$netMinutes.'.',
                'body_html' => '<p>Uw uren voor <strong>'.$entryDate.'</strong> zijn vastgesteld.</p><p>Netto minuten: <strong>'.$netMinutes.'</strong>.</p>',
                'type' => 'work_entry_finalized',
            ], [
                'actor_id' => (int) $registrar->id,
                'organization_id' => (int) $registrar->organization_id,
            ]);

            $this->atwService->dispatchSignalsForCreatedEntry(
                $employee,
                $registrar,
                $atwValidation['signals'] ?? [],
                (int) $entry->id,
            );

            return [
                'id' => $entry->id,
                'employee_id' => $entry->employee_id,
                'entry_date' => $entry->entry_date->toDateString(),
                'start_at' => $entry->start_at->toIso8601String(),
                'end_at' => $entry->end_at->toIso8601String(),
                'pause_minutes' => $entry->pause_minutes,
                'net_minutes' => $entry->net_minutes,
                'type' => $entry->type,
                'is_finalized' => $entry->is_finalized,
            ];
        });
    }

    public function list(int $registrarId, array $filters = []): array
    {
        $registrar = User::findOrFail($registrarId);
        $this->assertAllowedRegistrar($registrar);

        $query = WorkEntry::where('organization_id', $registrar->organization_id)
            ->orderBy('entry_date', 'desc')
            ->orderBy('start_at', 'desc');

        if ($registrar->role === 'manager' && $registrar->team_id) {
            $query->where('team_id', $registrar->team_id);
        }

        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('entry_date', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('entry_date', '<=', $filters['to']);
        }

        return $query->limit(200)->get()->map(fn (WorkEntry $e) => [
            'id' => $e->id,
            'employee_id' => $e->employee_id,
            'entry_date' => $e->entry_date->toDateString(),
            'start_at' => $e->start_at->toIso8601String(),
            'end_at' => $e->end_at->toIso8601String(),
            'net_minutes' => $e->net_minutes,
            'type' => $e->type,
            'is_finalized' => $e->is_finalized,
        ])->all();
    }

    private function assertAllowedRegistrar(User $registrar): void
    {
        if (!in_array($registrar->role, self::ALLOWED_ROLES, true)) {
            throw ValidationException::withMessages([
                'registrar' => 'Alleen eigenaar of manager mag uren registreren.',
            ]);
        }
    }

    private function assertSameOrganization(User $registrar, User $employee): void
    {
        if ($registrar->organization_id !== $employee->organization_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Medewerker behoort niet tot dezelfde organisatie.',
            ]);
        }
    }

    private function assertTeamScope(User $registrar, User $employee): void
    {
        if ($registrar->role !== 'manager') {
            return;
        }

        if (!$registrar->team_id) {
            throw ValidationException::withMessages([
                'registrar' => 'Manager moet gekoppeld zijn aan een team.',
            ]);
        }

        if ($registrar->team_id !== $employee->team_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Manager mag alleen uren registreren voor eigen team.',
            ]);
        }
    }
}
