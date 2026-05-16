<?php

namespace App\Services;

use App\Models\SystemJobRun;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\CarbonImmutable;

class PendingInputReminderService
{
    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
    ) {
    }

    public function run(?int $organizationId = null, int $days = 1, bool $dryRun = false): array
    {
        $startedAt = CarbonImmutable::now();
        $jobRun = SystemJobRun::query()->create([
            'organization_id' => $organizationId,
            'job_name' => 'reminder.pending_input',
            'status' => 'started',
            'started_at' => $startedAt,
            'details' => [
                'days' => $days,
                'dry_run' => $dryRun,
            ],
        ]);

        try {
            $targetDate = CarbonImmutable::now('Europe/Amsterdam')->subDays(max(1, $days))->toDateString();
            $managers = User::query()
                ->where('is_active', true)
                ->where('role', 'manager')
                ->whereNotNull('team_id')
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->get();

            $dispatched = 0;
            $summaries = [];

            foreach ($managers as $manager) {
                $employees = User::query()
                    ->where('organization_id', $manager->organization_id)
                    ->where('team_id', $manager->team_id)
                    ->where('role', 'employee')
                    ->where('is_active', true)
                    ->get(['id', 'name']);

                if ($employees->isEmpty()) {
                    continue;
                }

                $employeeIds = $employees->pluck('id')->all();
                $reportedIds = WorkEntry::query()
                    ->where('organization_id', $manager->organization_id)
                    ->whereDate('entry_date', $targetDate)
                    ->whereIn('employee_id', $employeeIds)
                    ->distinct()
                    ->pluck('employee_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $missing = $employees->filter(fn ($employee) => !in_array((int) $employee->id, $reportedIds, true));
                if ($missing->isEmpty()) {
                    continue;
                }

                $missingNames = $missing->pluck('name')->values()->all();
                $summaries[] = [
                    'manager_id' => (int) $manager->id,
                    'manager_email' => (string) $manager->email,
                    'organization_id' => (int) $manager->organization_id,
                    'target_date' => $targetDate,
                    'missing_count' => count($missingNames),
                    'missing_names' => $missingNames,
                ];

                if ($dryRun) {
                    continue;
                }

                $this->emailOutboxService->dispatch([
                    'idempotency_key' => 'reminder-open-entries-'.$manager->organization_id.'-'.$manager->team_id.'-'.$targetDate.'-'.$manager->id,
                    'organization_id' => (int) $manager->organization_id,
                    'user_id' => (int) $manager->id,
                    'recipient' => (string) $manager->email,
                    'subject' => 'Herinnering openstaande invoer '.$targetDate,
                    'body_text' => 'Er ontbreken ureninvoeren voor '.$targetDate.' voor: '.implode(', ', $missingNames).'.',
                    'body_html' => '<p>Er ontbreken ureninvoeren voor <strong>'.$targetDate.'</strong> voor: '.e(implode(', ', $missingNames)).'.</p>',
                    'type' => 'reminder_open_entries',
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
                    'days' => $days,
                    'target_date' => $targetDate,
                    'dry_run' => $dryRun,
                    'dispatched' => $dispatched,
                    'managers' => $summaries,
                ],
            ]);

            return [
                'job_run_id' => (int) $jobRun->id,
                'target_date' => $targetDate,
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
}
