<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->unsignedBigInteger('deleted_by_id')->nullable()->after('deleted_at');

            $table->index('deleted_at', 'idx_users_deleted_at');
            $table->foreign('deleted_by_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['deleted_by_id']);
            $table->dropIndex('idx_users_deleted_at');
            $table->dropColumn(['deleted_at', 'deleted_by_id']);
        });
    }
};
