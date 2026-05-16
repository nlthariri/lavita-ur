<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('work_entry_id');
            $table->unsignedBigInteger('submitted_by_id');
            $table->unsignedBigInteger('reviewed_by_id')->nullable();
            $table->text('motivation');
            $table->text('manager_response')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Eén openstaand bezwaar per werkregel tegelijk
            $table->unique(['work_entry_id', 'status'], 'uq_objection_open_per_entry');
            $table->index(['organization_id', 'status'], 'idx_objections_org_status');
            $table->index('submitted_by_id', 'idx_objections_submitted_by');
        });

        Schema::table('objections', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('work_entry_id')->references('id')->on('work_entries')->cascadeOnDelete();
            $table->foreign('submitted_by_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objections');
    }
};
