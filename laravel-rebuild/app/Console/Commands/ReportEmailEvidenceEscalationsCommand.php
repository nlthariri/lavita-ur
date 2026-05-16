<?php

namespace App\Console\Commands;

use App\Services\EmailEvidenceIntegrityAuditService;
use Illuminate\Console\Command;

class ReportEmailEvidenceEscalationsCommand extends Command
{
    protected $signature = 'integrity:email-evidence:escalations:report {--org-id=} {--fail-on-open}';

    protected $description = 'Rapporteer openstaande integrity-escalatie incidenten.';

    public function handle(EmailEvidenceIntegrityAuditService $service): int
    {
        $organizationId = $this->option('org-id') ? (int) $this->option('org-id') : null;

        $summary = $service->summarizeOpenEscalations($organizationId);

        $this->info('Integrity escalation rapportage voltooid.');
        $this->line('Open escalations: '.$summary['open_count']);

        if ($summary['open_count'] > 0) {
            foreach ($summary['open_escalations'] as $item) {
                $this->warn(
                    'Open incident: job_run_id='.$item['job_run_id']
                    .', incident_id='.(string) ($item['incident_id'] ?? 'n/a')
                    .', status='.$item['status']
                    .', attempts='.(int) $item['attempts']
                );
            }
        }

        if ($summary['open_count'] > 0 && (bool) $this->option('fail-on-open')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
