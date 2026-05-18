<?php

/**
 * Verwerk de e-mail outbox queue (verstuur gequeude mails).
 *
 * Bezoek: https://ur.la-vitatrading.nl/process-email-queue.php?token=lavita-queue-2026
 * 
 * Dit script moet periodiek worden aangeroepen (via cron of handmatig)
 * om gequeude e-mails daadwerkelijk te versturen via SMTP.
 *
 * VERWIJDER NA OPZETTEN VAN CRON!
 */

$token = 'lavita-queue-2026';

if (($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    echo 'Toegang geweigerd.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\EmailOutboxService;

echo "<pre>\n";
echo "=== E-mail Queue Verwerking ===\n";
echo "Datum: " . now()->format('Y-m-d H:i:s') . "\n\n";

try {
    $outboxService = app(EmailOutboxService::class);
    $result = $outboxService->processBatch();
    
    echo "Resultaat:\n";
    echo "  Verstuurd: {$result['sent']}\n";
    echo "  Mislukt: {$result['failed']}\n";
    echo "  Totaal verwerkt: {$result['processed']}\n\n";
    
    if ($result['sent'] > 0) {
        echo "✓ {$result['sent']} e-mail(s) succesvol verstuurd!\n";
    }
    
    if ($result['failed'] > 0) {
        echo "⚠ {$result['failed']} e-mail(s) mislukt (worden later opnieuw geprobeerd).\n";
        
        // Toon de foutmeldingen
        $failedItems = \App\Models\EmailOutbox::where('status', 'retrying')
            ->orWhere('status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'recipient', 'subject', 'status', 'error_message', 'retry_count']);
        
        echo "\nMislukte items:\n";
        foreach ($failedItems as $item) {
            echo "  ID {$item->id}: {$item->recipient} — {$item->status} (poging {$item->retry_count})\n";
            echo "    Fout: {$item->error_message}\n\n";
        }
    }
    
    if ($result['processed'] === 0) {
        echo "Geen e-mails in de wachtrij.\n";
    }
    
} catch (Throwable $e) {
    echo "✗ FOUT: " . $e->getMessage() . "\n";
    echo "  " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== KLAAR ===\n";
echo "</pre>";
