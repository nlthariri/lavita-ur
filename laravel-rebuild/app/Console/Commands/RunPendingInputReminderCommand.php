<?php

namespace App\Console\Commands;

use App\Services\PendingInputReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunPendingInputReminderCommand extends Command
{
    protected $signature = 'reminder:pending-input {--org-id=} {--days=1} {--dry-run}';

    protected $description = 'Stuur reminders voor openstaande ureninvoer naar managers.';

    public function handle(PendingInputReminderService $service): int
    {
        $organizationId = $this->option('org-id') ? (int) $this->option('org-id') : null;
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        $lock = Cache::lock('reminder:pending-input:any', 1800);
        if (!$lock->get()) {
            $this->error('Een reminder-run is al actief.');

            return self::FAILURE;
        }

        try {
            $result = $service->run($organizationId, $days, $dryRun);
        } finally {
            $lock->release();
        }

        $this->info('Reminder run voltooid.');
        $this->line('Job run ID: '.$result['job_run_id']);
        $this->line('Target date: '.$result['target_date']);
        $this->line('Dispatches: '.$result['dispatched']);

        return self::SUCCESS;
    }
}
