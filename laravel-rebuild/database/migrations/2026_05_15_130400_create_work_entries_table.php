<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('registered_by_id');
            $table->date('entry_date');
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->unsignedSmallInteger('pause_minutes')->default(0);
            $table->unsignedSmallInteger('net_minutes');
            $table->string('type', 20)->default('WORK');
            $table->string('note', 500)->nullable();
            $table->boolean('is_finalized')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'entry_date', 'start_at'], 'uq_work_entry_employee_date_start');
            $table->index(['organization_id', 'employee_id', 'entry_date'], 'idx_work_entries_org_emp_date');
            $table->index(['team_id', 'entry_date'], 'idx_work_entries_team_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_entries');
    }
};
