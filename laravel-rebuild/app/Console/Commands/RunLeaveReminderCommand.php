<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LeaveReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Artisan-commando — `reminder:leave-pending`
 *
 * Stuurt herinneringsmails naar managers voor verlofaanvragen die langer
 * dan 3 werkdagen onbehandeld zijn.
 *
 * Respecteert opt-out: herinneringen worden alleen verstuurd als de
 * medewerker `email_reminders_opt_in = true` heeft.
 *
 * Requirements: 13.5, 13.7
 */
class RunLeaveReminderCommand extends Command
{
    protected $signature = 'reminder:leave-pending {--org-id=} {--threshold=} {--dry-run}';

    protected $description = 'Stuur herinneringen voor onbehandelde verlofaanvragen (>3 werkdagen) naar managers.';

    public function handle(LeaveReminderService $service): int
    {
        $organizationId = $this->option('org-id') ? (int) $this->option('org-id') : null;
        $thresholdOverride = $this->option('threshold') ? (int) $this->option('threshold') : null;
        $dryRun = (bool) $this->option('dry-run');

        $lock = Cache::lock('reminder:leave-pending:any', 1800);
        if (! $lock->get()) {
            $this->error('Een leave-reminder-run is al actief.');

            return self::FAILURE;
        }

        try {
            $result = $service->run($organizationId, $thresholdOverride, $dryRun);
        } finally {
            $lock->release();
        }

        $this->info('Leave reminder run voltooid.');
        $this->line('Job run ID: ' . $result['job_run_id']);
        $this->line('Dispatches: ' . $result['dispatched']);

        if ($dryRun) {
            $this->warn('Dry-run modus — geen mails verstuurd.');
        }

        return self::SUCCESS;
    }
}
