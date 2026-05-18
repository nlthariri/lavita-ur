<?php

namespace App\Console\Commands;

use App\Services\EmailEvidenceIntegrityAuditService;
use Illuminate\Console\Command;

class AcknowledgeEmailEvidenceIncidentCommand extends Command
{
    protected $signature = 'integrity:email-evidence:incident:ack {incident-id} {--note=}';

    protected $description = 'Markeer een integrity incident als acknowledged.';

    public function handle(EmailEvidenceIntegrityAuditService $service): int
    {
        $incidentId = (string) $this->argument('incident-id');
        $note = (string) ($this->option('note') ?? '');

        if (! $service->acknowledgeIncident($incidentId, $note)) {
            $this->error('Incident niet gevonden: '.$incidentId);

            return self::FAILURE;
        }

        $this->info('Incident acknowledged: '.$incidentId);

        return self::SUCCESS;
    }
}
