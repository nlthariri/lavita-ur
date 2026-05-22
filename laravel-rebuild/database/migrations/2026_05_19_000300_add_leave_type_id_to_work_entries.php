<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('leave_type_id')
                ->nullable()
                ->after('type');

            $table->index('leave_type_id', 'idx_we_leave_type');

            $table->foreign('leave_type_id', 'fk_we_leave_type')
                ->references('id')
                ->on('leave_types')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('work_entries', function (Blueprint $table): void {
            $table->dropForeign('fk_we_leave_type');
            $table->dropIndex('idx_we_leave_type');
            $table->dropColumn('leave_type_id');
        });
    }
};
