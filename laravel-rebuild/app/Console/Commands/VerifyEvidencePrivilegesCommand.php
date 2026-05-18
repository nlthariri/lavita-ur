<?php

namespace App\Console\Commands;

use App\Services\EvidencePrivilegeVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class VerifyEvidencePrivilegesCommand extends Command
{
    protected $signature = 'integrity:evidence-privileges:verify {--fail-on-violation}';

    protected $description = 'Verifieer runtime DB-privileges op append-only evidence-tabellen.';

    public function handle(EvidencePrivilegeVerificationService $service): int
    {
        $lock = Cache::lock('integrity:evidence-privileges:verify:any', 1800);
        if (! $lock->get()) {
            $this->error('Een privilege-verificatie run is al actief.');

            return self::FAILURE;
        }

        try {
            $result = $service->run();
        } finally {
            $lock->release();
        }

        $summary = $result['summary'];

        $this->info('Evidence privilege verificatie voltooid.');
        $this->line('Job run ID: '.$result['job_run_id']);
        $this->line('Driver: '.$summary['driver']);
        $this->line('Status: '.$summary['status']);
        $this->line('Violations: '.$summary['violations_count']);

        if ($summary['status'] === 'failed' && (bool) $this->option('fail-on-violation')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
