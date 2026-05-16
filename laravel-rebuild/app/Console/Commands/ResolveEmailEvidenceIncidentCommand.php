<?php

namespace App\Console\Commands;

use App\Services\EmailEvidenceIntegrityAuditService;
use Illuminate\Console\Command;

class ResolveEmailEvidenceIncidentCommand extends Command
{
    protected $signature = 'integrity:email-evidence:incident:resolve {incident-id} {--note=}';

    protected $description = 'Markeer een integrity incident als resolved.';

    public function handle(EmailEvidenceIntegrityAuditService $service): int
    {
        $incidentId = (string) $this->argument('incident-id');
        $note = (string) ($this->option('note') ?? '');

        if (!$service->resolveIncident($incidentId, $note)) {
            $this->error('Incident niet gevonden: '.$incidentId);

            return self::FAILURE;
        }

        $this->info('Incident resolved: '.$incidentId);

        return self::SUCCESS;
    }
}
