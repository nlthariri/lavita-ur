<?php
/**
 * Eenmalig cache-clear script — voer uit via browser:
 * https://ur.la-vitatrading.nl/clear-cache.php?key=LaVita2026ClearNow
 *
 * VERWIJDER DIT BESTAND NA UITVOERING.
 */
if (($_GET['key'] ?? '') !== 'LaVita2026ClearNow') {
    http_response_code(403);
    die('Forbidden');
}

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::capture());

header('Content-Type: text/plain; charset=utf-8');

echo "Clearing caches...\n\n";

// View cache
\Illuminate\Support\Facades\Artisan::call('view:clear');
echo "✓ View cache cleared\n";

// Route cache
\Illuminate\Support\Facades\Artisan::call('route:clear');
echo "✓ Route cache cleared\n";

// Config cache
\Illuminate\Support\Facades\Artisan::call('config:clear');
echo "✓ Config cache cleared\n";

// Application cache
\Illuminate\Support\Facades\Artisan::call('cache:clear');
echo "✓ Application cache cleared\n";

echo "\n✅ Alle caches gewist. VERWIJDER DIT BESTAND NU.\n";
