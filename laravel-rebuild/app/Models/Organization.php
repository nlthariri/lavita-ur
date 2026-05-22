<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'kvk_number',
        'loonheffingennummer',
        'default_timezone',
        'retention_years',
        'pending_input_threshold_days',
        'atw_daily_max_minutes',
        'atw_weekly_max_minutes',
        'atw_weekly_warning_minutes',
        'atw_average_16_week_minutes',
    ];

    protected $casts = [
        'retention_years' => 'integer',
        'pending_input_threshold_days' => 'integer',
        'atw_daily_max_minutes' => 'integer',
        'atw_weekly_max_minutes' => 'integer',
        'atw_weekly_warning_minutes' => 'integer',
        'atw_average_16_week_minutes' => 'integer',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function workEntries(): HasMany
    {
        return $this->hasMany(WorkEntry::class);
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class);
    }
}
