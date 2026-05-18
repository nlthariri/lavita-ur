<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pending_input_threshold_days')->default(3)->after('retention_years');
        });

        // Add CHECK constraint for valid range 1..14 (MySQL only; SQLite
        // does not support ALTER TABLE ... ADD CONSTRAINT).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE organizations ADD CONSTRAINT chk_pending_input_threshold_days CHECK (pending_input_threshold_days BETWEEN 1 AND 14)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT chk_pending_input_threshold_days');
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('pending_input_threshold_days');
        });
    }
};
