<?php

namespace App\Console\Commands;

use App\Services\EmailEvidenceIntegrityAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunEmailEvidenceIntegrityCommand extends Command
{
    protected $signature = 'integrity:email-evidence {--org-id=} {--outbox-id=} {--fail-on-corruption}';

    protected $description = 'Voer periodieke integrity-audit uit op e-mail evidence hash-chains.';

    public function handle(EmailEvidenceIntegrityAuditService $auditService): int
    {
        $organizationId = $this->option('org-id') ? (int) $this->option('org-id') : null;
        $outboxId = $this->option('outbox-id') ? (int) $this->option('outbox-id') : null;
        $failOnCorruption = (bool) $this->option('fail-on-corruption');

        $integrityLock = Cache::lock('integrity:email-evidence:any', 3600);
        if (!$integrityLock->get()) {
            $this->error('Een integrity-audit is al actief voor deze scope.');

            return self::FAILURE;
        }

        $retentionLock = Cache::lock('retention:run:any', 3600);
        if (!$retentionLock->get()) {
            $integrityLock->release();
            $this->error('Retention-run is actief; integrity-audit is geblokkeerd om race conditions te voorkomen.');

            return self::FAILURE;
        }

        $retentionLock->release();

        try {
            $result = $auditService->run($organizationId, $outboxId, $failOnCorruption);
        } finally {
            $integrityLock->release();
        }

        $summary = $result['summary'];
        $this->info('Integrity-audit voltooid.');
        $this->line('Job run ID: '.$result['job_run_id']);
        $this->line('Scanned outbox records: '.$summary['scanned']);
        $this->line('Tampered outbox records: '.count($summary['tampered_outbox_ids']));

        if (!empty($summary['tamper_detected'])) {
            $this->warn('Tampering detected: escalatie vereist.');
        }

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
