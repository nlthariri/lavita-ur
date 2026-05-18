<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * LaVita Urenregistratie — Web Installer
 *
 * Dit script begeleidt de eerste installatie van de applicatie.
 * Na succesvolle installatie kan de /install map worden verwijderd.
 *
 * Stappen:
 * 1. Systeemvereisten controleren
 * 2. Database-configuratie
 * 3. Database-migratie uitvoeren
 * 4. Eerste organisatie + owner-account aanmaken
 * 5. Applicatie-instellingen configureren
 * 6. Installatie afronden
 */

// Voorkom toegang als de app al geïnstalleerd is
$envFile = dirname(__DIR__, 2).'/.env';
$lockFile = dirname(__DIR__, 2).'/storage/installed.lock';

if (file_exists($lockFile)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Al geïnstalleerd</title></head><body>';
    echo '<h1>LaVita is al geïnstalleerd</h1>';
    echo '<p>Verwijder de map <code>/public/install</code> voor de veiligheid.</p>';
    echo '</body></html>';
    exit;
}

// Bepaal de huidige stap
$step = isset($_GET['step']) ? (int) $_GET['step'] : 1;
$error = '';
$success = '';

// Voorkom stap-skipping: stap 3+ vereist dat .env bestaat
if ($step >= 3 && ! file_exists($envFile)) {
    $step = 2;
    $error = 'Configuratie nog niet voltooid. Doorloop eerst stap 2.';
}

// Stap 4+ vereist dat migraties zijn uitgevoerd (sessions tabel moet bestaan)
if ($step >= 4 && ! isset($_SESSION['migrate_output']) && $step !== 6) {
    // Controleer of de database al tabellen heeft
    if (file_exists($envFile)) {
        try {
            bootstrapLaravel();
            $tableCount = DB::select('SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE()')[0]->cnt ?? 0;
            if ($tableCount < 10) {
                $step = 3;
                $error = 'Database-migratie nog niet uitgevoerd. Doorloop eerst stap 3.';
            }
        } catch (Throwable) {
            // Als we niet kunnen verbinden, terug naar stap 2
            $step = 2;
            $error = 'Database-verbinding mislukt. Controleer de configuratie.';
        }
    }
}

// Bootstrap Laravel voor stappen die het nodig hebben
$laravelBooted = false;
function bootstrapLaravel(): void
{
    global $laravelBooted;
    if ($laravelBooted) {
        return;
    }
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $laravelBooted = true;
    }
}

// CSRF-token genereren
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="'.htmlspecialchars($_SESSION['csrf_token']).'">';
}

function verifyCsrf(): bool
{
    return isset($_POST['_csrf']) && hash_equals($_SESSION['csrf_token'], $_POST['_csrf']);
}

// Verwerk POST-acties
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (! verifyCsrf()) {
        $error = 'Ongeldige CSRF-token. Ververs de pagina.';
    } else {
        switch ($step) {
            case 2:
                // Database-configuratie opslaan
                $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
                $dbPort = trim($_POST['db_port'] ?? '3306');
                $dbName = trim($_POST['db_name'] ?? 'lavita_ur');
                $dbUser = trim($_POST['db_user'] ?? 'root');
                $dbPass = $_POST['db_pass'] ?? '';
                $appUrl = trim($_POST['app_url'] ?? 'https://lavita.nl');
                $mailHost = trim($_POST['mail_host'] ?? '127.0.0.1');
                $mailPort = trim($_POST['mail_port'] ?? '587');
                $mailUser = trim($_POST['mail_user'] ?? '');
                $mailPass = $_POST['mail_pass'] ?? '';
                $mailFrom = trim($_POST['mail_from'] ?? 'noreply@lavita.nl');

                // Test database-verbinding
                try {
                    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName}";
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    $pdo->query('SELECT 1');
                } catch (PDOException $e) {
                    $error = 'Database-verbinding mislukt: '.htmlspecialchars($e->getMessage());
                    break;
                }

                // Genereer APP_KEY
                $appKey = 'base64:'.base64_encode(random_bytes(32));

                // Schrijf .env — escape speciale tekens in wachtwoorden
                $escapedDbPass = addcslashes($dbPass, '"\\$');
                $escapedMailPass = addcslashes($mailPass, '"\\$');
                $escapedMailFrom = addcslashes($mailFrom, '"\\$');

                $envContent = <<<ENV
APP_NAME="LaVita Urenregistratie"
APP_ENV=production
APP_DEBUG=false
APP_URL={$appUrl}
APP_KEY={$appKey}
APP_PREVIOUS_KEYS=
APP_TIMEZONE=Europe/Amsterdam
APP_LOCALE=nl

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_DATABASE={$dbName}
DB_USERNAME={$dbUser}
DB_PASSWORD="{$escapedDbPass}"

MAIL_MAILER=smtp
MAIL_HOST={$mailHost}
MAIL_PORT={$mailPort}
MAIL_USERNAME="{$mailUser}"
MAIL_PASSWORD="{$escapedMailPass}"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="{$escapedMailFrom}"
MAIL_FROM_NAME="\${APP_NAME}"

QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

BACKUP_ARCHIVE_PASSWORD=
ENV;

                file_put_contents($envFile, $envContent);
                header('Location: ?step=3');
                exit;
                break;

            case 3:
                // Migraties uitvoeren
                bootstrapLaravel();

                try {
                    Artisan::call('migrate', ['--force' => true]);
                    $output = Artisan::output();
                    $_SESSION['migrate_output'] = $output;
                    header('Location: ?step=4');
                    exit;
                } catch (Throwable $e) {
                    $error = 'Migratie mislukt: '.htmlspecialchars($e->getMessage());
                }
                break;

            case 4:
                // Eerste organisatie + owner aanmaken
                bootstrapLaravel();
                $orgName = trim($_POST['org_name'] ?? '');
                $orgKvk = trim($_POST['org_kvk'] ?? '');
                $ownerName = trim($_POST['owner_name'] ?? '');
                $ownerEmail = trim($_POST['owner_email'] ?? '');
                $ownerPassword = $_POST['owner_password'] ?? '';
                $ownerPasswordConfirm = $_POST['owner_password_confirm'] ?? '';

                if ($orgName === '' || $ownerName === '' || $ownerEmail === '' || $ownerPassword === '') {
                    $error = 'Alle verplichte velden moeten worden ingevuld.';
                    break;
                }

                if (strlen($ownerPassword) < 12) {
                    $error = 'Wachtwoord moet minimaal 12 tekens lang zijn.';
                    break;
                }

                if ($ownerPassword !== $ownerPasswordConfirm) {
                    $error = 'Wachtwoorden komen niet overeen.';
                    break;
                }

                try {
                    $org = Organization::create([
                        'name' => $orgName,
                        'kvk_number' => $orgKvk ?: null,
                        'retention_years' => 7,
                        'pending_input_threshold_days' => 3,
                        'atw_daily_max_minutes' => 720,
                        'atw_weekly_max_minutes' => 3600,
                        'atw_weekly_warning_minutes' => 2880,
                        'atw_average_16_week_minutes' => 2880,
                    ]);

                    $user = User::create([
                        'name' => $ownerName,
                        'full_name' => $ownerName,
                        'email' => strtolower($ownerEmail),
                        'password' => Hash::make($ownerPassword),
                        'organization_id' => $org->id,
                        'role' => 'owner',
                        'is_active' => true,
                    ]);

                    $_SESSION['owner_id'] = $user->id;
                    $_SESSION['org_id'] = $org->id;

                    header('Location: ?step=5');
                    exit;
                } catch (Throwable $e) {
                    $error = 'Account aanmaken mislukt: '.htmlspecialchars($e->getMessage());
                }
                break;

            case 5:
                // Cache opwarmen + installatie afronden
                bootstrapLaravel();

                try {
                    Artisan::call('config:cache');
                    Artisan::call('route:cache');
                    Artisan::call('view:cache');
                    Artisan::call('storage:link');

                    // Lock-bestand aanmaken
                    file_put_contents($lockFile, json_encode([
                        'installed_at' => date('c'),
                        'version' => '1.0.0',
                        'php_version' => PHP_VERSION,
                    ]));

                    header('Location: ?step=6');
                    exit;
                } catch (Throwable $e) {
                    $error = 'Afronden mislukt: '.htmlspecialchars($e->getMessage());
                }
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LaVita Installatie — Stap <?= $step ?>/6</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; line-height: 1.6; }
        .container { max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 2rem; margin-bottom: 1.5rem; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #059669; }
        h2 { font-size: 1.2rem; margin-bottom: 1rem; color: #374151; }
        .steps { display: flex; gap: 0.5rem; margin-bottom: 2rem; }
        .step-dot { width: 2rem; height: 2rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: bold; }
        .step-dot.active { background: #059669; color: white; }
        .step-dot.done { background: #10b981; color: white; }
        .step-dot.pending { background: #e5e7eb; color: #6b7280; }
        label { display: block; font-weight: 500; margin-bottom: 0.25rem; font-size: 0.875rem; }
        input[type="text"], input[type="email"], input[type="password"], input[type="number"] {
            width: 100%; padding: 0.625rem 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px;
            font-size: 0.9rem; margin-bottom: 1rem; transition: border-color 0.2s;
        }
        input:focus { outline: none; border-color: #059669; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #059669; color: white; border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #047857; }
        .btn-secondary { background: #6b7280; }
        .btn-secondary:hover { background: #4b5563; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; }
        .check { color: #059669; } .cross { color: #dc2626; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        code { background: #f1f5f9; padding: 0.125rem 0.375rem; border-radius: 4px; font-size: 0.8rem; }
        .hint { font-size: 0.8rem; color: #6b7280; margin-top: -0.75rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>🌿 LaVita Urenregistratie</h1>
        <p style="color: #6b7280; margin-bottom: 1rem;">Installatiewizard</p>

        <div class="steps">
            <?php for ($i = 1; $i <= 6; $i++) { ?>
                <div class="step-dot <?= $i < $step ? 'done' : ($i === $step ? 'active' : 'pending') ?>">
                    <?= $i < $step ? '✓' : $i ?>
                </div>
            <?php } ?>
        </div>

        <?php if ($error) { ?>
            <div class="error"><?= $error ?></div>
        <?php } ?>

        <?php if ($step === 1) { ?>
            <h2>Stap 1: Systeemvereisten</h2>
            <?php
            $checks = [
                'PHP versie ≥ 8.3' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'PDO MySQL extensie' => extension_loaded('pdo_mysql'),
                'OpenSSL extensie' => extension_loaded('openssl'),
                'Mbstring extensie' => extension_loaded('mbstring'),
                'Tokenizer extensie' => extension_loaded('tokenizer'),
                'XML extensie' => extension_loaded('xml'),
                'Ctype extensie' => extension_loaded('ctype'),
                'JSON extensie' => extension_loaded('json'),
                'BCMath extensie' => extension_loaded('bcmath'),
                'Fileinfo extensie' => extension_loaded('fileinfo'),
                'GD extensie (PDF)' => extension_loaded('gd'),
                'Zip extensie (backup)' => extension_loaded('zip'),
                'storage/ schrijfbaar' => is_writable(dirname(__DIR__, 2).'/storage'),
                'bootstrap/cache/ schrijfbaar' => is_writable(dirname(__DIR__, 2).'/bootstrap/cache'),
                'vendor/ bestaat' => is_dir(dirname(__DIR__, 2).'/vendor'),
            ];
            $allPassed = ! in_array(false, $checks, true);
            ?>
            <?php foreach ($checks as $label => $passed) { ?>
                <p><span class="<?= $passed ? 'check' : 'cross' ?>"><?= $passed ? '✓' : '✗' ?></span> <?= htmlspecialchars($label) ?> <small>(<?= $passed ? 'OK' : 'ONTBREEKT' ?>)</small></p>
            <?php } ?>

            <br>
            <?php if ($allPassed) { ?>
                <div class="success">Alle vereisten zijn voldaan!</div>
                <a href="?step=2" class="btn">Volgende →</a>
            <?php } else { ?>
                <div class="error">Niet alle vereisten zijn voldaan. Los de ontbrekende items op en ververs deze pagina.</div>
            <?php } ?>

        <?php } elseif ($step === 2) { ?>
            <h2>Stap 2: Database & E-mail configuratie</h2>
            <form method="POST">
                <?= csrfField() ?>
                <h3 style="margin-bottom: 0.5rem; font-size: 1rem;">Database (MySQL)</h3>
                <div class="info">Maak eerst een lege MySQL-database aan via je hostingpaneel (bijv. Plesk).</div>
                <div class="grid-2">
                    <div><label for="db_host">Host</label><input type="text" id="db_host" name="db_host" value="127.0.0.1"></div>
                    <div><label for="db_port">Poort</label><input type="number" id="db_port" name="db_port" value="3306"></div>
                </div>
                <label for="db_name">Database naam</label>
                <input type="text" id="db_name" name="db_name" value="lavita_ur" required>
                <div class="grid-2">
                    <div><label for="db_user">Gebruikersnaam</label><input type="text" id="db_user" name="db_user" value="root" required></div>
                    <div><label for="db_pass">Wachtwoord</label><input type="password" id="db_pass" name="db_pass"></div>
                </div>

                <h3 style="margin: 1.5rem 0 0.5rem; font-size: 1rem;">Applicatie</h3>
                <label for="app_url">Applicatie-URL</label>
                <input type="text" id="app_url" name="app_url" value="https://lavita.nl" required>
                <p class="hint">Inclusief https://, zonder trailing slash.</p>

                <h3 style="margin: 1.5rem 0 0.5rem; font-size: 1rem;">E-mail (SMTP)</h3>
                <div class="grid-2">
                    <div><label for="mail_host">SMTP Host</label><input type="text" id="mail_host" name="mail_host" value="smtp.example.com"></div>
                    <div><label for="mail_port">SMTP Poort</label><input type="number" id="mail_port" name="mail_port" value="587"></div>
                </div>
                <div class="grid-2">
                    <div><label for="mail_user">SMTP Gebruiker</label><input type="text" id="mail_user" name="mail_user"></div>
                    <div><label for="mail_pass">SMTP Wachtwoord</label><input type="password" id="mail_pass" name="mail_pass"></div>
                </div>
                <label for="mail_from">Afzender e-mail</label>
                <input type="email" id="mail_from" name="mail_from" value="noreply@lavita.nl" required>

                <br><button type="submit" class="btn">Verbinding testen & opslaan →</button>
            </form>

        <?php } elseif ($step === 3) { ?>
            <h2>Stap 3: Database-migratie</h2>
            <p>De database-tabellen worden nu aangemaakt. Dit kan enkele seconden duren.</p>
            <br>
            <form method="POST">
                <?= csrfField() ?>
                <button type="submit" class="btn">Migraties uitvoeren →</button>
            </form>

        <?php } elseif ($step === 4) { ?>
            <h2>Stap 4: Organisatie & Eigenaar-account</h2>
            <?php if (! empty($_SESSION['migrate_output'])) { ?>
                <div class="success">Database-migratie succesvol afgerond!</div>
            <?php } ?>
            <form method="POST">
                <?= csrfField() ?>
                <h3 style="margin-bottom: 0.5rem; font-size: 1rem;">Organisatie</h3>
                <label for="org_name">Organisatienaam *</label>
                <input type="text" id="org_name" name="org_name" required placeholder="Bijv. La Vita BV">
                <label for="org_kvk">KvK-nummer</label>
                <input type="text" id="org_kvk" name="org_kvk" placeholder="Optioneel">

                <h3 style="margin: 1.5rem 0 0.5rem; font-size: 1rem;">Eigenaar-account (admin)</h3>
                <div class="info">Dit wordt het eerste account met volledige beheerdersrechten. Na installatie kunt u MFA instellen.</div>
                <label for="owner_name">Naam *</label>
                <input type="text" id="owner_name" name="owner_name" required>
                <label for="owner_email">E-mailadres *</label>
                <input type="email" id="owner_email" name="owner_email" required>
                <label for="owner_password">Wachtwoord * (min. 12 tekens)</label>
                <input type="password" id="owner_password" name="owner_password" required minlength="12">
                <label for="owner_password_confirm">Wachtwoord bevestigen *</label>
                <input type="password" id="owner_password_confirm" name="owner_password_confirm" required minlength="12">

                <br><button type="submit" class="btn">Account aanmaken →</button>
            </form>

        <?php } elseif ($step === 5) { ?>
            <h2>Stap 5: Installatie afronden</h2>
            <div class="success">Organisatie en eigenaar-account zijn aangemaakt!</div>
            <p>De volgende acties worden nu uitgevoerd:</p>
            <ul style="margin: 1rem 0; padding-left: 1.5rem;">
                <li>Configuratie-cache opwarmen</li>
                <li>Route-cache opwarmen</li>
                <li>View-cache opwarmen</li>
                <li>Storage-link aanmaken</li>
                <li>Installatie-lock aanmaken</li>
            </ul>
            <form method="POST">
                <?= csrfField() ?>
                <button type="submit" class="btn">Afronden →</button>
            </form>

        <?php } elseif ($step === 6) { ?>
            <h2>✅ Installatie voltooid!</h2>
            <div class="success">LaVita Urenregistratie is succesvol geïnstalleerd.</div>

            <h3 style="margin: 1rem 0 0.5rem; font-size: 1rem;">Volgende stappen:</h3>
            <ol style="padding-left: 1.5rem; margin-bottom: 1.5rem;">
                <li><strong>MFA instellen</strong> — Log in en stel MFA in via <code>/mfa-setup</code></li>
                <li><strong>Crontab instellen</strong> — Voeg toe aan crontab:<br>
                    <code style="display: block; margin: 0.5rem 0; padding: 0.5rem; background: #1e293b; color: #e2e8f0; border-radius: 4px;">* * * * * cd /pad/naar/laravel-rebuild && php artisan schedule:run >> /dev/null 2>&1</code>
                </li>
                <li><strong>Queue worker</strong> — Start de queue worker (of configureer als Plesk-taak):<br>
                    <code style="display: block; margin: 0.5rem 0; padding: 0.5rem; background: #1e293b; color: #e2e8f0; border-radius: 4px;">php artisan queue:work --sleep=3 --tries=3 --max-time=3600</code>
                </li>
                <li><strong>Backup-wachtwoord</strong> — Stel <code>BACKUP_ARCHIVE_PASSWORD</code> in de <code>.env</code> in</li>
                <li><strong>/install verwijderen</strong> — Verwijder de map <code>public/install/</code> voor de veiligheid</li>
            </ol>

            <div class="info">
                <strong>Beveiligingswaarschuwing:</strong> Verwijder de map <code>public/install/</code> direct na het lezen van deze instructies.
                Zolang deze map bestaat, is de installer toegankelijk (hoewel geblokkeerd door het lock-bestand).
            </div>

            <a href="/" class="btn">Naar de applicatie →</a>
        <?php } ?>
    </div>

    <p style="text-align: center; color: #9ca3af; font-size: 0.8rem;">
        LaVita Urenregistratie v1.0 — Installatie
    </p>
</div>
</body>
</html>
