<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migratie: create_projects_table
 *
 * Maakt de tabel `projects` aan voor de project- en kostenplaatsmodule.
 * Velden volgen het Data Model uit `design.md`:
 *   id, organization_id, code (uniek per organisatie), name, description,
 *   hourly_rate (DECIMAL 8,2 nullable), is_active, archived_at, created_at,
 *   updated_at.
 *
 * Foreign key naar `organizations` met ON DELETE CASCADE; uniek-index op
 * (organization_id, code) en composite-index op (organization_id, is_active)
 * voor lijst-queries binnen een organisatie.
 *
 * Requirements: 2.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'uq_projects_org_code');
            $table->index(['organization_id', 'is_active'], 'idx_projects_org_active');

            $table->foreign('organization_id', 'fk_projects_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
