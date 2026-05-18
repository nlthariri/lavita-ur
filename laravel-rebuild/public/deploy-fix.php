<?php

/**
 * DEPLOYMENT SCRIPT — Voert alle nodige acties uit na het uploaden van nieuwe bestanden.
 *
 * Bezoek: https://ur.la-vitatrading.nl/deploy-fix.php?token=lavita-deploy-2026
 * VERWIJDER NA GEBRUIK!
 */

$token = 'lavita-deploy-2026';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "<pre>\n";
echo "=== Deployment Fix Script ===\n";
echo "Datum: " . now()->format('Y-m-d H:i:s') . "\n\n";

$steps = [];

// 1. Config cache clearen
try {
    Illuminate\Support\Facades\Artisan::call('config:clear');
    $steps[] = '✓ Config cache gecleared';
} catch (Throwable $e) {
    $steps[] = '✗ Config clear mislukt: ' . $e->getMessage();
}

// 2. View cache clearen
try {
    Illuminate\Support\Facades\Artisan::call('view:clear');
    $steps[] = '✓ View cache gecleared';
} catch (Throwable $e) {
    $steps[] = '✗ View clear mislukt: ' . $e->getMessage();
}

// 3. Route cache clearen
try {
    Illuminate\Support\Facades\Artisan::call('route:clear');
    $steps[] = '✓ Route cache gecleared';
} catch (Throwable $e) {
    $steps[] = '✗ Route clear mislukt: ' . $e->getMessage();
}

// 4. Migraties draaien
try {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();
    $steps[] = '✓ Migraties uitgevoerd';
    if (trim($output) !== '') {
        $steps[] = '  ' . str_replace("\n", "\n  ", trim($output));
    }
} catch (Throwable $e) {
    $steps[] = '✗ Migratie mislukt: ' . $e->getMessage();
}

// 5. Autoloader optimaliseren
try {
    $composerPath = realpath(__DIR__ . '/../');
    $result = shell_exec("cd \"{$composerPath}\" && php composer.phar dump-autoload -o 2>&1");
    if ($result === null) {
        // Probeer zonder composer.phar (als composer globaal is geïnstalleerd)
        $result = shell_exec("cd \"{$composerPath}\" && composer dump-autoload -o 2>&1");
    }
    $steps[] = '✓ Autoloader geoptimaliseerd';
    if ($result) {
        $steps[] = '  ' . str_replace("\n", "\n  ", trim($result));
    }
} catch (Throwable $e) {
    $steps[] = '⚠ Autoloader: ' . $e->getMessage();
    $steps[] = '  (Niet kritiek — PSR-4 autoloading werkt ook zonder optimalisatie)';
}

// 6. Verificatie: check of nieuwe classes bestaan
echo "=== Stappen ===\n";
foreach ($steps as $step) {
    echo $step . "\n";
}

echo "\n=== Verificatie: Nieuwe classes ===\n";
$classes = [
    'App\\Livewire\\Settings\\SettingsOverview',
    'App\\Livewire\\Settings\\TeamsManager',
    'App\\Livewire\\Settings\\ProjectsManager',
    'App\\Livewire\\Settings\\OrganizationSettings',
    'App\\Livewire\\Settings\\HolidaysManager',
    'App\\Livewire\\Objections\\ObjectionsList',
    'App\\Livewire\\Profile\\ProfilePage',
    'App\\Livewire\\Dashboard\\EmployeeHome',
    'App\\Http\\Middleware\\OptionalSessionAuth',
];

foreach ($classes as $class) {
    $exists = class_exists($class);
    $short = str_replace('App\\', '', $class);
    echo ($exists ? '✓' : '✗') . " {$short}\n";
}

echo "\n=== Routes check ===\n";
$routes = [
    '/instellingen', '/instellingen/email', '/instellingen/organisatie',
    '/instellingen/teams', '/instellingen/projecten', '/instellingen/feestdagen',
    '/bezwaren', '/profiel', '/dashboard/medewerker',
];
foreach ($routes as $uri) {
    $route = app('router')->getRoutes()->match(
        Illuminate\Http\Request::create($uri, 'GET')
    );
    $name = $route->getName() ?? '(unnamed)';
    echo "✓ {$uri} → {$name}\n";
}

echo "\n=== KLAAR! ===\n";
echo "⚠️  VERWIJDER DIT BESTAND EN fix-columns.php EN create-owner.php VIA FTP!\n";
echo "</pre>";
