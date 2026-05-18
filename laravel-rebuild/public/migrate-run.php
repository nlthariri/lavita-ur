<?php

/**
 * TIJDELIJK MIGRATIE-SCRIPT — VERWIJDER NA GEBRUIK!
 *
 * Bezoek: https://ur.la-vitatrading.nl/migrate-run.php
 * Voert alle database-migraties uit.
 */

// Beveiligingstoken — voorkomt dat iemand anders dit per ongeluk uitvoert
$token = 'lavita-migrate-2026-geheim';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd. Gebruik ?token=lavita-migrate-2026-geheim';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "<pre>\n";
echo "=== Database Migraties ===\n\n";

try {
    // Fresh migration: drop alle tabellen en draai opnieuw
    // (nodig omdat vorige run halverwege is gestopt)
    Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
    echo Illuminate\Support\Facades\Artisan::output();
    echo "\n\n=== Migraties succesvol! ===\n";
    echo "\n=== Seeding email templates ===\n";
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EmailTemplatesSeeder', '--force' => true]);
    echo Illuminate\Support\Facades\Artisan::output();
    echo "\n\n=== KLAAR! VERWIJDER DIT BESTAND NU VIA FTP! ===\n";
} catch (Throwable $e) {
    echo "FOUT: " . $e->getMessage() . "\n\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
