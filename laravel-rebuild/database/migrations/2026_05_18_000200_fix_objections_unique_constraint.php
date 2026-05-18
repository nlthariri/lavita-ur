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
        // Stap 1: Verwijder de te brede unique constraint
        Schema::table('objections', function (Blueprint $table): void {
            $table->dropUnique('uq_objection_open_per_entry');
        });

        // Stap 2: Voeg een generated column toe die NULL is voor niet-OPEN
        // statussen. MySQL's unique index negeert NULL-waarden, waardoor
        // meerdere REJECTED/APPROVED rijen per work_entry_id mogelijk zijn
        // maar slechts één OPEN.
        if (DB::getDriverName() === 'sqlite') {
            // SQLite ondersteunt geen generated columns op dezelfde manier.
            // We voegen een gewone index toe; de applicatie-laag handhaaft
            // de constraint via lockForUpdate() in ObjectionsService::submit().
            Schema::table('objections', function (Blueprint $table): void {
                $table->index(['work_entry_id', 'status'], 'idx_objections_entry_status');
            });
        } else {
            // MySQL/MariaDB: generated column + unique index
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
            });
        }

        // Herstel de originele (te brede) constraint
        Schema::table('objections', function (Blueprint $table): void {
            $table->unique(['work_entry_id', 'status'], 'uq_objection_open_per_entry');
        });
    }
};
