<?php

/**
 * DIAGNOSE: Test de volledige welkomstmail-keten stap voor stap.
 *
 * Bezoek: https://ur.la-vitatrading.nl/diagnose-email.php?token=lavita-diag-2026
 * VERWIJDER NA GEBRUIK!
 */

$token = 'lavita-diag-2026';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\EmailTemplateService;
use App\Services\EmailOutboxService;
use App\Services\PasswordResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

echo "<pre>\n";
echo "=== E-mail Diagnose ===\n";
echo "Datum: " . now()->format('Y-m-d H:i:s') . "\n\n";

// Stap 1: Mail config
echo "--- STAP 1: Mail configuratie ---\n";
$mailConfig = config('mail');
echo "Mailer: " . ($mailConfig['default'] ?? 'niet ingesteld') . "\n";
$smtp = $mailConfig['mailers']['smtp'] ?? [];
echo "Host: " . ($smtp['host'] ?? 'NIET INGESTELD') . "\n";
echo "Port: " . ($smtp['port'] ?? 'NIET INGESTELD') . "\n";
echo "Encryption: " . ($smtp['encryption'] ?? $smtp['scheme'] ?? 'geen') . "\n";
echo "Username: " . ($smtp['username'] ?? 'NIET INGESTELD') . "\n";
echo "Password: " . (empty($smtp['password']) ? 'LEEG!' : '***' . substr($smtp['password'], -3)) . "\n";
echo "From: " . ($mailConfig['from']['address'] ?? 'NIET INGESTELD') . "\n";
echo "From name: " . ($mailConfig['from']['name'] ?? 'NIET INGESTELD') . "\n\n";

// Stap 2: Template render
echo "--- STAP 2: Template renderen ---\n";
try {
    $templateService = app(EmailTemplateService::class);
    $vars = [
        'full_name' => 'Test Gebruiker',
        'email' => 'test@example.com',
        'role' => 'employee',
        'organization_name' => 'La Vita Trading',
        'team_name' => 'Team A',
        'login_url' => 'https://ur.la-vitatrading.nl/inloggen',
        'reset_link' => 'https://ur.la-vitatrading.nl/wachtwoord-reset?token=test123',
        'valid_hours' => '24',
    ];
    $rendered = $templateService->render('welcome_email', $vars, 1);
    echo "✓ Template gerenderd\n";
    echo "  Subject: " . substr($rendered['subject'], 0, 80) . "\n";
    echo "  Body text lengte: " . strlen($rendered['body_text']) . " bytes\n";
    echo "  Body html lengte: " . strlen($rendered['body_html']) . " bytes\n\n";
} catch (Throwable $e) {
    echo "✗ Template render MISLUKT: " . $e->getMessage() . "\n";
    echo "  Class: " . get_class($e) . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}

// Stap 3: PasswordResetService
echo "--- STAP 3: PasswordResetService ---\n";
try {
    $resetService = app(PasswordResetService::class);
    echo "✓ PasswordResetService geresolved\n";
    
    // Check of de password_reset_tokens tabel bestaat
    $hasTable = \Illuminate\Support\Facades\Schema::hasTable('password_reset_tokens');
    echo "  password_reset_tokens tabel: " . ($hasTable ? '✓ bestaat' : '✗ ONTBREEKT') . "\n";
    
    if (!$hasTable) {
        $hasOldTable = \Illuminate\Support\Facades\Schema::hasTable('password_resets');
        echo "  password_resets tabel (oud): " . ($hasOldTable ? '✓ bestaat' : '✗ ONTBREEKT') . "\n";
    }
    echo "\n";
} catch (Throwable $e) {
    echo "✗ PasswordResetService MISLUKT: " . $e->getMessage() . "\n";
    echo "  Class: " . get_class($e) . "\n\n";
}

// Stap 4: Email outbox tabel
echo "--- STAP 4: Email outbox tabel ---\n";
try {
    $hasOutbox = \Illuminate\Support\Facades\Schema::hasTable('email_outbox');
    echo "  email_outbox tabel: " . ($hasOutbox ? '✓ bestaat' : '✗ ONTBREEKT') . "\n";
    
    if ($hasOutbox) {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('email_outbox');
        echo "  Kolommen (" . count($columns) . "): " . implode(', ', $columns) . "\n";
        
        // Check of alle benodigde kolommen bestaan
        $required = ['idempotency_key', 'organization_id', 'user_id', 'recipient', 'subject', 'body_text', 'body_html', 'type', 'status', 'retry_count', 'next_attempt_at'];
        $missing = array_diff($required, $columns);
        if (empty($missing)) {
            echo "  ✓ Alle verplichte kolommen aanwezig\n";
        } else {
            echo "  ✗ ONTBREKENDE kolommen: " . implode(', ', $missing) . "\n";
        }
    }
    
    $hasEvents = \Illuminate\Support\Facades\Schema::hasTable('email_outbox_events');
    echo "  email_outbox_events tabel: " . ($hasEvents ? '✓ bestaat' : '✗ ONTBREEKT') . "\n";
    echo "\n";
} catch (Throwable $e) {
    echo "✗ Outbox check MISLUKT: " . $e->getMessage() . "\n\n";
}

// Stap 5: Probeer een test-dispatch (zonder echt te versturen)
echo "--- STAP 5: Test outbox dispatch ---\n";
try {
    $outboxService = app(EmailOutboxService::class);
    $result = $outboxService->dispatch([
        'idempotency_key' => 'diagnose-test-' . time(),
        'organization_id' => 1,
        'user_id' => 4, // admin user
        'recipient' => 'test-diagnose@example.com',
        'subject' => 'Diagnose test',
        'body_text' => 'Dit is een diagnose test.',
        'body_html' => '<p>Dit is een diagnose test.</p>',
        'type' => 'welcome_email',
    ], [
        'actor_id' => 4,
        'organization_id' => 1,
    ]);
    echo "✓ Outbox dispatch geslaagd\n";
    echo "  ID: " . ($result['id'] ?? '?') . "\n";
    echo "  Status: " . ($result['status'] ?? '?') . "\n";
    echo "  Idempotent: " . ($result['idempotent'] ? 'ja' : 'nee') . "\n\n";
} catch (Throwable $e) {
    echo "✗ Outbox dispatch MISLUKT: " . $e->getMessage() . "\n";
    echo "  Class: " . get_class($e) . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "  Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
    echo "\n";
}

// Stap 6: Directe SMTP test
echo "--- STAP 6: Directe SMTP test ---\n";
try {
    $host = $smtp['host'] ?? 'localhost';
    $port = (int) ($smtp['port'] ?? 587);
    $encryption = $smtp['encryption'] ?? $smtp['scheme'] ?? null;
    $tls = in_array($encryption, ['tls', 'ssl'], true);
    
    $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
        host: $host,
        port: $port,
        tls: $tls,
    );
    $transport->getStream()->setTimeout(15);
    
    $username = $smtp['username'] ?? '';
    $password = $smtp['password'] ?? '';
    if ($username !== '' && $username !== 'null') {
        $transport->setUsername($username);
    }
    if ($password !== '' && $password !== 'null') {
        $transport->setPassword($password);
    }
    
    $mailer = new \Symfony\Component\Mailer\Mailer($transport);
    
    $email = (new \Symfony\Component\Mime\Email())
        ->from(new \Symfony\Component\Mime\Address($mailConfig['from']['address'] ?? 'noreply@la-vitatrading.nl', $mailConfig['from']['name'] ?? 'LaVita'))
        ->to('gaderrweb@gmail.com')
        ->subject('LaVita Diagnose Test - ' . now()->format('H:i:s'))
        ->text('Dit is een diagnose-test vanuit het LaVita systeem. Als je dit ontvangt, werkt SMTP correct.');
    
    $mailer->send($email);
    $transport->stop();
    
    echo "✓ SMTP test e-mail verstuurd naar gaderrweb@gmail.com\n\n";
} catch (Throwable $e) {
    echo "✗ SMTP MISLUKT: " . $e->getMessage() . "\n";
    echo "  Class: " . get_class($e) . "\n";
    if ($e->getPrevious()) {
        echo "  Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
    echo "\n";
}

// Stap 7: Volledige AccountProvisioningService simulatie
echo "--- STAP 7: Volledige create-keten simulatie ---\n";
try {
    $provService = app(\App\Services\AccountProvisioningService::class);
    echo "✓ AccountProvisioningService geresolved\n";
    echo "  Dependencies:\n";
    echo "    - EmailOutboxService: ✓\n";
    echo "    - EmailTemplateService: ✓\n";
    echo "    - PasswordResetService: ✓\n\n";
} catch (Throwable $e) {
    echo "✗ AccountProvisioningService MISLUKT: " . $e->getMessage() . "\n\n";
}

echo "=== DIAGNOSE COMPLEET ===\n";
echo "⚠️  VERWIJDER DIT BESTAND NA GEBRUIK!\n";
echo "</pre>";
