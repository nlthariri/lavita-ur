<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objections', function (Blueprint $table) {
            $table->timestamp('corrected_start_at')->nullable()->after('manager_response');
            $table->timestamp('corrected_end_at')->nullable()->after('corrected_start_at');
            $table->unsignedSmallInteger('corrected_pause_minutes')->nullable()->after('corrected_end_at');
            $table->json('work_entry_before')->nullable()->after('corrected_pause_minutes');
            $table->json('work_entry_after')->nullable()->after('work_entry_before');
        });
    }

    public function down(): void
    {
        Schema::table('objections', function (Blueprint $table) {
            $table->dropColumn([
                'corrected_start_at',
                'corrected_end_at',
                'corrected_pause_minutes',
                'work_entry_before',
                'work_entry_after',
            ]);
        });
    }
};
