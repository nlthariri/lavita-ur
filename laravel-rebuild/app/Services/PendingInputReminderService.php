<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SystemJobRun;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\CarbonImmutable;

class PendingInputReminderService
{
    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
    ) {}

    /**
     * Run the pending-input reminder check.
     *
     * Logic (Req 9.4, 9.5):
     *  - Uses `organization.pending_input_threshold_days` (default 3) as threshold.
     *  - Only considers weekdays (Monday–Friday) for WORK-entries.
     *  - Respects `email_reminders_opt_in = false` → skips those employees.
     *  - Sends `pending_input_reminder` to manager(s) of the employee's team.
     */
    public function run(?int $organizationId = null, ?int $thresholdOverride = null, bool $dryRun = false): array
    {
        $startedAt = CarbonImmutable::now();
        $jobRun = SystemJobRun::query()->create([
            'organization_id' => $organizationId,
            'job_name' => 'reminder.pending_input',
            'status' => 'started',
            'started_at' => $startedAt,
            'details' => [
                'threshold_override' => $thresholdOverride,
                'dry_run' => $dryRun,
            ],
        ]);

        try {
            $managers = User::query()
                ->where('is_active', true)
                ->where('role', 'manager')
                ->whereNotNull('team_id')
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->get();

            $dispatched = 0;
            $summaries = [];

            // Cache organization threshold per org_id to avoid repeated queries.
            $orgThresholds = [];

            foreach ($managers as $manager) {
                $orgId = (int) $manager->organization_id;

                // Resolve threshold: override > organization setting > default 3.
                if ($thresholdOverride !== null) {
                    $threshold = max(1, min(14, $thresholdOverride));
                } else {
                    if (! isset($orgThresholds[$orgId])) {
                        $org = Organization::find($orgId);
                        $orgThresholds[$orgId] = $org !== null
                            ? (int) ($org->pending_input_threshold_days ?? 3)
                            : 3;
                    }
                    $threshold = $orgThresholds[$orgId];
                }

                // Compute the weekdays (Mon–Fri) to check: the last N weekdays
                // before today (Europe/Amsterdam).
                $today = CarbonImmutable::now('Europe/Amsterdam')->startOfDay();
                $checkDates = $this->getLastNWeekdays($today, $threshold);

                if (empty($checkDates)) {
                    continue;
                }

                // Only include employees who opted in to email reminders (Req 9.5).
                $employees = User::query()
                    ->where('organization_id', $orgId)
                    ->where('team_id', $manager->team_id)
                    ->where('role', 'employee')
                    ->where('is_active', true)
                    ->where('email_reminders_opt_in', true)
                    ->get(['id', 'name']);

                if ($employees->isEmpty()) {
                    continue;
                }

                $employeeIds = $employees->pluck('id')->all();

                // Find employees who have at least one WORK entry on any of the check dates.
                $reportedIds = WorkEntry::query()
                    ->where('organization_id', $orgId)
                    ->whereIn('entry_date', $checkDates)
                    ->where('type', 'WORK')
                    ->whereIn('employee_id', $employeeIds)
                    ->distinct()
                    ->pluck('employee_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                // Missing = employees with NO WORK entries in the last N weekdays.
                $missing = $employees->filter(fn ($employee) => ! in_array((int) $employee->id, $reportedIds, true));
                if ($missing->isEmpty()) {
                    continue;
                }

                $missingNames = $missing->pluck('name')->values()->all();
                $periodStart = $checkDates[count($checkDates) - 1] ?? '';
                $periodEnd = $checkDates[0] ?? '';

                $summaries[] = [
                    'manager_id' => (int) $manager->id,
                    'manager_email' => (string) $manager->email,
                    'organization_id' => $orgId,
                    'threshold_days' => $threshold,
                    'check_dates' => $checkDates,
                    'missing_count' => count($missingNames),
                    'missing_names' => $missingNames,
                ];

                if ($dryRun) {
                    continue;
                }

                $idempotencyKey = 'pending-input-reminder-'.$orgId.'-'.$manager->team_id.'-'.$periodEnd.'-'.$manager->id;

                $this->emailOutboxService->dispatch([
                    'idempotency_key' => $idempotencyKey,
                    'organization_id' => $orgId,
                    'user_id' => (int) $manager->id,
                    'recipient' => (string) $manager->email,
                    'subject' => 'Herinnering openstaande invoer',
                    'body_text' => 'De volgende medewerkers hebben de afgelopen '.$threshold.' werkdagen geen uren ingevoerd: '.implode(', ', $missingNames).'.',
                    'body_html' => '<p>De volgende medewerkers hebben de afgelopen <strong>'.$threshold.'</strong> werkdagen geen uren ingevoerd: '.e(implode(', ', $missingNames)).'.</p>',
                    'type' => 'pending_input_reminder',
                ]);
                $dispatched++;
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
                    'dispatched' => $dispatched,
                    'managers' => $summaries,
                ],
            ]);

            return [
                'job_run_id' => (int) $jobRun->id,
                'dispatched' => $dispatched,
                'managers' => $summaries,
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
     * Get the last N weekdays (Mon–Fri) before the given date, ordered
     * from most recent to oldest (descending).
     *
     * @return string[] Array of 'Y-m-d' date strings.
     */
    public function getLastNWeekdays(CarbonImmutable $beforeDate, int $count): array
    {
        $dates = [];
        $current = $beforeDate->subDay();

        while (count($dates) < $count) {
            // isWeekday() returns true for Mon–Fri.
            if ($current->isWeekday()) {
                $dates[] = $current->toDateString();
            }
            $current = $current->subDay();
        }

        return $dates;
    }
}
