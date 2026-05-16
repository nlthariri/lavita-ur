# Lokale ontwikkeling — LaVita Laravel Backend

Uitgebreide gids voor het opzetten van een lokale ontwikkelomgeving en het werken aan de codebase.

---

## Inhoudsopgave

1. [Vereisten](#1-vereisten)
2. [Eerste keer instellen](#2-eerste-keer-instellen)
3. [Projectstructuur](#3-projectstructuur)
4. [Werken met de database](#4-werken-met-de-database)
5. [Testen](#5-testen)
6. [Nieuwe feature toevoegen](#6-nieuwe-feature-toevoegen)
7. [Debugging](#7-debugging)
8. [Code-conventies](#8-code-conventies)
9. [Veelvoorkomende fouten](#9-veelvoorkomende-fouten)

---

## 1. Vereisten

Installeer de volgende tools lokaal:

```bash
# PHP 8.3
php -v  # moet 8.3.x tonen

# Composer 2
composer --version

# Node.js 20 (voor Vite / assets, optioneel)
node --version  # moet v20.x tonen

# SQLite (voor tests)
sqlite3 --version

# Git
git --version
```

Optioneel maar handig:

```bash
# MySQL 8 (voor lokale productie-simulatie)
mysql --version

# TablePlus / DBeaver (GUI voor database-inspectie)
```

---

## 2. Eerste keer instellen

```bash
# 1. Clone de repository
git clone https://github.com/uw-org/lavita-ur.git
cd lavita-ur/laravel-rebuild

# 2. PHP-afhankelijkheden
composer install

# 3. Omgeving
cp .env.example .env
php artisan key:generate

# 4. Database (SQLite voor lokaal gebruik)
# .env heeft al DB_CONNECTION=sqlite, geen extra configuratie nodig

# 5. Migraties uitvoeren
php artisan migrate

# 6. Testsuite draaien (validatie dat alles werkt)
php artisan test

# 7. Development server starten
php artisan serve
```

De API is nu bereikbaar op `http://localhost:8000/api/`.

Test snel:

```bash
curl http://localhost:8000/api/health
# Verwacht: {"status":"ok","checks":{"app":"ok","database":"ok"},...}
```

---

## 3. Projectstructuur

```
laravel-rebuild/
├── app/
│   ├── Console/Commands/      # Artisan-commando's (scheduler-taken)
│   ├── Http/
│   │   ├── Controllers/Transitie/
│   │   │   ├── AuthModule/        # Login, MFA, accounts, password-reset
│   │   │   ├── AtwModule/         # ATW-validatie en signalen
│   │   │   ├── AuditModule/       # Auditlog export
│   │   │   ├── EmailFlowsModule/  # E-mail dispatch, templates, maandrapport
│   │   │   ├── ObjectionsModule/  # Bezwaren indienen en beoordelen
│   │   │   ├── ReportsModule/     # PDF/Excel rapporten
│   │   │   ├── SystemModule/      # Health / ready endpoints
│   │   │   └── WorkEntriesModule/ # Uurregistraties
│   │   └── Middleware/
│   │       ├── InternalApiAuth.php    # Bearer-token + MFA-afdwinging
│   │       └── FailClosedThrottle.php # Fail-closed rate-limiting
│   ├── Models/                # Eloquent-modellen
│   ├── Providers/
│   │   └── AppServiceProvider.php  # Rate limiters (auth, mfa, api)
│   └── Services/              # Bedrijfslogica
│       ├── AtwEngine.php          # ATW-berekeningen (pure functie)
│       ├── AtwService.php         # ATW orchestratie
│       ├── AuthMfaService.php     # Login, MFA setup/verify, logout
│       ├── EmailOutboxService.php # Outbox dispatch + audit-keten
│       └── WorkEntriesService.php # Uurregistraties aanmaken/ophalen
├── bootstrap/
│   └── app.php                # Middleware-aliassen, routing-configuratie
├── config/                    # Laravel-configuratiebestanden
├── database/
│   ├── factories/             # Model factories voor testen
│   ├── migrations/            # Database-migraties (chronologisch)
│   └── seeders/               # Optionele seeders
├── docs/                      # Governance-documenten (per iteratie)
├── routes/
│   ├── api.php                # Alle API-routes
│   └── console.php            # Geplande Artisan-taken (scheduler)
├── tests/
│   ├── Feature/               # HTTP-tests (contract-tests)
│   └── Unit/                  # Pure eenheidstests (AtwEngine, TotpService)
├── .env.example               # Template voor .env
├── phpunit.xml                # PHPUnit-configuratie (test-omgeving)
└── composer.json              # PHP-afhankelijkheden
```

---

## 4. Werken met de database

### Lokale SQLite-database

De `.env.example` gebruikt SQLite als standaard. De database-file wordt aangemaakt op `database/database.sqlite`.

```bash
# Database resetten
php artisan migrate:fresh

# Database resetten + seeders draaien (als je seeders hebt)
php artisan migrate:fresh --seed
```

### Migratie aanmaken

```bash
php artisan make:migration add_column_x_to_table_y --table=table_y
```

Naamgevingsconventie: `JJJJ_MM_DD_UUMM_omschrijving.php`

### Modelinspectie via Tinker

```bash
php artisan tinker

# Voorbeelden:
App\Models\User::count()
App\Models\WorkEntry::first()
App\Models\AuthSession::where('revoked_at', null)->count()
```

---

## 5. Testen

### Teststructuur

```
tests/
├── Feature/
│   ├── AuthModuleContractTest.php          # Login, MFA, accounts, recovery codes
│   ├── AtwModuleContractTest.php           # ATW-validatie via HTTP
│   ├── EmailFlowsModuleContractTest.php    # E-mail dispatch, templates
│   ├── EmailEvidenceIntegrityCommandTest.php # Integrity-commando's
│   ├── EvidencePrivilegeVerificationCommandTest.php
│   ├── ObjectionsModuleContractTest.php    # Bezwaren
│   ├── PasswordResetAuditModuleContractTest.php
│   ├── PendingInputReminderCommandTest.php
│   ├── ReportsModuleContractTest.php       # PDF/Excel
│   ├── RetentionCommandTest.php            # Bewaarplicht
│   ├── SystemHealthEndpointsTest.php       # Health/ready
│   └── WorkEntriesModuleContractTest.php   # Uurregistraties
└── Unit/
    ├── AtwEngineTest.php                   # Pure ATW-logica
    ├── TotpServiceTest.php                 # TOTP-generatie
    └── ExampleTest.php
```

### Test draaien

```bash
# Alle tests
php artisan test

# Specifiek bestand
php artisan test --filter=AuthModuleContractTest

# Specifieke test-methode
php artisan test --filter=test_mfa_setup_returns_eight_recovery_codes

# Met coverage (vereist Xdebug of PCOV)
php artisan test --coverage
```

### Nieuwe test schrijven

**Feature-test (HTTP contract-test):**

```php
// tests/Feature/MijnModuleContractTest.php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MijnModuleContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_een_beschrijvende_naam(): void
    {
        // Arrange: maak gebruiker aan
        $token = $this->createBearerToken(['role' => 'owner']);

        // Act: stuur een request
        $response = $this->getWithAuth('/api/internal/work-entries', $token);

        // Assert
        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'count']);
    }
}
```

**Beschikbare TestCase-helpers:**

```php
// Maak een sessie-token voor een gebruiker (roles: owner, manager, employee, boekhouder)
$token = $this->createBearerToken(['role' => 'owner']);
$token = $this->createBearerToken(['role' => 'manager', 'organization_id' => 5]);

// HTTP-verzoeken met auth-header
$response = $this->getWithAuth('/api/internal/work-entries', $token);
$response = $this->postWithAuth('/api/internal/work-entries', $token, ['employee_id' => 1, ...]);
```

**Unit-test (pure logica):**

```php
// tests/Unit/MijnServiceTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\AtwEngine;

class AtwEngineTest extends TestCase
{
    public function test_daily_limit_trigger(): void
    {
        $engine = new AtwEngine();
        $signals = $engine->evaluate(
            ['start_at' => '2026-05-16 06:00', 'end_at' => '2026-05-16 19:00', 'net_minutes' => 720],
            [],
            $this->defaultPolicy()
        );

        $this->assertContains('DAILY_LIMIT', array_column($signals, 'type'));
    }
}
```

---

## 6. Nieuwe feature toevoegen

### Stappenplan

**Stap 1 — Migratie aanmaken** (als nieuw model/tabel):

```bash
php artisan make:migration create_mijn_tabel_table
# Bewerk de migratie in database/migrations/
php artisan migrate
```

**Stap 2 — Model aanmaken:**

```php
// app/Models/MijnModel.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MijnModel extends Model
{
    protected $fillable = ['kolom_a', 'kolom_b'];
    protected $hidden = ['gevoelig_veld'];
}
```

**Stap 3 — Service aanmaken** (bedrijfslogica):

```php
// app/Services/MijnService.php
namespace App\Services;

class MijnService
{
    public function doSomething(array $data): array
    {
        // Logica hier
    }
}
```

**Stap 4 — Controller aanmaken:**

```php
// app/Http/Controllers/Transitie/MijnModule/MijnModuleController.php
namespace App\Http\Controllers\Transitie\MijnModule;

use App\Http\Controllers\Controller;
use App\Services\MijnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MijnModuleController extends Controller
{
    public function __construct(private readonly MijnService $mijnService) {}

    public function getMijnEndpoint(Request $request): JsonResponse
    {
        // Validatie, service-aanroep, response
        return response()->json(['data' => []]);
    }
}
```

**Stap 5 — Route toevoegen** in `routes/api.php`:

```php
Route::get('/internal/mijn-endpoint', [MijnModuleController::class, 'getMijnEndpoint']);
```

**Stap 6 — Tests schrijven** en draaien:

```bash
php artisan test --filter=MijnModuleContractTest
```

---

## 7. Debugging

### Logs bekijken

```bash
# Real-time logs volgen
tail -f storage/logs/laravel.log

# Laatste 50 regels
tail -50 storage/logs/laravel.log
```

### Request debuggen

Voeg tijdelijk toe aan een controller of service:

```php
\Illuminate\Support\Facades\Log::debug('Debug info:', ['data' => $data]);
```

### Queue-jobs lokaal

In `.env` staat `QUEUE_CONNECTION=sync` voor lokale ontwikkeling — jobs worden direct uitgevoerd, niet in de achtergrond.

### Scheduler lokaal testen

```bash
# Lijst van alle geplande taken
php artisan schedule:list

# Commando handmatig uitvoeren
php artisan retention:run
php artisan integrity:email-evidence --fail-on-corruption
```

### Tinker voor snelle tests

```bash
php artisan tinker

# Gebruiker opzoeken
$user = App\Models\User::find(1);
$user->role;

# Service aanroepen
$svc = app(App\Services\AtwService::class);

# Cache wissen
Cache::flush();
```

---

## 8. Code-conventies

### Naamgeving

| Element | Conventie | Voorbeeld |
|---------|-----------|-----------|
| Controller-methode | `{verb}{Module}{Resource}` | `postInternalWorkEntries` |
| Service-methode | camelCase, beschrijvend | `validateProposedShift` |
| Test-methode | `test_{beschrijving_met_underscores}` | `test_mfa_setup_returns_eight_recovery_codes` |
| Migratie | `{datum}_{omschrijving}` | `2026_05_16_130100_create_mfa_recovery_codes_table` |
| Route-naam | REST + intern-prefix | `/api/internal/work-entries` |

### Validatieregels

Valideer altijd in de controller via `$request->validate([...])`. Gebruik nooit `$request->input()` zonder validatie op publieke endpoints.

### Response-structuur

```php
// Succes (lijst)
return response()->json(['data' => $items, 'count' => count($items)]);

// Succes (enkel object)
return response()->json(['status' => 'ok', 'module' => 'MijnModule', ...]);

// Fout (vaste structuur)
return response()->json(['message' => 'Omschrijving van de fout.'], 403);
```

### Services vs. Controllers

- **Services** bevatten alle bedrijfslogica en databasetoegang.
- **Controllers** doen alleen: validatie → service-aanroep → response opbouwen.
- **Nooit** directe DB-queries in controllers.

---

## 9. Veelvoorkomende fouten

### "Target class [...] does not exist"

Voer uit:

```bash
composer dump-autoload
php artisan config:clear
```

### "SQLSTATE[HY000]: General error: 1 no such table"

Migraties zijn niet bijgewerkt:

```bash
php artisan migrate
# of voor een schone start:
php artisan migrate:fresh
```

### Tests mislukken: "RefreshDatabase not rolling back"

Zorg dat de test-klasse `RefreshDatabase` gebruikt:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MijnTest extends TestCase
{
    use RefreshDatabase;
```

### "Unauthenticated" bij tests

Gebruik de `createBearerToken`-helper in TestCase, niet `actingAs()`:

```php
$token = $this->createBearerToken(['role' => 'owner']);
$response = $this->getWithAuth('/api/internal/...', $token);
```

### "MFA verification required" bij tests

Owner/manager-routes vereisen een geverifieerde MFA-sessie. De `createBearerToken`-helper maakt automatisch een MFA-secret aan als de rol `owner` of `manager` is. Controleer de implementatie in `tests/TestCase.php`.

### Cache-problemen

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

---

*Versie: 16 mei 2026*
