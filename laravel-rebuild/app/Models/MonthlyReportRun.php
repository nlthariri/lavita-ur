<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyReportRun extends Model
{
    protected $table = 'monthly_report_runs';

    protected $fillable = [
        'organization_id',
        'period_month',
        'requested_by_actor_id',
        'request_id',
        'source_ip',
        'user_agent',
        'correlation_id',
        'dedupe_key',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('MonthlyReportRun is append-only en mag niet geupdate worden.');
        });

        static::deleting(function (): void {
            throw new \LogicException('MonthlyReportRun is append-only en mag niet verwijderd worden.');
        });
    }
}
