<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->timestamp('scrubbed_at')->nullable()->after('sent_at')->index();
            $table->index('sent_at');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->timestamp('scrubbed_at')->nullable()->after('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropIndex(['sent_at']);
            $table->dropColumn('scrubbed_at');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('scrubbed_at');
        });
    }
};