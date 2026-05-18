<?php

/**
 * FIX: Vergroot encrypted kolommen in users-tabel.
 *
 * Bezoek: https://ur.la-vitatrading.nl/fix-columns.php?token=lavita-fix-2026
 * VERWIJDER NA GEBRUIK!
 */

$token = 'lavita-fix-2026';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "<pre>\n";
echo "=== Fix Encrypted Kolommen ===\n\n";

try {
    // 1. Phone: VARCHAR(40) → TEXT
    echo "1. phone kolom vergroten naar TEXT...\n";
    DB::statement('ALTER TABLE `users` MODIFY `phone` TEXT NULL');
    echo "   ✓ phone is nu TEXT\n\n";

    // 2. Full_name: VARCHAR(255) → TEXT
    echo "2. full_name kolom vergroten naar TEXT...\n";
    DB::statement('ALTER TABLE `users` MODIFY `full_name` TEXT NULL');
    echo "   ✓ full_name is nu TEXT\n\n";

    // 3. Email: VARCHAR(255) → TEXT (unique index verwijderen eerst)
    echo "3. email kolom vergroten naar TEXT...\n";
    try {
        DB::statement('ALTER TABLE `users` DROP INDEX `users_email_unique`');
        echo "   ✓ users_email_unique index verwijderd\n";
    } catch (Throwable $e) {
        echo "   - unique index bestond niet (ok)\n";
    }
    DB::statement('ALTER TABLE `users` MODIFY `email` TEXT NOT NULL');
    echo "   ✓ email is nu TEXT\n\n";

    // 4. Markeer migratie als uitgevoerd
    echo "4. Migratie registreren...\n";
    $migrationName = '2026_05_18_190000_enlarge_encrypted_columns_users';
    $exists = DB::table('migrations')->where('migration', $migrationName)->exists();
    if (!$exists) {
        $batch = (int) DB::table('migrations')->max('batch') + 1;
        DB::table('migrations')->insert([
            'migration' => $migrationName,
            'batch' => $batch,
        ]);
        echo "   ✓ Migratie geregistreerd (batch {$batch})\n\n";
    } else {
        echo "   - Migratie was al geregistreerd\n\n";
    }

    echo "=== KLAAR! Alle kolommen zijn vergroot. ===\n";
    echo "\n⚠️  VERWIJDER DIT BESTAND NU VIA FTP!\n";

} catch (Throwable $e) {
    echo "FOUT: " . $e->getMessage() . "\n\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
