<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill email_index_hash for all existing users.
        // Since the email column may already be encrypted (via cast), we use
        // Eloquent to decrypt and then compute the hash.
        User::query()
            ->whereNull('email_index_hash')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $email = $user->email;
                    if ($email !== null && $email !== '') {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update([
                                'email_index_hash' => hash('sha256', strtolower((string) $email)),
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('users')->update(['email_index_hash' => null]);
    }
};
