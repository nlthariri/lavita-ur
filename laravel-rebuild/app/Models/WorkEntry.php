<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkEntry extends Model
{
    protected $fillable = [
        'organization_id',
        'employee_id',
        'team_id',
        'registered_by_id',
        'entry_date',
        'start_at',
        'end_at',
        'pause_minutes',
        'net_minutes',
        'type',
        'note',
        'project_id',
        'cost_center_id',
        'is_finalized',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_finalized' => 'boolean',
        'pause_minutes' => 'integer',
        'net_minutes' => 'integer',
        'project_id' => 'integer',
        'cost_center_id' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
