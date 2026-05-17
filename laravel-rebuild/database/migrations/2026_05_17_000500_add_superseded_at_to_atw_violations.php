<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migratie: add_superseded_at_to_atw_violations
 *
 * Voegt de nullable kolom `superseded_at` toe aan `atw_violations` zodat de
 * service-laag bij een soft-delete van een werkregel de gerelateerde
 * ATW-signalen kan markeren als achterhaald (superseded). Een NULL-waarde
 * betekent dat de violation nog actief is; een gevulde timestamp betekent
 * dat de bron-werkregel is verwijderd of de violation om andere redenen
 * niet meer geldig is.
 *
 * Requirements: 1.7 — gerelateerde `atw_violations` records markeren als
 * `superseded` bij DELETE van een werkregel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atw_violations', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('atw_violations', function (Blueprint $table): void {
            $table->dropColumn('superseded_at');
        });
    }
};
