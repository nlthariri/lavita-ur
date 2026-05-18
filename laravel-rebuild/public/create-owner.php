<?php

/**
 * TIJDELIJK OWNER + MFA SETUP SCRIPT — VERWIJDER NA GEBRUIK!
 *
 * Bezoek: https://ur.la-vitatrading.nl/create-owner.php?token=lavita-owner-2026-geheim
 */

$token = 'lavita-owner-2026-geheim';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Organization;
use App\Models\User;
use App\Services\AuthMfaService;
use Illuminate\Support\Facades\Hash;

echo "<pre>\n";
echo "=== Owner Account + MFA Setup ===\n\n";

try {
    $org = Organization::firstOrCreate(
        ['name' => 'La Vita Trading'],
        ['retention_years' => 7]
    );
    echo "Organisatie: {$org->name} (ID: {$org->id})\n";

    $email = 'admin@la-vitatrading.nl';
    $password = 'LaVita2026!Owner#Secure';

    $existingHash = hash('sha256', strtolower($email));
    $user = User::where('email_index_hash', $existingHash)->first();

    if (!$user) {
        $user = User::create([
            'name' => 'Admin',
            'full_name' => 'Beheerder La Vita',
            'email' => $email,
            'password' => Hash::make($password),
            'organization_id' => $org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        echo "Account aangemaakt (ID: {$user->id})\n";
    } else {
        echo "Account bestaat al (ID: {$user->id})\n";
    }

    // MFA Setup
    echo "\n=== MFA INSTELLEN ===\n";
    $mfaService = app(AuthMfaService::class);
    $result = $mfaService->setupMfa((int) $user->id, $password);

    echo "\nIssuer: {$result['issuer']}\n";
    echo "Label: {$result['label']}\n";

    if (isset($result['provisioning_secret'])) {
        $secret = $result['provisioning_secret'];
        echo "\n========================================\n";
        echo "TOTP SECRET (voer in in Google Authenticator / Authy):\n";
        echo "\n    {$secret}\n";
        echo "\n========================================\n";
    }

    echo "\n=== RECOVERY CODES (bewaar deze veilig!) ===\n";
    foreach ($result['recovery_codes'] as $i => $code) {
        echo "    " . ($i + 1) . ". {$code}\n";
    }

    // Verifieer MFA direct zodat de account volledig actief is
    if (isset($result['provisioning_secret'])) {
        $totpCode = $mfaService->codeForTesting($result['provisioning_secret']);
        $mfaService->verifyMfa((int) $user->id, $totpCode);
        echo "\n MFA is geverifieerd en actief!\n";
    }

    echo "\n=== INLOGGEGEVENS ===\n";
    echo "URL:         https://ur.la-vitatrading.nl/inloggen\n";
    echo "E-mail:      {$email}\n";
    echo "Wachtwoord:  {$password}\n";
    echo "MFA:         Voeg het TOTP SECRET hierboven toe aan je authenticator-app\n";
    echo "             OF gebruik een van de recovery codes hierboven\n";
    echo "\n WIJZIG WACHTWOORD NA EERSTE LOGIN!\n";
    echo "\n=== VERWIJDER DIT BESTAND NU VIA FTP! ===\n";
} catch (Throwable $e) {
    echo "FOUT: " . $e->getMessage() . "\n\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
