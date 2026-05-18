<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {--disk=local}';

    protected $description = 'Verifieer de meest recente backup: decrypt-test + SHA-256 manifest check.';

    public function handle(AuditService $auditService): int
    {
        $disk = $this->option('disk');
        $this->info('Backup-integriteitscheck gestart...');

        try {
            $backupPath = $this->findLatestBackup($disk);

            if ($backupPath === null) {
                $this->recordFailure('Geen backup gevonden.', $auditService);

                return self::FAILURE;
            }

            $this->info("Laatste backup: {$backupPath}");

            // Stap 1: Decrypt-test — controleer of het archief geopend kan worden
            if (! $this->verifyDecrypt($disk, $backupPath)) {
                $this->recordFailure("Decrypt-test mislukt voor: {$backupPath}", $auditService);

                return self::FAILURE;
            }

            $this->info('✓ Decrypt-test geslaagd.');

            // Stap 2: SHA-256 manifest check — controleer integriteit van het bestand
            if (! $this->verifySha256Manifest($disk, $backupPath)) {
                $this->recordFailure("SHA-256 manifest check mislukt voor: {$backupPath}", $auditService);

                return self::FAILURE;
            }

            $this->info('✓ SHA-256 manifest check geslaagd.');
            $this->info('Backup-integriteitscheck voltooid: PASS');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->recordFailure("Onverwachte fout: {$e->getMessage()}", $auditService);

            return self::FAILURE;
        }
    }

    /**
     * Zoek het meest recente backup-bestand op de opgegeven disk.
     */
    private function findLatestBackup(string $disk): ?string
    {
        $storage = Storage::disk($disk);
        $backupName = config('backup.backup.name', 'lavita-urenregistratie');
        $backupDir = $backupName;

        if (! $storage->exists($backupDir)) {
            return null;
        }

        $files = collect($storage->allFiles($backupDir))
            ->filter(fn (string $file) => str_ends_with($file, '.zip'))
            ->sortDesc()
            ->values();

        return $files->first();
    }

    /**
     * Verifieer dat het backup-archief gedecrypt kan worden.
     * Controleert of het ZIP-bestand gelezen kan worden met het geconfigureerde wachtwoord.
     */
    private function verifyDecrypt(string $disk, string $backupPath): bool
    {
        $storage = Storage::disk($disk);
        $fullPath = $storage->path($backupPath);

        if (! file_exists($fullPath)) {
            $this->error("Backup-bestand niet gevonden op schijf: {$fullPath}");

            return false;
        }

        $password = config('backup.backup.password');

        // Probeer het ZIP-archief te openen
        $zip = new \ZipArchive;
        $result = $zip->open($fullPath);

        if ($result !== true) {
            $this->error("Kan ZIP-archief niet openen (code: {$result})");

            return false;
        }

        // Als er een wachtwoord is geconfigureerd, probeer een bestand te lezen
        if ($password) {
            $zip->setPassword($password);

            // Probeer het eerste bestand te lezen als decrypt-test
            if ($zip->numFiles > 0) {
                $firstFile = $zip->getNameIndex(0);
                $content = $zip->getFromName($firstFile);

                if ($content === false) {
                    $zip->close();
                    $this->error('Decrypt mislukt: kan bestanden niet lezen met geconfigureerd wachtwoord.');

                    return false;
                }
            }
        }

        $zip->close();

        return true;
    }

    /**
     * Verifieer de SHA-256 integriteit van het backup-bestand.
     * Berekent de hash en vergelijkt met een opgeslagen manifest.
     */
    private function verifySha256Manifest(string $disk, string $backupPath): bool
    {
        $storage = Storage::disk($disk);
        $fullPath = $storage->path($backupPath);

        if (! file_exists($fullPath)) {
            return false;
        }

        // Bereken SHA-256 hash van het backup-bestand
        $currentHash = hash_file('sha256', $fullPath);

        if ($currentHash === false) {
            $this->error('Kan SHA-256 hash niet berekenen.');

            return false;
        }

        // Controleer of er een manifest-bestand bestaat
        $manifestPath = $backupPath.'.sha256';

        if ($storage->exists($manifestPath)) {
            // Vergelijk met opgeslagen hash
            $storedHash = trim($storage->get($manifestPath));

            if ($currentHash !== $storedHash) {
                $this->error("SHA-256 mismatch! Verwacht: {$storedHash}, Berekend: {$currentHash}");

                return false;
            }
        } else {
            // Maak manifest aan voor toekomstige verificaties
            $storage->put($manifestPath, $currentHash);
            $this->info("SHA-256 manifest aangemaakt: {$manifestPath}");
        }

        // Controleer bestandsgrootte (niet 0 bytes)
        $fileSize = filesize($fullPath);

        if ($fileSize === 0) {
            $this->error('Backup-bestand is leeg (0 bytes).');

            return false;
        }

        $this->line('  Bestandsgrootte: '.number_format($fileSize / 1024 / 1024, 2).' MB');
        $this->line("  SHA-256: {$currentHash}");

        return true;
    }

    /**
     * Registreer een mislukte integriteitscheck: audit-event + alert-mail.
     *
     * Hernoemd van `fail()` naar `recordFailure()` omdat Laravel 13's
     * `Illuminate\Console\Command` een `public fail()` methode definieert
     * die niet overschreven mag worden met een andere signature.
     */
    private function recordFailure(string $reason, AuditService $auditService): void
    {
        $this->error("FAIL: {$reason}");

        // Schrijf audit-event
        $auditService->record([
            'organization_id' => null,
            'actor_id' => null,
            'action' => 'BACKUP_INTEGRITY_FAILED',
            'target_type' => 'backup',
            'target_id' => 'latest',
            'after_data' => json_encode(['reason' => $reason, 'timestamp' => now()->toIso8601String()]),
        ]);

        // Verstuur alert-mail
        $alertEmail = config('backup.notifications.mail.to', 'admin@lavita.nl');

        try {
            Mail::raw(
                "⚠️ Backup-integriteitscheck MISLUKT\n\n"
                ."Reden: {$reason}\n"
                .'Tijdstip: '.now()->format('Y-m-d H:i:s')."\n"
                .'Server: '.gethostname()."\n\n"
                .'Actie vereist: controleer de backup-configuratie en voer handmatig een backup:run uit.',
                function ($message) use ($alertEmail) {
                    $message->to($alertEmail)
                        ->subject('[ALERT] LaVita Backup-integriteitscheck mislukt');
                }
            );
        } catch (\Throwable $e) {
            $this->error("Kon alert-mail niet versturen: {$e->getMessage()}");
        }
    }
}
