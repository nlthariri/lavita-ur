<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atw_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('work_entry_id')->nullable();
            $table->string('violation_type', 30);
            $table->string('severity', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('current_minutes');
            $table->unsignedSmallInteger('threshold_minutes');
            $table->string('details', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'user_id', 'created_at'], 'idx_atw_violations_org_user');

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('work_entry_id')->references('id')->on('work_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atw_violations');
    }
};
