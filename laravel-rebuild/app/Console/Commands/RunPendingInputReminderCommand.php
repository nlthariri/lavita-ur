<?php

namespace App\Console\Commands;

use App\Services\PendingInputReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunPendingInputReminderCommand extends Command
{
    protected $signature = 'reminder:pending-input {--org-id=} {--threshold=} {--dry-run}';

    protected $description = 'Stuur reminders voor openstaande ureninvoer naar managers (threshold uit organizations.pending_input_threshold_days).';

    public function handle(PendingInputReminderService $service): int
    {
        $organizationId = $this->option('org-id') ? (int) $this->option('org-id') : null;
        $thresholdOverride = $this->option('threshold') ? (int) $this->option('threshold') : null;
        $dryRun = (bool) $this->option('dry-run');

        $lock = Cache::lock('reminder:pending-input:any', 1800);
        if (! $lock->get()) {
            $this->error('Een reminder-run is al actief.');

            return self::FAILURE;
        }

        try {
            $result = $service->run($organizationId, $thresholdOverride, $dryRun);
        } finally {
            $lock->release();
        }

        $this->info('Reminder run voltooid.');
        $this->line('Job run ID: '.$result['job_run_id']);
        $this->line('Dispatches: '.$result['dispatched']);

        return self::SUCCESS;
    }
}
