<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemJobRun extends Model
{
    protected $table = 'system_job_runs';

    protected $fillable = [
        'organization_id',
        'job_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'rows_affected',
        'details',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'rows_affected' => 'integer',
        'duration_ms' => 'integer',
        'details' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}