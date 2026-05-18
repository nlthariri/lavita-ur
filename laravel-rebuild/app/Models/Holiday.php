<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = [
        'year',
        'date',
        'name',
        'is_national',
    ];

    protected $casts = [
        'year' => 'integer',
        'date' => 'date',
        'is_national' => 'boolean',
    ];

    /**
     * Scope: filter op een specifiek jaar.
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }
}
