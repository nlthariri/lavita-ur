<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Globale scope die rijen met een gevulde `archived_at`-kolom standaard
 * uitsluit. Dit is de "soft-delete"-equivalent voor modellen waarvan de
 * archief-kolom niet `deleted_at` heet en daarom niet de Laravel
 * `SoftDeletes`-trait kunnen gebruiken.
 *
 * Aanvullend registreert deze scope query-uitbreidingen `withArchived()`,
 * `withoutArchived()` en `onlyArchived()` op de bouwer, naar analogie van
 * `SoftDeletes::withTrashed()` etc.
 *
 * Requirements: 2.1, 2.2
 */
class NotArchivedScope implements Scope
{
    /**
     * Filter standaard alle rijen met `archived_at IS NOT NULL` weg.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getQualifiedArchivedAtColumn());
    }

    /**
     * Registreer extra query-macros zodra de scope wordt aangekoppeld.
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withArchived', function (Builder $builder, bool $withArchived = true): Builder {
            if (! $withArchived) {
                return $builder->withoutArchived();
            }

            return $builder->withoutGlobalScope($this);
        });

        $builder->macro('withoutArchived', function (Builder $builder): Builder {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope($this)
                ->whereNull($model->getQualifiedArchivedAtColumn());
        });

        $builder->macro('onlyArchived', function (Builder $builder): Builder {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope($this)
                ->whereNotNull($model->getQualifiedArchivedAtColumn());
        });
    }
}
