<?php

namespace App\Services;

use App\Models\SystemJobRun;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AnniversaryNotificationService
{
    private const MILESTONE_YEARS = [1, 5, 10, 25];

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly EmailTemplateService $emailTemplateService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Dispatch anniversary notifications for all matching users on the given date.
     *
     * Matches users where:
     *  - employment_start month == today.month AND day == today.day
     *  - year difference ∈ {1, 5, 10, 25}
     *  - is_active = true
     *  - employment_start is not null
     *
     * Dispatches email to employee + manager(s) of their team.
     * Writes audit-event ANNIVERSARY_DISPATCHED per dispatched mail.
     *
     * @return array{job_run_id: int, dispatched: int, matches: array}
     */
    public function dispatchForDate(Carbon $today): array
    {
        $startedAt = CarbonImmutable::now();
        $jobRun = SystemJobRun::query()->create([
            'organization_id' => null,
            'job_name' => 'notifications.anniversary',
            'status' => 'started',
            'started_at' => $startedAt,
            'details' => [
                'date' => $today->toDateString(),
            ],
        ]);

        try {
            $matches = $this->findMatchingUsers($today);
            $dispatched = 0;
            $matchDetails = [];

            foreach ($matches as $user) {
                $years = $today->year - Carbon::parse($user->employment_start)->year;

                $rendered = $this->emailTemplateService->render('anniversary', [
                    'full_name' => (string) ($user->full_name ?? $user->name),
                    'years' => (string) $years,
                    'employment_start' => Carbon::parse($user->employment_start)->format('d-m-Y'),
                ], (int) $user->organization_id);

                // Dispatch to employee
                $employeeIdempotencyKey = 'anniversary-'.$today->toDateString().'-employee-'.$user->id;
                $this->emailOutboxService->dispatch([
                    'idempotency_key' => $employeeIdempotencyKey,
                    'organization_id' => (int) $user->organization_id,
                    'user_id' => (int) $user->id,
                    'recipient' => (string) $user->email,
                    'subject' => $rendered['subject'],
                    'body_text' => $rendered['body_text'],
                    'body_html' => $rendered['body_html'],
                    'type' => 'anniversary',
                ]);
                $dispatched++;

                $this->auditService->record([
                    'organization_id' => (int) $user->organization_id,
                    'actor_id' => null,
                    'action' => 'ANNIVERSARY_DISPATCHED',
                    'target_type' => 'user',
                    'target_id' => (string) $user->id,
                    'after_data' => [
                        'years' => $years,
                        'recipient_type' => 'employee',
                        'employment_start' => $user->employment_start,
                    ],
                ]);

                // Dispatch to manager(s) of the employee's team
                $managers = $this->getManagersForUser($user);
                foreach ($managers as $manager) {
                    $managerIdempotencyKey = 'anniversary-'.$today->toDateString().'-manager-'.$manager->id.'-employee-'.$user->id;
                    $this->emailOutboxService->dispatch([
                        'idempotency_key' => $managerIdempotencyKey,
                        'organization_id' => (int) $user->organization_id,
                        'user_id' => (int) $manager->id,
                        'recipient' => (string) $manager->email,
                        'subject' => $rendered['subject'],
                        'body_text' => $rendered['body_text'],
                        'body_html' => $rendered['body_html'],
                        'type' => 'anniversary',
                    ]);
                    $dispatched++;

                    $this->auditService->record([
                        'organization_id' => (int) $user->organization_id,
                        'actor_id' => null,
                        'action' => 'ANNIVERSARY_DISPATCHED',
                        'target_type' => 'user',
                        'target_id' => (string) $user->id,
                        'after_data' => [
                            'years' => $years,
                            'recipient_type' => 'manager',
                            'manager_id' => (int) $manager->id,
                            'employment_start' => $user->employment_start,
                        ],
                    ]);
                }

                $matchDetails[] = [
                    'user_id' => (int) $user->id,
                    'years' => $years,
                    'managers_notified' => $managers->count(),
                ];
            }

            $finishedAt = CarbonImmutable::now();
            $jobRun->update([
                'status' => 'completed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'rows_affected' => $dispatched,
                'details' => [
                    'date' => $today->toDateString(),
                    'dispatched' => $dispatched,
                    'matches' => $matchDetails,
                ],
            ]);

            return [
                'job_run_id' => (int) $jobRun->id,
                'dispatched' => $dispatched,
                'matches' => $matchDetails,
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
     * Find all active users whose employment_start matches today's month/day
     * and whose year difference is a milestone year.
     *
     * @return Collection<int, User>
     */
    private function findMatchingUsers(Carbon $today): Collection
    {
        $month = $today->month;
        $day = $today->day;
        $currentYear = $today->year;

        // Calculate the employment_start years that would produce milestone anniversaries
        $targetYears = array_map(
            fn (int $milestone) => $currentYear - $milestone,
            self::MILESTONE_YEARS,
        );

        return User::query()
            ->where('is_active', true)
            ->whereNotNull('employment_start')
            ->whereMonth('employment_start', $month)
            ->whereDay('employment_start', $day)
            ->where(function ($query) use ($targetYears) {
                foreach ($targetYears as $year) {
                    $query->orWhereYear('employment_start', $year);
                }
            })
            ->get();
    }

    /**
     * Get managers for a user's team within the same organization.
     *
     * @return Collection<int, User>
     */
    private function getManagersForUser(User $user): Collection
    {
        if ($user->team_id === null) {
            // If user has no team, notify owners of the organization
            return User::query()
                ->where('organization_id', $user->organization_id)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->get();
        }

        return User::query()
            ->where('organization_id', $user->organization_id)
            ->where('team_id', $user->team_id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->get();
    }
}
