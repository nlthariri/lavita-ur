<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migratie: create_cost_centers_table
 *
 * Maakt de tabel `cost_centers` aan voor de project- en kostenplaatsmodule.
 * Velden volgen het Data Model uit `design.md`:
 *   id, organization_id, code (uniek per organisatie), name, description,
 *   is_active, archived_at, created_at, updated_at.
 *
 * Foreign key naar `organizations` met ON DELETE CASCADE; uniek-index op
 * (organization_id, code) en composite-index op (organization_id, is_active)
 * voor lijst-queries binnen een organisatie.
 *
 * Requirements: 2.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'uq_costc_org_code');
            $table->index(['organization_id', 'is_active'], 'idx_costc_org_active');

            $table->foreign('organization_id', 'fk_costc_org')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
