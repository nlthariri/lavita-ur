<?php

$token = 'lavita-cache-2026';

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
Illuminate\Support\Facades\Artisan::call('config:clear');
echo Illuminate\Support\Facades\Artisan::output();
Illuminate\Support\Facades\Artisan::call('cache:clear');
echo Illuminate\Support\Facades\Artisan::output();
Illuminate\Support\Facades\Artisan::call('view:clear');
echo Illuminate\Support\Facades\Artisan::output();
echo "\nKlaar! Verwijder dit bestand.\n</pre>";
