<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table): void {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('updated_by_actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('email_templates', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['updated_by_actor_id']);
        });
    }
};
