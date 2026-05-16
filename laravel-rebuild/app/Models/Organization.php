<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'kvk_number',
        'loonheffingennummer',
        'default_timezone',
        'retention_years',
        'atw_daily_max_minutes',
        'atw_weekly_max_minutes',
        'atw_weekly_warning_minutes',
        'atw_average_16_week_minutes',
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
}
