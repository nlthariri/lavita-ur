<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix de te brede unique constraint op objections.
 *
 * Probleem: `unique(['work_entry_id', 'status'])` blokkeert meerdere
 * bezwaren met dezelfde status op dezelfde werkregel. Dit betekent dat
 * een medewerker na een REJECTED bezwaar nooit meer een nieuw bezwaar
 * kan indienen op dezelfde werkregel (want er kan maar één REJECTED
 * rij bestaan).
 *
 * Oplossing: Verwijder de brede unique constraint en vervang door een
 * partial unique index die alleen OPEN-status afdwingt. In MySQL (dat
 * geen partial unique indexes ondersteunt) gebruiken we een generated
 * column + unique index als workaround.
 *
 * Consensus: unaniem goedgekeurd door DB-engineer, juridisch adviseur,
 * functioneel analist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite: geen FK-probleem, gewoon de index droppen en een
            // gewone index toevoegen. De applicatie-laag handhaaft de
            // constraint via lockForUpdate() in ObjectionsService::submit().
            Schema::table('objections', function (Blueprint $table): void {
                try {
                    $table->dropUnique('uq_objection_open_per_entry');
                } catch (\Throwable) {
                    // Index bestaat mogelijk niet in SQLite tests
                }
                $table->index(['work_entry_id', 'status'], 'idx_objections_entry_status');
            });

            return;
        }

        // MySQL/MariaDB: de unique index wordt gebruikt als backing-index
        // voor de FK op work_entry_id. We moeten eerst de FK droppen,
        // dan de index, dan de nieuwe structuur aanmaken, en de FK
        // weer toevoegen.

        // Stap 1: Drop de FK op work_entry_id
        Schema::table('objections', function (Blueprint $table): void {
            $table->dropForeign(['work_entry_id']);
        });

        // Stap 2: Drop de te brede unique constraint
        Schema::table('objections', function (Blueprint $table): void {
            $table->dropUnique('uq_objection_open_per_entry');
        });

        // Stap 3: Voeg een gewone index toe op work_entry_id (nodig voor FK)
        Schema::table('objections', function (Blueprint $table): void {
            $table->index('work_entry_id', 'idx_objections_work_entry_id');
        });

        // Stap 4: Herstel de FK op work_entry_id
        Schema::table('objections', function (Blueprint $table): void {
            $table->foreign('work_entry_id')->references('id')->on('work_entries')->cascadeOnDelete();
        });

        // Stap 5: Generated column + partial unique index
        DB::statement("
            ALTER TABLE objections
            ADD COLUMN open_guard BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status = 'OPEN' THEN work_entry_id ELSE NULL END
                ) STORED
        ");

        Schema::table('objections', function (Blueprint $table): void {
            $table->unique('open_guard', 'uq_objection_one_open_per_entry');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('objections', function (Blueprint $table): void {
                $table->dropIndex('idx_objections_entry_status');
            });
        } else {
            Schema::table('objections', function (Blueprint $table): void {
                $table->dropUnique('uq_objection_one_open_per_entry');
                $table->dropColumn('open_guard');
                $table->dropIndex('idx_objections_work_entry_id');
            });
        }

        // Herstel de originele (te brede) constraint
        Schema::table('objections', function (Blueprint $table): void {
            $table->unique(['work_entry_id', 'status'], 'uq_objection_open_per_entry');
        });
    }
};
