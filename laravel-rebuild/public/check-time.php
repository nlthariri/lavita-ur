<?php

$token = 'lavita-time-2026';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MfaSecret;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Support\Facades\Crypt;

echo "<pre>\n";
echo "=== Server Tijd Check ===\n\n";
echo "Server tijd (UTC):     " . gmdate('Y-m-d H:i:s') . "\n";
echo "Server tijd (AMS):     " . date('Y-m-d H:i:s') . "\n";
echo "Unix timestamp:        " . time() . "\n";
echo "PHP timezone:          " . date_default_timezone_get() . "\n\n";

// Check de TOTP code voor de owner
$email = 'admin@la-vitatrading.nl';
$emailHash = hash('sha256', strtolower($email));
$user = User::where('email_index_hash', $emailHash)->first();

if ($user) {
    $mfaSecret = MfaSecret::where('user_id', $user->id)->first();
    if ($mfaSecret) {
        $secret = Crypt::decryptString($mfaSecret->secret_encrypted);
        $totp = new TotpService();
        
        $currentCode = $totp->getCode($secret, time());
        $prevCode = $totp->getCode($secret, time() - 30);
        $nextCode = $totp->getCode($secret, time() + 30);
        
        echo "=== TOTP Debug ===\n";
        echo "Secret (last 4):       " . substr($secret, -4) . "\n";
        echo "Huidige code (t):      {$currentCode}\n";
        echo "Vorige code (t-30):    {$prevCode}\n";
        echo "Volgende code (t+30):  {$nextCode}\n\n";
        echo "Voer een van deze codes in op de MFA-pagina.\n";
        echo "Als geen van deze werkt, is het secret in je app anders dan op de server.\n";
    }
}

echo "\n=== VERWIJDER DIT BESTAND NA GEBRUIK ===\n";
echo "</pre>";
