<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('email_index_hash', 64)->nullable()->unique()->after('email');
            $table->string('phone', 40)->nullable()->after('email_index_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email_index_hash']);
            $table->dropColumn(['email_index_hash', 'phone']);
        });
    }
};
