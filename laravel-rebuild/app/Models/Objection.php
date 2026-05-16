<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Objection extends Model
{
    protected $fillable = [
        'organization_id',
        'work_entry_id',
        'submitted_by_id',
        'reviewed_by_id',
        'motivation',
        'manager_response',
        'corrected_start_at',
        'corrected_end_at',
        'corrected_pause_minutes',
        'work_entry_before',
        'work_entry_after',
        'status',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'corrected_start_at' => 'datetime',
        'corrected_end_at' => 'datetime',
        'corrected_pause_minutes' => 'integer',
        'work_entry_before' => 'array',
        'work_entry_after' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workEntry(): BelongsTo
    {
        return $this->belongsTo(WorkEntry::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
