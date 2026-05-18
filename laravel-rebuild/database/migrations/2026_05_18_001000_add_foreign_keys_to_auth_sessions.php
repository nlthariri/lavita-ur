<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voegt foreign key constraint toe aan auth_sessions.user_id.
 * Voorkomt orphaned sessions bij user-verwijdering en verbetert
 * referentiële integriteit.
 *
 * CASCADE on delete: wanneer een user hard-deleted wordt (na
 * pseudonimisering + retentieperiode), worden de bijbehorende
 * sessies automatisch opgeruimd.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite ondersteunt geen ALTER TABLE ADD CONSTRAINT, dus
        // skippen we de FK in test-omgeving.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }
};
