<?php
/**
 * Eenmalig migratiescript — voer uit via browser:
 * https://ur.la-vitatrading.nl/run-migrations-2026-05-19.php?key=LaVita2026MigrateNow
 *
 * Voert de 3 ontbrekende migraties uit:
 *  1. create_leave_types_table
 *  2. add_annual_leave_days_to_users
 *  3. add_leave_type_id_to_work_entries
 *
 * VERWIJDER DIT BESTAND NA UITVOERING.
 */

// Beveiligingssleutel — voorkomt ongeautoriseerde uitvoering
$expectedKey = 'LaVita2026MigrateNow';

if (($_GET['key'] ?? '') !== $expectedKey) {
    http_response_code(403);
    die('Forbidden. Gebruik ?key=' . $expectedKey);
}

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(\Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

header('Content-Type: text/plain; charset=utf-8');

echo "=== La Vita Urenregistratie — Migratiescript 2026-05-19 ===\n\n";

$results = [];

// --- Migratie 1: create_leave_types_table ---
echo "[1/3] Controleer leave_types tabel...\n";
if (Schema::hasTable('leave_types')) {
    echo "  ✓ Tabel 'leave_types' bestaat al. Overgeslagen.\n\n";
    $results[] = 'leave_types: al aanwezig';
} else {
    echo "  → Aanmaken tabel 'leave_types'...\n";
    try {
        Schema::create('leave_types', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('max_days_per_year')->nullable();
            $table->boolean('counts_towards_balance')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'uq_leave_types_org_code');
            $table->index(['organization_id', 'is_active'], 'idx_leave_types_org_active');

            $table->foreign('organization_id', 'fk_leave_types_org')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
        });
        echo "  ✓ Tabel 'leave_types' aangemaakt.\n\n";
        $results[] = 'leave_types: AANGEMAAKT';
    } catch (\Throwable $e) {
        echo "  ✗ FOUT: " . $e->getMessage() . "\n\n";
        $results[] = 'leave_types: FOUT - ' . $e->getMessage();
    }
}

// --- Migratie 2: add_annual_leave_days_to_users ---
echo "[2/3] Controleer users.annual_leave_days kolom...\n";
if (Schema::hasColumn('users', 'annual_leave_days')) {
    echo "  ✓ Kolom 'users.annual_leave_days' bestaat al. Overgeslagen.\n\n";
    $results[] = 'annual_leave_days: al aanwezig';
} else {
    echo "  → Toevoegen kolom 'annual_leave_days' aan users...\n";
    try {
        Schema::table('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedSmallInteger('annual_leave_days')
                ->nullable()
                ->after('team_id')
                ->comment('Jaarlijks verlofrecht in dagen');
        });
        echo "  ✓ Kolom 'users.annual_leave_days' toegevoegd.\n\n";
        $results[] = 'annual_leave_days: TOEGEVOEGD';
    } catch (\Throwable $e) {
        echo "  ✗ FOUT: " . $e->getMessage() . "\n\n";
        $results[] = 'annual_leave_days: FOUT - ' . $e->getMessage();
    }
}

// --- Migratie 3: add_leave_type_id_to_work_entries ---
echo "[3/3] Controleer work_entries.leave_type_id kolom...\n";
if (Schema::hasColumn('work_entries', 'leave_type_id')) {
    echo "  ✓ Kolom 'work_entries.leave_type_id' bestaat al. Overgeslagen.\n\n";
    $results[] = 'leave_type_id: al aanwezig';
} else {
    echo "  → Toevoegen kolom 'leave_type_id' aan work_entries...\n";
    try {
        Schema::table('work_entries', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedBigInteger('leave_type_id')
                ->nullable()
                ->after('type');

            $table->index('leave_type_id', 'idx_we_leave_type');

            $table->foreign('leave_type_id', 'fk_we_leave_type')
                ->references('id')
                ->on('leave_types')
                ->onDelete('set null');
        });
        echo "  ✓ Kolom 'work_entries.leave_type_id' toegevoegd met FK en index.\n\n";
        $results[] = 'leave_type_id: TOEGEVOEGD';
    } catch (\Throwable $e) {
        echo "  ✗ FOUT: " . $e->getMessage() . "\n\n";
        $results[] = 'leave_type_id: FOUT - ' . $e->getMessage();
    }
}

// --- Seed standaard verlof-types ---
echo "[BONUS] Seed standaard verlof-types voor alle organisaties...\n";
try {
    $organizations = DB::table('organizations')->get();
    $seeded = 0;

    $defaultTypes = [
        ['code' => 'VAKANTIE', 'name' => 'Vakantieverlof', 'counts_towards_balance' => true],
        ['code' => 'BIJZONDER', 'name' => 'Bijzonder verlof', 'counts_towards_balance' => false],
        ['code' => 'ONBETAALD', 'name' => 'Onbetaald verlof', 'counts_towards_balance' => false],
        ['code' => 'OUDERSCHAP', 'name' => 'Ouderschapsverlof', 'counts_towards_balance' => false],
    ];

    foreach ($organizations as $org) {
        foreach ($defaultTypes as $type) {
            $exists = DB::table('leave_types')
                ->where('organization_id', $org->id)
                ->where('code', $type['code'])
                ->exists();

            if (!$exists) {
                DB::table('leave_types')->insert([
                    'organization_id' => $org->id,
                    'code' => $type['code'],
                    'name' => $type['name'],
                    'counts_towards_balance' => $type['counts_towards_balance'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $seeded++;
            }
        }
    }
    echo "  ✓ {$seeded} verlof-types geseeded.\n\n";
    $results[] = "seed: {$seeded} types aangemaakt";
} catch (\Throwable $e) {
    echo "  ✗ FOUT bij seeden: " . $e->getMessage() . "\n\n";
    $results[] = 'seed: FOUT - ' . $e->getMessage();
}

// --- Samenvatting ---
echo "=== SAMENVATTING ===\n";
foreach ($results as $r) {
    echo "  • {$r}\n";
}
echo "\n✅ Script voltooid. VERWIJDER DIT BESTAND NU.\n";
echo "   Pad: public/run-migrations-2026-05-19.php\n";
