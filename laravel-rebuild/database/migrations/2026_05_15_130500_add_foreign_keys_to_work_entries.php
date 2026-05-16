<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });

        Schema::table('work_entries', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('registered_by_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_entries', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'employee_id', 'registered_by_id', 'team_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'team_id']);
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'manager_id']);
        });
    }
};
