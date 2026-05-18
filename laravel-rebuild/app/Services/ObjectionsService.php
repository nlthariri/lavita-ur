<?php

namespace App\Services;

use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObjectionsService
{
    private const ALLOWED_REVIEW_ROLES = ['owner', 'manager'];

    private const STATUS_OPEN = 'OPEN';

    private const STATUS_APPROVED = 'APPROVED';

    private const STATUS_REJECTED = 'REJECTED';

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly AuditService $auditService,
    ) {}

    public function submit(array $input, int $submitterId): array
    {
        $submitter = User::findOrFail($submitterId);

        if ($submitter->role !== 'employee') {
            throw ValidationException::withMessages([
                'submitter' => 'Alleen medewerkers kunnen bezwaar indienen.',
            ]);
        }

        $entry = WorkEntry::findOrFail((int) $input['work_entry_id']);

        if ($entry->organization_id !== $submitter->organization_id) {
            throw ValidationException::withMessages([
                'work_entry_id' => 'Werkregel behoort niet tot uw organisatie.',
            ]);
        }

        if ($entry->employee_id !== $submitter->id) {
            throw ValidationException::withMessages([
                'work_entry_id' => 'U mag alleen bezwaar indienen op uw eigen urenregels.',
            ]);
        }

        // Wrap in transactie om race condition te voorkomen: twee
        // gelijktijdige submits zouden anders beide de $existing-check
        // passeren en dan op de unique constraint crashen.
        return DB::transaction(function () use ($input, $submitter, $entry): array {
            $existing = Objection::where('work_entry_id', $entry->id)
                ->where('status', self::STATUS_OPEN)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'work_entry_id' => 'Er is al een openstaand bezwaar voor deze werkregel.',
                ]);
            }

            $objection = Objection::create([
                'organization_id' => $submitter->organization_id,
                'work_entry_id' => $entry->id,
                'submitted_by_id' => $submitter->id,
                'motivation' => substr(trim($input['motivation']), 0, 2000),
                'status' => self::STATUS_OPEN,
                'submitted_at' => now(),
            ]);

            $this->notifyReviewersOfSubmittedObjection($objection, $entry, $submitter);

            return $this->toArray($objection);
        });
    }

    public function review(int $objectionId, array $input, int $reviewerId): array
    {
        $reviewer = User::findOrFail($reviewerId);

        if (! in_array($reviewer->role, self::ALLOWED_REVIEW_ROLES, true)) {
            throw ValidationException::withMessages([
                'reviewer' => 'Alleen eigenaar of manager mag bezwaren beoordelen.',
            ]);
        }

        $decision = $input['decision'];
        if (! in_array($decision, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Beslissing moet APPROVED of REJECTED zijn.',
            ]);
        }

        if ($decision === self::STATUS_REJECTED && empty(trim($input['manager_response'] ?? ''))) {
            throw ValidationException::withMessages([
                'manager_response' => 'Motivatie is verplicht bij afwijzing.',
            ]);
        }

        if ($decision === self::STATUS_APPROVED) {
            $this->assertCorrectionInput($input);

            // Valideer corrected_pause_minutes is niet negatief
            if (isset($input['corrected_pause_minutes']) && (int) $input['corrected_pause_minutes'] < 0) {
                throw ValidationException::withMessages([
                    'corrected_pause_minutes' => 'Gecorrigeerde pauze mag niet negatief zijn.',
                ]);
            }
        }

        return DB::transaction(function () use ($objectionId, $input, $reviewer, $decision): array {
            /** @var Objection $objection */
            $objection = Objection::lockForUpdate()->findOrFail($objectionId);

            if ($objection->organization_id !== $reviewer->organization_id) {
                throw ValidationException::withMessages([
                    'objection' => 'Bezwaar behoort niet tot uw organisatie.',
                ]);
            }

            // Team-scope voor managers: mag alleen bezwaren beoordelen
            // op werkregels van het eigen team (enterprise-hardening).
            if ($reviewer->role === 'manager') {
                $workEntry = WorkEntry::find($objection->work_entry_id);
                if ($workEntry && $reviewer->team_id && (int) $workEntry->team_id !== (int) $reviewer->team_id) {
                    throw ValidationException::withMessages([
                        'objection' => 'Manager mag alleen bezwaren van het eigen team beoordelen.',
                    ]);
                }
            }

            if ($objection->status !== self::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'status' => 'Dit bezwaar is al beoordeeld (status: '.$objection->status.').',
                ]);
            }

            $workEntry = WorkEntry::query()->lockForUpdate()->findOrFail($objection->work_entry_id);

            $beforeSnapshot = [
                'start_at' => $workEntry->start_at?->toIso8601String(),
                'end_at' => $workEntry->end_at?->toIso8601String(),
                'pause_minutes' => $workEntry->pause_minutes,
                'net_minutes' => $workEntry->net_minutes,
            ];

            $afterSnapshot = null;
            $correctedStart = null;
            $correctedEnd = null;
            $correctedPauseMinutes = null;

            if ($decision === self::STATUS_APPROVED) {
                $correctedStart = Carbon::createFromFormat('Y-m-d H:i', $workEntry->entry_date->toDateString().' '.$input['corrected_start_time'], 'Europe/Amsterdam')->utc();
                $correctedEnd = Carbon::createFromFormat('Y-m-d H:i', $workEntry->entry_date->toDateString().' '.$input['corrected_end_time'], 'Europe/Amsterdam')->utc();

                if ($correctedEnd->lte($correctedStart)) {
                    throw ValidationException::withMessages([
                        'corrected_end_time' => 'Gecorrigeerde eindtijd moet na de gecorrigeerde begintijd liggen.',
                    ]);
                }

                $correctedPauseMinutes = (int) $input['corrected_pause_minutes'];
                $grossMinutes = (int) $correctedStart->diffInMinutes($correctedEnd);
                $netMinutes = max(0, $grossMinutes - $correctedPauseMinutes);

                $workEntry->update([
                    'start_at' => $correctedStart,
                    'end_at' => $correctedEnd,
                    'pause_minutes' => $correctedPauseMinutes,
                    'net_minutes' => $netMinutes,
                ]);

                $afterSnapshot = [
                    'start_at' => $workEntry->fresh()->start_at?->toIso8601String(),
                    'end_at' => $workEntry->fresh()->end_at?->toIso8601String(),
                    'pause_minutes' => $workEntry->fresh()->pause_minutes,
                    'net_minutes' => $workEntry->fresh()->net_minutes,
                ];
            }

            $objection->update([
                'status' => $decision,
                'reviewed_by_id' => $reviewer->id,
                'manager_response' => isset($input['manager_response']) ? substr(trim($input['manager_response']), 0, 2000) : null,
                'corrected_start_at' => $correctedStart,
                'corrected_end_at' => $correctedEnd,
                'corrected_pause_minutes' => $correctedPauseMinutes,
                'work_entry_before' => $beforeSnapshot,
                'work_entry_after' => $afterSnapshot,
                'reviewed_at' => now(),
            ]);

            if ($decision === self::STATUS_APPROVED && $afterSnapshot !== null) {
                $this->auditService->record([
                    'organization_id' => $reviewer->organization_id,
                    'actor_id' => $reviewer->id,
                    'action' => 'objection_approved_work_entry_corrected',
                    'target_type' => 'work_entry',
                    'target_id' => (string) $workEntry->id,
                    'before_data' => $beforeSnapshot,
                    'after_data' => $afterSnapshot,
                ]);
            }

            if ($decision === self::STATUS_REJECTED) {
                $this->auditService->record([
                    'organization_id' => $reviewer->organization_id,
                    'actor_id' => $reviewer->id,
                    'action' => 'objection_rejected',
                    'target_type' => 'objection',
                    'target_id' => (string) $objection->id,
                    'before_data' => ['status' => self::STATUS_OPEN],
                    'after_data' => [
                        'status' => self::STATUS_REJECTED,
                        'manager_response' => $objection->manager_response,
                        'work_entry_id' => (int) $objection->work_entry_id,
                    ],
                ]);
            }

            $this->notifySubmitterOfReviewOutcome($objection, $reviewer, $decision);

            return $this->toArray($objection->fresh());
        });
    }

    public function list(int $userId, ?array $filters = []): array
    {
        $user = User::findOrFail($userId);

        $query = Objection::where('organization_id', $user->organization_id)
            ->orderBy('submitted_at', 'desc');

        if ($user->role === 'employee') {
            $query->whereHas('workEntry', fn ($q) => $q->where('employee_id', $user->id));
        } elseif ($user->role === 'manager' && $user->team_id) {
            $query->whereHas('workEntry', fn ($q) => $q->where('team_id', $user->team_id));
        }

        if (! empty($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        return $query->limit(200)->get()->map(fn (Objection $o) => $this->toArray($o))->all();
    }

    private function toArray(Objection $objection): array
    {
        return [
            'id' => $objection->id,
            'work_entry_id' => $objection->work_entry_id,
            'submitted_by_id' => $objection->submitted_by_id,
            'reviewed_by_id' => $objection->reviewed_by_id,
            'motivation' => $objection->motivation,
            'manager_response' => $objection->manager_response,
            'corrected_start_at' => $objection->corrected_start_at?->toIso8601String(),
            'corrected_end_at' => $objection->corrected_end_at?->toIso8601String(),
            'corrected_pause_minutes' => $objection->corrected_pause_minutes,
            'work_entry_before' => $objection->work_entry_before,
            'work_entry_after' => $objection->work_entry_after,
            'status' => $objection->status,
            'submitted_at' => $objection->submitted_at?->toIso8601String(),
            'reviewed_at' => $objection->reviewed_at?->toIso8601String(),
        ];
    }

    private function assertCorrectionInput(array $input): void
    {
        $missing = [];
        foreach (['corrected_start_time', 'corrected_end_time', 'corrected_pause_minutes'] as $key) {
            if (! array_key_exists($key, $input)) {
                $missing[] = $key;
            }
        }

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'correction' => 'Bij goedkeuring zijn corrected_start_time, corrected_end_time en corrected_pause_minutes verplicht.',
            ]);
        }
    }

    private function notifyReviewersOfSubmittedObjection(Objection $objection, WorkEntry $entry, User $submitter): void
    {
        $recipients = User::query()
            ->where('organization_id', $submitter->organization_id)
            ->where('is_active', true)
            ->where(function ($query) use ($entry): void {
                $query->where('role', 'owner')
                    ->orWhere(function ($managerQuery) use ($entry): void {
                        $managerQuery->where('role', 'manager');

                        if ($entry->team_id) {
                            $managerQuery->where('team_id', $entry->team_id);
                        }
                    });
            })
            ->get();

        $safeName = e($submitter->name);
        $safeEntryId = (int) $entry->id;

        foreach ($recipients as $recipient) {
            $this->emailOutboxService->dispatch([
                'idempotency_key' => 'objection-submitted-'.$objection->id.'-'.$recipient->id,
                'organization_id' => (int) $submitter->organization_id,
                'user_id' => (int) $recipient->id,
                'recipient' => (string) $recipient->email,
                'subject' => 'Nieuw bezwaar ingediend',
                'body_text' => 'Er is een nieuw bezwaar ingediend voor werkregel #'.$entry->id.' door '.$submitter->name.'.',
                'body_html' => '<p>Er is een nieuw bezwaar ingediend voor werkregel <strong>#'.$entry->id.'</strong> door <strong>'.$safeName.'</strong>.</p>',
                'type' => 'objection_submitted',
            ], [
                'actor_id' => (int) $submitter->id,
                'organization_id' => (int) $submitter->organization_id,
            ]);
        }
    }

    private function notifySubmitterOfReviewOutcome(Objection $objection, User $reviewer, string $decision): void
    {
        $submitter = User::query()->find($objection->submitted_by_id);
        if (! $submitter || ! $submitter->is_active) {
            return;
        }

        $response = $objection->manager_response ? (' Toelichting: '.$objection->manager_response) : '';
        $statusLabel = $decision === self::STATUS_APPROVED ? 'goedgekeurd' : 'afgewezen';
        $safeReviewerName = e($reviewer->name);
        $safeResponse = e($response);

        $this->emailOutboxService->dispatch([
            'idempotency_key' => 'objection-reviewed-'.$objection->id,
            'organization_id' => (int) $submitter->organization_id,
            'user_id' => (int) $submitter->id,
            'recipient' => (string) $submitter->email,
            'subject' => 'Uitkomst bezwaar: '.$statusLabel,
            'body_text' => 'Uw bezwaar voor werkregel #'.$objection->work_entry_id.' is '.$statusLabel.' door '.$reviewer->name.'.'.$response,
            'body_html' => '<p>Uw bezwaar voor werkregel <strong>#'.$objection->work_entry_id.'</strong> is <strong>'.$statusLabel.'</strong> door <strong>'.$safeReviewerName.'</strong>.</p><p>'.$safeResponse.'</p>',
            'type' => 'objection_reviewed',
        ], [
            'actor_id' => (int) $reviewer->id,
            'organization_id' => (int) $reviewer->organization_id,
        ]);
    }
}
