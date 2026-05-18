<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vergroot encrypted kolommen in de users-tabel.
 *
 * Laravel's `encrypted` cast slaat waarden op als base64-encoded
 * JSON met IV + MAC + tag, wat ~3-4x groter is dan de plaintext.
 * Een telefoonnummer van 15 tekens wordt ~200 tekens encrypted.
 * Een naam van 100 tekens wordt ~300 tekens encrypted.
 *
 * Oorspronkelijke kolomgroottes waren te klein voor encrypted data:
 * - phone: VARCHAR(40) → TEXT
 * - full_name: mogelijk ook te klein → TEXT
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Encrypted kolommen hebben TEXT nodig omdat de ciphertext
            // 3-4x groter is dan de plaintext waarde.
            $table->text('phone')->nullable()->change();
            $table->text('full_name')->nullable()->change();
        });

        // Email kolom: verwijder eerst de unique index (TEXT kan geen unique index hebben),
        // dan verander naar TEXT. De email_index_hash kolom dient als zoek-index.
        try {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_email_unique');
            });
        } catch (\Throwable $e) {
            // Index bestaat mogelijk niet meer — veilig om te negeren.
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->text('email')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->change();
            $table->string('full_name', 255)->nullable()->change();
            $table->string('email', 255)->change();
        });

        try {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('email');
            });
        } catch (\Throwable $e) {
            // Kan falen als er duplicate encrypted waarden zijn.
        }
    }
};
