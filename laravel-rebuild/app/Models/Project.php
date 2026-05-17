<?php

namespace App\Models;

use App\Models\Concerns\HasArchivedAt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent-model voor de tabel `projects`.
 *
 * Hoort bij één `Organization` en kan gekoppeld zijn aan veel `WorkEntry`-rijen
 * via `work_entries.project_id`. Soft-delete-gedrag wordt geleverd door de
 * `HasArchivedAt`-trait die werkt op de kolom `archived_at` (zie design.md
 * Data Models). De Laravel `SoftDeletes`-trait wordt bewust niet gebruikt
 * omdat die de kolom `deleted_at` vereist.
 *
 * Requirements: 2.1, 2.2
 */
class Project extends Model
{
    use HasArchivedAt;

    protected $table = 'projects';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'hourly_rate',
        'is_active',
        'archived_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workEntries(): HasMany
    {
        return $this->hasMany(WorkEntry::class, 'project_id');
    }
}
