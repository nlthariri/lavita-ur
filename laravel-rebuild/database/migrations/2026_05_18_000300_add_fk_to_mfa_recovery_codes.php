<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-02: Voeg foreign key constraint toe op mfa_recovery_codes.user_id.
 *
 * Zonder FK kunnen orphan recovery codes achterblijven wanneer een
 * gebruiker wordt verwijderd. CASCADE DELETE zorgt ervoor dat recovery
 * codes automatisch worden opgeruimd bij user-verwijdering.
 *
 * Iteratie 29 — Expert-panel consensus 8/8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfa_recovery_codes', function (Blueprint $table): void {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mfa_recovery_codes', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }
};
