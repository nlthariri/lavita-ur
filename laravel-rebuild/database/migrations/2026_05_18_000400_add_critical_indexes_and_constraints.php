<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iteratie 32: Kritieke indexen en constraints voor productie-performance.
 *
 * - users.organization_id index (meest-gebruikte query-patroon)
 * - users.team_id index (manager-scope queries)
 * - users.(organization_id, is_active, role) composite index
 * - email_outbox.(status, next_attempt_at) composite index (queue worker)
 * - monthly_report_runs.dedupe_key unique constraint
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Alleen toevoegen als ze nog niet bestaan (idempotent)
            if (! $this->hasIndex('users', 'users_organization_id_index')) {
                $table->index('organization_id');
            }
            if (! $this->hasIndex('users', 'users_team_id_index')) {
                $table->index('team_id');
            }
            if (! $this->hasIndex('users', 'users_org_active_role_index')) {
                $table->index(['organization_id', 'is_active', 'role'], 'users_org_active_role_index');
            }
        });

        Schema::table('email_outbox', function (Blueprint $table): void {
            if (! $this->hasIndex('email_outbox', 'email_outbox_status_next_attempt_index')) {
                $table->index(['status', 'next_attempt_at'], 'email_outbox_status_next_attempt_index');
            }
        });

        // monthly_report_runs.dedupe_key: upgrade van index naar unique
        Schema::table('monthly_report_runs', function (Blueprint $table): void {
            // Drop de bestaande non-unique index als die bestaat
            try {
                $table->dropIndex(['dedupe_key']);
            } catch (Throwable) {
                // Index bestaat mogelijk niet
            }
            $table->unique('dedupe_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_org_active_role_index');
            $table->dropIndex(['team_id']);
            $table->dropIndex(['organization_id']);
        });

        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropIndex('email_outbox_status_next_attempt_index');
        });

        Schema::table('monthly_report_runs', function (Blueprint $table): void {
            $table->dropUnique(['dedupe_key']);
            $table->index('dedupe_key');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);
        foreach ($indexes as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
