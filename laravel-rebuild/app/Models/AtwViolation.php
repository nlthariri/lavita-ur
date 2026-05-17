<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtwViolation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'user_id',
        'work_entry_id',
        'violation_type',
        'severity',
        'period_start',
        'period_end',
        'current_minutes',
        'threshold_minutes',
        'details',
        'superseded_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'current_minutes' => 'integer',
        'threshold_minutes' => 'integer',
        'superseded_at' => 'datetime',
    ];

    protected $table = 'atw_violations';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workEntry(): BelongsTo
    {
        return $this->belongsTo(WorkEntry::class);
    }
}
