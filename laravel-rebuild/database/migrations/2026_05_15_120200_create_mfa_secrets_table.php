<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_secrets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('secret_encrypted', 512);
            $table->string('issuer', 120)->default('La Vita Urenregistratie');
            $table->string('label', 190);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_secrets');
    }
};
