<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outbox_id')->index();
            $table->string('event_type', 40)->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('request_id', 100)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->json('payload')->nullable();
            $table->string('previous_event_hash', 64)->nullable();
            $table->string('event_hash', 64);
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_outbox_events');
    }
};
