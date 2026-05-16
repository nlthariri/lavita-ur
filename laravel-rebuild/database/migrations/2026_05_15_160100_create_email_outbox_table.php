<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient', 255);
            $table->string('subject', 500);
            $table->text('body_text');
            $table->text('body_html');
            $table->string('type', 80)->default('custom');
            $table->json('attachments')->nullable();
            $table->enum('status', ['queued', 'retrying', 'sent', 'failed'])->default('queued')->index();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('next_attempt_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_outbox');
    }
};
