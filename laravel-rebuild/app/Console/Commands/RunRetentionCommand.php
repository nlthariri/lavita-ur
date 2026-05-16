<?php

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunRetentionCommand extends Command
{
    protected $signature = 'retention:run {--org-id=} {--dry-run}';

    protected $description = 'Voer GDPR retention cleanup en pseudonimisering uit.';

    public function handle(RetentionService $retentionService): int
    {
        $organizationId = $this->option('org-id') ? (int) $this->option('org-id') : null;
        $lock = Cache::lock('retention:run:any', 3600);

        if (!$lock->get()) {
            $this->error('Een retention-run is al actief voor deze scope.');

            return self::FAILURE;
        }

        try {
            $result = $retentionService->run(
                $organizationId,
                (bool) $this->option('dry-run'),
            );
        } finally {
            $lock->release();
        }

        $summary = $result['summary'];
        $this->info('Retention run voltooid.');
        $this->line('Job run ID: '.$result['job_run_id']);
        $this->line('Email outbox affected: '.$summary['email_outbox']['affected']);
        $this->line('Audit events affected: '.$summary['audit_events']['affected']);
        $this->line('Pseudonymization affected: '.$summary['organizations']['affected']);

        return self::SUCCESS;
    }
}