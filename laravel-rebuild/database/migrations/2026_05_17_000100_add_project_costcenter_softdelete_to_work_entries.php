<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migratie: add_project_costcenter_softdelete_to_work_entries
 *
 * Voegt de kolommen `project_id`, `cost_center_id` en `deleted_at` toe aan
 * `work_entries`, plus bijbehorende indexen. De foreign keys naar `projects`
 * en `cost_centers` worden bewust nog NIET aangelegd in deze migratie omdat
 * die tabellen pas in taak 2.1 (`create_projects_table`,
 * `create_cost_centers_table`) worden gecreëerd. De FK-statements staan als
 * commentaar/placeholder in deze migratie, en worden in taak 2.1 daadwerkelijk
 * geactiveerd via een aparte migratie.
 *
 * Requirements: 1.7 (soft-delete `deleted_at` op werkregels) en
 * 2.3 (`project_id` + `cost_center_id` kolommen + indexen op `work_entries`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('team_id');
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('project_id');
            $table->timestamp('deleted_at')->nullable()->after('updated_at');

            $table->index('project_id', 'idx_we_project');
            $table->index('cost_center_id', 'idx_we_cost_center');
            $table->index('deleted_at', 'idx_we_deleted_at');

            // Placeholder FKs — bewust uitgecommentarieerd. Deze foreign keys
            // worden geactiveerd door taak 2.1 zodra de tabellen `projects`
            // en `cost_centers` bestaan.
            //
            // $table->foreign('project_id', 'fk_we_project')
            //     ->references('id')->on('projects')->nullOnDelete();
            // $table->foreign('cost_center_id', 'fk_we_cost_center')
            //     ->references('id')->on('cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_entries', function (Blueprint $table) {
            // Placeholder FK-drops — alleen relevant nadat taak 2.1 de FKs
            // heeft toegevoegd. Worden in de bijbehorende rollback van 2.1
            // afgehandeld.
            //
            // $table->dropForeign('fk_we_project');
            // $table->dropForeign('fk_we_cost_center');

            $table->dropIndex('idx_we_project');
            $table->dropIndex('idx_we_cost_center');
            $table->dropIndex('idx_we_deleted_at');

            $table->dropColumn(['project_id', 'cost_center_id', 'deleted_at']);
        });
    }
};
