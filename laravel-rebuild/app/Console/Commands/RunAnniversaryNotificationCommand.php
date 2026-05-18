<?php

namespace App\Console\Commands;

use App\Services\AnniversaryNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunAnniversaryNotificationCommand extends Command
{
    protected $signature = 'notifications:anniversary {--date= : Override date (Y-m-d) for testing}';

    protected $description = 'Verstuur jubileumnotificaties voor medewerkers met 1, 5, 10 of 25 jaar dienstverband.';

    public function handle(AnniversaryNotificationService $service): int
    {
        $lock = Cache::lock('notifications:anniversary:run', 1800);
        if (! $lock->get()) {
            $this->error('Een jubileum-notificatie-run is al actief.');

            return self::FAILURE;
        }

        try {
            $dateOption = $this->option('date');
            $today = $dateOption
                ? Carbon::parse($dateOption)->startOfDay()
                : Carbon::now('Europe/Amsterdam')->startOfDay();

            $result = $service->dispatchForDate($today);
        } finally {
            $lock->release();
        }

        $this->info('Jubileum-notificatie run voltooid.');
        $this->line('Job run ID: '.$result['job_run_id']);
        $this->line('Dispatches: '.$result['dispatched']);
        $this->line('Matches: '.count($result['matches']));

        if (! empty($result['matches'])) {
            $this->table(
                ['User ID', 'Jaren', 'Managers genotificeerd'],
                array_map(fn ($m) => [$m['user_id'], $m['years'], $m['managers_notified']], $result['matches']),
            );
        }

        return self::SUCCESS;
    }
}
