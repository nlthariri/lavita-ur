<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise-hardening iteratie 2: ontbrekende indexen en FK-constraints.
 *
 * Bevindingen uit de multi-partij audit:
 * - audit_events.actor_id: geen index, maar gefilterd in deep pseudonymization
 * - atw_violations.severity: geen index, maar gefilterd in dashboard queries
 * - atw_violations.superseded_at: geen index, maar gefilterd met whereNull
 * - email_outbox.user_id: geen index ondanks user-scoped lookups
 * - email_outbox.type: geen index, gefilterd in template-flows
 * - email_templates.organization_id: geen FK constraint
 * - email_templates.updated_by_actor_id: geen FK constraint
 *
 * Consensus: unaniem goedgekeurd door DB-engineer, backend-team, security-engineer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Indexen op audit_events ---
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index('actor_id', 'idx_audit_events_actor_id');
        });

        // --- Indexen op atw_violations ---
        Schema::table('atw_violations', function (Blueprint $table): void {
            $table->index('severity', 'idx_atw_violations_severity');
            $table->index('superseded_at', 'idx_atw_violations_superseded_at');
        });

        // --- Indexen op email_outbox ---
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->index('user_id', 'idx_email_outbox_user_id');
            $table->index('type', 'idx_email_outbox_type');
        });

        // --- FK constraints op email_templates ---
        if (Schema::hasTable('email_templates')) {
            Schema::table('email_templates', function (Blueprint $table): void {
                $table->foreign('organization_id', 'fk_email_templates_org')
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete();

                $table->foreign('updated_by_actor_id', 'fk_email_templates_actor')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('idx_audit_events_actor_id');
        });

        Schema::table('atw_violations', function (Blueprint $table): void {
            $table->dropIndex('idx_atw_violations_severity');
            $table->dropIndex('idx_atw_violations_superseded_at');
        });

        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropIndex('idx_email_outbox_user_id');
            $table->dropIndex('idx_email_outbox_type');
        });

        if (Schema::hasTable('email_templates')) {
            Schema::table('email_templates', function (Blueprint $table): void {
                $table->dropForeign('fk_email_templates_org');
                $table->dropForeign('fk_email_templates_actor');
            });
        }
    }
};
