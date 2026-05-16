<?php

namespace App\Services;

use App\Models\SystemJobRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EvidencePrivilegeVerificationService
{
    /** @var array<int, string> */
    private const EVIDENCE_TABLES = [
        'email_outbox_events',
        'monthly_report_runs',
        'system_job_runs',
    ];

    public function run(): array
    {
        $startedAt = CarbonImmutable::now();
        $driver = DB::getDriverName();

        $jobRun = SystemJobRun::create([
            'job_name' => 'integrity.evidence_privilege_check',
            'status' => 'started',
            'started_at' => $startedAt,
            'details' => [
                'driver' => $driver,
                'tables' => self::EVIDENCE_TABLES,
            ],
        ]);

        try {
            $summary = match ($driver) {
                'pgsql' => $this->verifyPostgresPrivileges(),
                'mysql', 'mariadb' => $this->verifyMysqlPrivileges(),
                default => $this->notApplicableSummary($driver),
            };

            $finishedAt = CarbonImmutable::now();
            $jobRun->update([
                'status' => $summary['status'] === 'failed' ? 'failed' : 'completed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'rows_affected' => $summary['violations_count'],
                'details' => $summary,
                'error_message' => $summary['status'] === 'failed'
                    ? 'Privilege verificatie heeft violations gedetecteerd.'
                    : null,
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

    private function verifyPostgresPrivileges(): array
    {
        $violations = [];
        $tableResults = [];

        foreach (self::EVIDENCE_TABLES as $table) {
            $row = DB::selectOne(
                'SELECT has_table_privilege(current_user, ?, ? ) AS can_select,
                        has_table_privilege(current_user, ?, ? ) AS can_insert,
                        has_table_privilege(current_user, ?, ? ) AS can_update,
                        has_table_privilege(current_user, ?, ? ) AS can_delete,
                        has_table_privilege(current_user, ?, ? ) AS can_truncate',
                [$table, 'SELECT', $table, 'INSERT', $table, 'UPDATE', $table, 'DELETE', $table, 'TRUNCATE']
            );

            $tableResult = [
                'table' => $table,
                'can_select' => (bool) ($row->can_select ?? false),
                'can_insert' => (bool) ($row->can_insert ?? false),
                'can_update' => (bool) ($row->can_update ?? false),
                'can_delete' => (bool) ($row->can_delete ?? false),
                'can_truncate' => (bool) ($row->can_truncate ?? false),
            ];

            $tableResults[] = $tableResult;

            if (!$tableResult['can_select'] || !$tableResult['can_insert'] || $tableResult['can_update'] || $tableResult['can_delete'] || $tableResult['can_truncate']) {
                $violations[] = [
                    'table' => $table,
                    'expected' => 'SELECT/INSERT only',
                    'actual' => $tableResult,
                ];
            }
        }

        return [
            'check' => 'evidence_runtime_privileges',
            'driver' => 'pgsql',
            'status' => count($violations) > 0 ? 'failed' : 'passed',
            'tables_checked' => count(self::EVIDENCE_TABLES),
            'violations_count' => count($violations),
            'violations' => $violations,
            'table_results' => $tableResults,
        ];
    }

    private function verifyMysqlPrivileges(): array
    {
        $schema = (string) DB::getDatabaseName();
        $rawRows = DB::table('information_schema.table_privileges')
            ->select('table_name', 'privilege_type')
            ->where('table_schema', $schema)
            ->whereIn('table_name', self::EVIDENCE_TABLES)
            ->whereRaw("GRANTEE = CONCAT(\"'\", SUBSTRING_INDEX(CURRENT_USER(), '@', 1), \"'@'\", SUBSTRING_INDEX(CURRENT_USER(), '@', -1), \"'\")")
            ->get();

        $privileges = [];
        foreach (self::EVIDENCE_TABLES as $table) {
            $privileges[$table] = [];
        }

        foreach ($rawRows as $row) {
            $privileges[(string) $row->table_name][] = strtoupper((string) $row->privilege_type);
        }

        $violations = [];
        $tableResults = [];

        foreach (self::EVIDENCE_TABLES as $table) {
            $grants = $privileges[$table] ?? [];
            $hasSelect = in_array('SELECT', $grants, true);
            $hasInsert = in_array('INSERT', $grants, true);
            $hasUpdate = in_array('UPDATE', $grants, true);
            $hasDelete = in_array('DELETE', $grants, true);
            $hasTrigger = in_array('TRIGGER', $grants, true);
            $hasAlter = in_array('ALTER', $grants, true);
            $hasDrop = in_array('DROP', $grants, true);

            $tableResult = [
                'table' => $table,
                'grants' => $grants,
                'can_select' => $hasSelect,
                'can_insert' => $hasInsert,
                'has_update' => $hasUpdate,
                'has_delete' => $hasDelete,
                'has_trigger' => $hasTrigger,
                'has_alter' => $hasAlter,
                'has_drop' => $hasDrop,
            ];

            $tableResults[] = $tableResult;

            if (!$hasSelect || !$hasInsert || $hasUpdate || $hasDelete || $hasTrigger || $hasAlter || $hasDrop) {
                $violations[] = [
                    'table' => $table,
                    'expected' => 'SELECT/INSERT only, no UPDATE/DELETE/TRIGGER/ALTER/DROP',
                    'actual' => $tableResult,
                ];
            }
        }

        return [
            'check' => 'evidence_runtime_privileges',
            'driver' => DB::getDriverName(),
            'status' => count($violations) > 0 ? 'failed' : 'passed',
            'tables_checked' => count(self::EVIDENCE_TABLES),
            'violations_count' => count($violations),
            'violations' => $violations,
            'table_results' => $tableResults,
        ];
    }

    private function notApplicableSummary(string $driver): array
    {
        return [
            'check' => 'evidence_runtime_privileges',
            'driver' => $driver,
            'status' => 'not_applicable',
            'tables_checked' => count(self::EVIDENCE_TABLES),
            'violations_count' => 0,
            'violations' => [],
            'reason' => 'Driver ondersteunt geen runtime GRANT-introspectie in deze omgeving.',
        ];
    }
}
