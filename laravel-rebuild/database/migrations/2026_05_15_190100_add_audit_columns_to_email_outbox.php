<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->unsignedBigInteger('initiator_actor_id')->nullable()->after('user_id')->index();
            $table->string('initiator_role_snapshot', 30)->nullable()->after('initiator_actor_id');
            $table->unsignedBigInteger('initiator_org_id_snapshot')->nullable()->after('initiator_role_snapshot')->index();
            $table->unsignedBigInteger('monthly_report_run_id')->nullable()->after('initiator_org_id_snapshot')->index();
            $table->string('request_id', 100)->nullable()->after('monthly_report_run_id');
            $table->string('source_ip', 45)->nullable()->after('request_id');
            $table->string('user_agent', 500)->nullable()->after('source_ip');
            $table->string('correlation_id', 64)->nullable()->after('user_agent')->index();
            $table->string('subject_sha256', 64)->nullable()->after('subject');
            $table->string('body_text_sha256', 64)->nullable()->after('body_text');
            $table->string('body_html_sha256', 64)->nullable()->after('body_html');
        });
    }

    public function down(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropColumn([
                'initiator_actor_id',
                'initiator_role_snapshot',
                'initiator_org_id_snapshot',
                'monthly_report_run_id',
                'request_id',
                'source_ip',
                'user_agent',
                'correlation_id',
                'subject_sha256',
                'body_text_sha256',
                'body_html_sha256',
            ]);
        });
    }
};
