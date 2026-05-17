<?php

namespace App\Models\Concerns;

use App\Models\Scopes\NotArchivedScope;
use Illuminate\Support\Carbon;

/**
 * Trait voor modellen die een `archived_at`-kolom gebruiken als soft-delete
 * indicator. Registreert de globale `NotArchivedScope`, biedt helper-methodes
 * `archive()`, `unarchive()` en `isArchived()` en cast `archived_at` naar een
 * Carbon-instance.
 *
 * Wordt bewust niet gebaseerd op Laravel's `SoftDeletes`-trait, omdat die
 * uitgaat van de kolomnaam `deleted_at` en de Data Model in `design.md`
 * expliciet `archived_at` voorschrijft voor de modellen `Project` en
 * `CostCenter` (Requirements 2.1, 2.2).
 */
trait HasArchivedAt
{
    /**
     * Boot de trait door de globale scope te registreren.
     */
    public static function bootHasArchivedAt(): void
    {
        static::addGlobalScope(new NotArchivedScope);
    }

    /**
     * Initialiseer de trait door cast en fillable bij te werken.
     */
    public function initializeHasArchivedAt(): void
    {
        if (! isset($this->casts[$this->getArchivedAtColumn()])) {
            $this->casts[$this->getArchivedAtColumn()] = 'datetime';
        }
    }

    /**
     * Markeer het model als gearchiveerd (soft-delete-equivalent).
     */
    public function archive(): bool
    {
        if ($this->isArchived()) {
            return true;
        }

        $this->{$this->getArchivedAtColumn()} = Carbon::now();

        return (bool) $this->save();
    }

    /**
     * Maak een eerder gearchiveerd model weer actief.
     */
    public function unarchive(): bool
    {
        if (! $this->isArchived()) {
            return true;
        }

        $this->{$this->getArchivedAtColumn()} = null;

        return (bool) $this->save();
    }

    /**
     * Bepaal of het model momenteel gearchiveerd is.
     */
    public function isArchived(): bool
    {
        return $this->{$this->getArchivedAtColumn()} !== null;
    }

    /**
     * Naam van de archief-kolom op de tabel.
     */
    public function getArchivedAtColumn(): string
    {
        return defined(static::class.'::ARCHIVED_AT') ? static::ARCHIVED_AT : 'archived_at';
    }

    /**
     * Volledig gekwalificeerde naam van de archief-kolom (incl. tabelprefix).
     */
    public function getQualifiedArchivedAtColumn(): string
    {
        return $this->qualifyColumn($this->getArchivedAtColumn());
    }
}
