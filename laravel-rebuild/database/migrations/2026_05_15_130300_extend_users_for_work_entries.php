<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name')->after('name')->nullable();
            $table->unsignedBigInteger('organization_id')->after('full_name')->nullable();
            $table->unsignedBigInteger('team_id')->after('organization_id')->nullable();
            $table->string('role', 20)->after('team_id')->default('employee');
            $table->boolean('is_active')->after('role')->default(true);
            $table->date('employment_start')->after('is_active')->nullable();
            $table->date('employment_end')->after('employment_start')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'full_name', 'organization_id', 'team_id',
                'role', 'is_active', 'employment_start', 'employment_end',
            ]);
        });
    }
};
