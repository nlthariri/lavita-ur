<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class RunRetentionCommand extends Command
{
    protected $signature = 'retention:run {--org-id=} {--dry-run} {--phase=all : Phase to run: daily (scrub only), deep (pseudonymization only), or all}';

    protected $description = 'Voer GDPR retention cleanup en pseudonimisering uit.';

    public function handle(RetentionService $retentionService): int
    {
        $organizationId = $this->option('org-id') ? (int) $this->option('org-id') : null;
        $phase = (string) ($this->option('phase') ?: 'all');
        $lock = Cache::lock('retention:run:any', 3600);

        if (! $lock->get()) {
            $this->error('Een retention-run is al actief voor deze scope.');

            return self::FAILURE;
        }

        try {
            if ($phase === 'daily' || $phase === 'all') {
                $result = $retentionService->run(
                    $organizationId,
                    (bool) $this->option('dry-run'),
                );

                $summary = $result['summary'];
                $this->info('Retention run voltooid (phase: '.$phase.').');
                $this->line('Job run ID: '.$result['job_run_id']);
                $this->line('Email outbox affected: '.$summary['email_outbox']['affected']);
                $this->line('Audit events affected: '.$summary['audit_events']['affected']);
                $this->line('Pseudonymization affected: '.$summary['organizations']['affected']);
            }

            // Diepe pseudonimisering: alleen bij phase=deep of phase=all
            if ($phase === 'deep' || $phase === 'all') {
                if ((bool) $this->option('dry-run')) {
                    $this->info('Deep pseudonymization: dry-run modus — geen wijzigingen uitgevoerd.');
                } else {
                    $this->runMonthlyDeepPseudonymization();
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * Voor users met deleted_at < now()-7y: wis employment_start/end.
     * Voor audit_events.created_at < now()-7y: set actor_id = null.
     */
    private function runMonthlyDeepPseudonymization(): void
    {
        $cutoff = CarbonImmutable::now()->subYears(7);

        // Wis employment_start/end voor gepseudonimiseerde accounts ouder dan 7 jaar
        if (Schema::hasColumn('users', 'deleted_at')) {
            $affectedUsers = User::whereNotNull('deleted_at')
                ->where('deleted_at', '<', $cutoff)
                ->where(function ($query) {
                    $query->whereNotNull('employment_start')
                        ->orWhereNotNull('employment_end');
                })
                ->update([
                    'employment_start' => null,
                    'employment_end' => null,
                ]);

            if ($affectedUsers > 0) {
                $this->line("Deep pseudonymization: {$affectedUsers} users employment data cleared.");
            }
        }

        // Nul actor_id op audit_events ouder dan 7 jaar
        $affectedAuditEvents = AuditEvent::where('created_at', '<', $cutoff)
            ->whereNotNull('actor_id')
            ->update(['actor_id' => null]);

        if ($affectedAuditEvents > 0) {
            $this->line("Deep pseudonymization: {$affectedAuditEvents} audit_events actor_id nulled.");
        }
    }
}
