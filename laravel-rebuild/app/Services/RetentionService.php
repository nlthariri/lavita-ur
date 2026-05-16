<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\EmailOutbox;
use App\Models\MfaSecret;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\SystemJobRun;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RetentionService
{
    private const EMAIL_BODY_RETENTION_DAYS = 30;
    private const AUDIT_IP_RETENTION_DAYS = 90;
    private const BATCH_SIZE = 100;
    private const OBJECTION_PLACEHOLDER = '[gepseudonimiseerd conform retentiebeleid]';

    public function run(?int $organizationId = null, bool $dryRun = false): array
    {
        $startedAt = CarbonImmutable::now();
        $jobRun = SystemJobRun::create([
            'organization_id' => $organizationId,
            'job_name' => 'retention.run',
            'status' => 'started',
            'started_at' => $startedAt,
            'details' => [
                'dry_run' => $dryRun,
                'phases' => [],
            ],
        ]);

        try {
            $summary = [
                'dry_run' => $dryRun,
                'email_outbox' => $this->scrubEmailOutbox($organizationId, $dryRun),
                'audit_events' => $this->scrubAuditEvents($organizationId, $dryRun),
                'organizations' => $this->pseudonymizeOrganizations($organizationId, $dryRun),
            ];

            $rowsAffected = $summary['email_outbox']['affected']
                + $summary['audit_events']['affected']
                + $summary['organizations']['affected'];

            $finishedAt = CarbonImmutable::now();
            $jobRun->update([
                'status' => 'completed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'rows_affected' => $rowsAffected,
                'details' => $summary,
            ]);

            return [
                'job_run_id' => $jobRun->id,
                'summary' => $summary,
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

    private function scrubEmailOutbox(?int $organizationId, bool $dryRun): array
    {
        $cutoff = CarbonImmutable::now()->subDays(self::EMAIL_BODY_RETENTION_DAYS);
        $query = EmailOutbox::query()
            ->where('status', 'sent')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', $cutoff)
            ->when($organizationId !== null, fn ($builder) => $builder->where('organization_id', $organizationId))
            ->whereNull('scrubbed_at');

        $eligible = (clone $query)->count();

        if ($dryRun || $eligible === 0) {
            return [
                'eligible' => $eligible,
                'affected' => 0,
                'cutoff' => $cutoff->toIso8601String(),
            ];
        }

        $affected = 0;
        $query->chunkById(self::BATCH_SIZE, function ($items) use (&$affected): void {
            $ids = $items->pluck('id')->all();
            $affected += EmailOutbox::whereIn('id', $ids)->update([
                'body_text' => '',
                'body_html' => '',
                'attachments' => null,
                'error_message' => null,
                'scrubbed_at' => now(),
            ]);
        });

        return [
            'eligible' => $eligible,
            'affected' => $affected,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    private function scrubAuditEvents(?int $organizationId, bool $dryRun): array
    {
        $cutoff = CarbonImmutable::now()->subDays(self::AUDIT_IP_RETENTION_DAYS);
        $query = AuditEvent::query()
            ->whereNotNull('ip_address')
            ->where('created_at', '<', $cutoff)
            ->when($organizationId !== null, fn ($builder) => $builder->where('organization_id', $organizationId))
            ->whereNull('scrubbed_at');

        $eligible = (clone $query)->count();

        if ($dryRun || $eligible === 0) {
            return [
                'eligible' => $eligible,
                'affected' => 0,
                'cutoff' => $cutoff->toIso8601String(),
            ];
        }

        $affected = 0;
        $query->chunkById(self::BATCH_SIZE, function ($items) use (&$affected): void {
            $ids = $items->pluck('id')->all();
            $affected += AuditEvent::whereIn('id', $ids)->update([
                'ip_address' => null,
                'scrubbed_at' => now(),
            ]);
        });

        return [
            'eligible' => $eligible,
            'affected' => $affected,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    private function pseudonymizeOrganizations(?int $organizationId, bool $dryRun): array
    {
        $totals = [
            'eligible_users' => 0,
            'affected_users' => 0,
            'affected_work_entries' => 0,
            'affected_objections' => 0,
            'affected_sessions' => 0,
            'affected_mfa_secrets' => 0,
            'affected_password_reset_tokens' => 0,
            'affected' => 0,
            'organizations' => [],
        ];

        Organization::query()
            ->when($organizationId !== null, fn ($query) => $query->where('id', $organizationId))
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($organizations) use (&$totals, $dryRun): void {
                foreach ($organizations as $organization) {
                    $orgSummary = $this->pseudonymizeOrganization($organization, $dryRun);
                    $totals['eligible_users'] += $orgSummary['eligible_users'];
                    $totals['affected_users'] += $orgSummary['affected_users'];
                    $totals['affected_work_entries'] += $orgSummary['affected_work_entries'];
                    $totals['affected_objections'] += $orgSummary['affected_objections'];
                    $totals['affected_sessions'] += $orgSummary['affected_sessions'];
                    $totals['affected_mfa_secrets'] += $orgSummary['affected_mfa_secrets'];
                    $totals['affected_password_reset_tokens'] += $orgSummary['affected_password_reset_tokens'];
                    $totals['affected'] += $orgSummary['affected'];
                    $totals['organizations'][] = $orgSummary;
                }
            });

        return $totals;
    }

    private function pseudonymizeOrganization(Organization $organization, bool $dryRun): array
    {
        $cutoff = CarbonImmutable::now()->subYears($organization->retention_years ?: 7)->toDateString();

        $userQuery = User::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', false)
            ->where(function ($query) use ($cutoff): void {
                $query->whereDate('employment_end', '<', $cutoff)
                    ->orWhere(function ($fallbackQuery) use ($cutoff): void {
                        $fallbackQuery->whereNull('employment_end')
                            ->whereDate('updated_at', '<', $cutoff);
                    });
            })
            ->where('email', 'not like', 'deleted+%@anonymized.local');

        $workEntryQuery = WorkEntry::query()
            ->where('organization_id', $organization->id)
            ->whereDate('entry_date', '<', $cutoff)
            ->whereNotNull('note');

        $objectionQuery = Objection::query()
            ->where('organization_id', $organization->id)
            ->whereDate('submitted_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('motivation')
                    ->orWhereNotNull('manager_response');
            });

        $eligibleUsers = (clone $userQuery)->count();
        $eligibleWorkEntries = (clone $workEntryQuery)->count();
        $eligibleObjections = (clone $objectionQuery)->count();

        if ($dryRun) {
            return [
                'organization_id' => $organization->id,
                'retention_years' => $organization->retention_years,
                'cutoff' => $cutoff,
                'eligible_users' => $eligibleUsers,
                'eligible_work_entries' => $eligibleWorkEntries,
                'eligible_objections' => $eligibleObjections,
                'affected_users' => 0,
                'affected_work_entries' => 0,
                'affected_objections' => 0,
                'affected_sessions' => 0,
                'affected_mfa_secrets' => 0,
                'affected_password_reset_tokens' => 0,
                'affected' => 0,
            ];
        }

        $affectedUsers = 0;
        $affectedSessions = 0;
        $affectedMfaSecrets = 0;
        $affectedPasswordResetTokens = 0;

        (clone $userQuery)
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($users) use (&$affectedUsers, &$affectedSessions, &$affectedMfaSecrets, &$affectedPasswordResetTokens): void {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, &$affectedUsers, &$affectedSessions, &$affectedMfaSecrets, &$affectedPasswordResetTokens): void {
                        $oldEmail = $user->email;
                        $alias = substr(hash_hmac('sha256', 'retention:user:'.$user->id, (string) config('app.key')), 0, 32);

                        $user->forceFill([
                            'name' => 'Gepseudonimiseerd',
                            'full_name' => 'Gepseudonimiseerd',
                            'email' => 'deleted+'.$alias.'@anonymized.local',
                            'password' => Hash::make(Str::random(48)),
                            'remember_token' => null,
                            'updated_at' => now(),
                        ])->save();

                        $affectedUsers++;
                        $affectedSessions += AuthSession::where('user_id', $user->id)
                            ->whereNull('revoked_at')
                            ->update(['revoked_at' => now()]);

                        if (Schema::hasTable('sessions')) {
                            DB::table('sessions')->where('user_id', $user->id)->delete();
                        }

                        $affectedMfaSecrets += MfaSecret::where('user_id', $user->id)->update([
                            'secret_encrypted' => hash('sha256', Str::random(64)),
                            'label' => 'gepseudonimiseerd',
                            'disabled_at' => now(),
                            'rotated_at' => now(),
                        ]);

                        $affectedPasswordResetTokens += DB::table('password_reset_tokens')
                            ->where('email', $oldEmail)
                            ->delete();
                    });
                }
            });

        $affectedWorkEntries = 0;
        $workEntryQuery->chunkById(self::BATCH_SIZE, function ($items) use (&$affectedWorkEntries): void {
            $ids = $items->pluck('id')->all();
            $affectedWorkEntries += WorkEntry::whereIn('id', $ids)->update([
                'note' => null,
            ]);
        });

        $affectedObjections = 0;
        $objectionQuery->chunkById(self::BATCH_SIZE, function ($items) use (&$affectedObjections): void {
            foreach ($items as $item) {
                $affectedObjections += Objection::where('id', $item->id)->update([
                    'motivation' => $item->motivation !== null ? self::OBJECTION_PLACEHOLDER : null,
                    'manager_response' => $item->manager_response !== null ? self::OBJECTION_PLACEHOLDER : null,
                ]);
            }
        });

        $affected = $affectedUsers + $affectedSessions + $affectedMfaSecrets + $affectedPasswordResetTokens + $affectedWorkEntries + $affectedObjections;

        Log::info('Retention organization processed', [
            'organization_id' => $organization->id,
            'retention_years' => $organization->retention_years,
            'affected' => $affected,
        ]);

        return [
            'organization_id' => $organization->id,
            'retention_years' => $organization->retention_years,
            'cutoff' => $cutoff,
            'eligible_users' => $eligibleUsers,
            'eligible_work_entries' => $eligibleWorkEntries,
            'eligible_objections' => $eligibleObjections,
            'affected_users' => $affectedUsers,
            'affected_work_entries' => $affectedWorkEntries,
            'affected_objections' => $affectedObjections,
            'affected_sessions' => $affectedSessions,
            'affected_mfa_secrets' => $affectedMfaSecrets,
            'affected_password_reset_tokens' => $affectedPasswordResetTokens,
            'affected' => $affected,
        ];
    }
}