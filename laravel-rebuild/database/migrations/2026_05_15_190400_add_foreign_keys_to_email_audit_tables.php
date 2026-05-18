<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('initiator_actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('initiator_org_id_snapshot')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('monthly_report_run_id')->references('id')->on('monthly_report_runs')->nullOnDelete();
        });

        Schema::table('email_outbox_events', function (Blueprint $table): void {
            $table->foreign('outbox_id')->references('id')->on('email_outbox')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('monthly_report_runs', function (Blueprint $table): void {
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('requested_by_actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monthly_report_runs', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['requested_by_actor_id']);
        });

        Schema::table('email_outbox_events', function (Blueprint $table): void {
            $table->dropForeign(['outbox_id']);
            $table->dropForeign(['actor_id']);
        });

        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['initiator_actor_id']);
            $table->dropForeign(['initiator_org_id_snapshot']);
            $table->dropForeign(['monthly_report_run_id']);
        });
    }
};
