<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Verlof-type model.
 *
 * Elke organisatie kan eigen verlof-types configureren (vakantie, bijzonder verlof, etc.).
 * Het veld `counts_towards_balance` bepaalt of opgenomen dagen van dit type
 * worden afgetrokken van het jaarlijks verlofrecht.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $max_days_per_year
 * @property bool $counts_towards_balance
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class LeaveType extends Model
{
    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'max_days_per_year',
        'counts_towards_balance',
        'is_active',
    ];

    protected $casts = [
        'max_days_per_year' => 'integer',
        'counts_towards_balance' => 'boolean',
        'is_active' => 'boolean',
    ];

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workEntries(): HasMany
    {
        return $this->hasMany(WorkEntry::class);
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    /**
     * Scope: alleen actieve verlof-types.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: alleen types die meetellen voor het verlof-saldo.
     */
    public function scopeCountsTowardsBalance(Builder $query): Builder
    {
        return $query->where('counts_towards_balance', true);
    }

    /**
     * Scope: filter op organisatie.
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
