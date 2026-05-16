<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_report_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('period_month', 7)->index();
            $table->unsignedBigInteger('requested_by_actor_id')->nullable()->index();
            $table->string('request_id', 100)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('dedupe_key', 191)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_runs');
    }
};