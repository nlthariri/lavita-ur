<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migratie: add_foreign_keys_to_work_entries_for_projects_and_cost_centers
 *
 * Activeert de in 2026_05_17_000100 als placeholder voorbereide foreign keys
 * `work_entries.project_id` → `projects.id` en
 * `work_entries.cost_center_id` → `cost_centers.id`, beide ON DELETE SET NULL.
 *
 * Deze FKs worden in een aparte migratie geactiveerd zodra de tabellen
 * `projects` en `cost_centers` bestaan (zie migraties 2026_05_17_000200 en
 * 2026_05_17_000300).
 *
 * Requirements: 2.3
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite ondersteunt geen ALTER TABLE ADD CONSTRAINT FOREIGN KEY voor
        // bestaande tabellen. Onder testing (SQLite) slaan we de FK-creatie
        // over; integriteit op `project_id` / `cost_center_id` wordt daar
        // gehandhaafd door de service-laag (org-mismatch + inactive checks).
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('work_entries', function (Blueprint $table) {
            $table->foreign('project_id', 'fk_we_project')
                ->references('id')->on('projects')
                ->nullOnDelete();
            $table->foreign('cost_center_id', 'fk_we_cost_center')
                ->references('id')->on('cost_centers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('work_entries', function (Blueprint $table) {
            $table->dropForeign('fk_we_project');
            $table->dropForeign('fk_we_cost_center');
        });
    }
};
