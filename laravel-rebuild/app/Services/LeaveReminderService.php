<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\SystemJobRun;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\CarbonImmutable;

/**
 * LeaveReminderService
 *
 * Controleert onbehandelde verlofaanvragen die langer dan 3 werkdagen
 * openstaan en stuurt een herinneringsmail naar de manager(s) van het team.
 *
 * Respecteert opt-out:
 *  - Herinneringen worden NIET verstuurd als de medewerker
 *    `email_reminders_opt_in = false` heeft (Requirement 13.7).
 *  - Essentiële mails (goedkeuring/afwijzing) worden altijd verstuurd
 *    via LeaveNotificationService — die logica zit niet in deze service.
 *
 * Requirements: 13.5, 13.7
 */
class LeaveReminderService
{
    private const DEFAULT_THRESHOLD_DAYS = 3;

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly EmailTemplateService $emailTemplateService,
    ) {}

    /**
     * Run de verlof-herinnering check.
     *
     * @param  int|null  $organizationId  Optioneel: alleen voor deze organisatie.
     * @param  int|null  $thresholdOverride  Optioneel: overschrijf de 3-werkdagen threshold.
     * @param  bool  $dryRun  Als true, geen mails versturen.
     * @return array{job_run_id: int, dispatched: int, reminders: array}
     */
    public function run(?int $organizationId = null, ?int $thresholdOverride = null, bool $dryRun = false): array
    {
        $startedAt = CarbonImmutable::now();
        $jobRun = SystemJobRun::query()->create([
            'organization_id' => $organizationId,
            'job_name' => 'reminder.leave_pending',
            'status' => 'started',
            'started_at' => $startedAt,
            'details' => [
                'threshold_override' => $thresholdOverride,
                'dry_run' => $dryRun,
            ],
        ]);

        try {
            $threshold = $thresholdOverride !== null
                ? max(1, min(14, $thresholdOverride))
                : self::DEFAULT_THRESHOLD_DAYS;

            // Bereken de cutoff-datum: aanvragen ouder dan N werkdagen.
            $today = CarbonImmutable::now('Europe/Amsterdam')->startOfDay();
            $cutoffDate = $this->subtractBusinessDays($today, $threshold);

            // Vind alle onbehandelde verlofaanvragen (PENDING = is_finalized=false, niet soft-deleted)
            // die zijn aangemaakt vóór de cutoff-datum.
            $pendingEntries = WorkEntry::query()
                ->whereNull('deleted_at')
                ->where('is_finalized', false)
                ->whereIn('type', ['LEAVE', 'SICK'])
                ->where('created_at', '<=', $cutoffDate->endOfDay())
                ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
                ->with(['employee'])
                ->get();

            $dispatched = 0;
            $reminders = [];

            foreach ($pendingEntries as $entry) {
                $employee = $entry->employee;
                if ($employee === null || ! $employee->is_active) {
                    continue;
                }

                // Respecteer opt-out: herinneringen alleen bij opt-in (Req 13.7).
                if (! (bool) ($employee->email_reminders_opt_in ?? true)) {
                    continue;
                }

                $orgId = (int) $entry->organization_id;
                $teamId = (int) ($entry->team_id ?? $employee->team_id);

                if ($teamId <= 0) {
                    continue;
                }

                // Vind managers van het team + owners van de organisatie.
                $managers = User::query()
                    ->where('organization_id', $orgId)
                    ->where('is_active', true)
                    ->where(function ($query) use ($teamId) {
                        $query->where(function ($q) use ($teamId) {
                            $q->where('role', 'manager')
                              ->where('team_id', $teamId);
                        })->orWhere('role', 'owner');
                    })
                    ->get();

                if ($managers->isEmpty()) {
                    continue;
                }

                $employeeName = (string) ($employee->full_name ?? $employee->name);
                $leaveDate = (string) $entry->entry_date;

                foreach ($managers as $manager) {
                    $idempotencyKey = 'leave-reminder-' . $entry->id . '-' . $manager->id . '-' . $today->toDateString();

                    $reminders[] = [
                        'entry_id' => (int) $entry->id,
                        'employee_name' => $employeeName,
                        'manager_id' => (int) $manager->id,
                        'manager_email' => (string) $manager->email,
                        'organization_id' => $orgId,
                        'leave_date' => $leaveDate,
                    ];

                    if ($dryRun) {
                        continue;
                    }

                    $vars = [
                        'manager_name' => (string) ($manager->full_name ?? $manager->name),
                        'employee_name' => $employeeName,
                        'leave_date' => $leaveDate,
                    ];

                    $rendered = $this->emailTemplateService->render('leave_reminder', $vars, $orgId);

                    $this->emailOutboxService->dispatch([
                        'idempotency_key' => $idempotencyKey,
                        'organization_id' => $orgId,
                        'user_id' => (int) $manager->id,
                        'recipient' => (string) $manager->email,
                        'subject' => $rendered['subject'],
                        'body_text' => $rendered['body_text'],
                        'body_html' => $rendered['body_html'],
                        'type' => 'leave_reminder',
                    ]);

                    $dispatched++;
                }
            }

            $finishedAt = CarbonImmutable::now();
            $jobRun->update([
                'status' => 'completed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'rows_affected' => $dispatched,
                'details' => [
                    'threshold_override' => $thresholdOverride,
                    'dry_run' => $dryRun,
                    'threshold_days' => $threshold,
                    'cutoff_date' => $cutoffDate->toDateString(),
                    'pending_entries_found' => $pendingEntries->count(),
                    'dispatched' => $dispatched,
                    'reminders' => $reminders,
                ],
            ]);

            return [
                'job_run_id' => (int) $jobRun->id,
                'dispatched' => $dispatched,
                'reminders' => $reminders,
            ];
        } catch (\Throwable $exception) {
            $finishedAt = CarbonImmutable::now();
            $jobRun->update([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'error_message' => substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }

    /**
     * Trek N werkdagen af van een datum (alleen ma-vr tellen als werkdagen).
     */
    public function subtractBusinessDays(CarbonImmutable $date, int $days): CarbonImmutable
    {
        $current = $date;
        $subtracted = 0;

        while ($subtracted < $days) {
            $current = $current->subDay();
            if ($current->isWeekday()) {
                $subtracted++;
            }
        }

        return $current;
    }
}
