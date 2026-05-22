<?php

/*
|--------------------------------------------------------------------------
| Web-routes — LaVita Urenregistratie
|--------------------------------------------------------------------------
|
| Bron: spec `lavita-urenregistratie`, taak 9.5
|   "Registreer web-routes in routes/web.php voor alle auth-schermen
|    (CSRF + sessie)."
|
| Validatie tegen requirements:
|   - requirements.md 6.1  → scherm "Inlog + MFA + QR" op `/inloggen`,
|                            `/auth/mfa-verify`, `/mfa-setup`.
|   - requirements.md 6.11 → scherm "Wachtwoord vergeten/reset" op
|                            `/wachtwoord-vergeten` (request) en
|                            `/wachtwoord-reset` (confirm).
|
| User-flow die deze file aan elkaar lijmt:
|     /inloggen              (Auth\LoginForm, taak 9.1)
|         └── op succesvolle credentials → /auth/mfa-verify?user_id=…
|                  (Auth\MfaVerifyForm, taak 9.2)
|              └── eerste-keer-MFA → /mfa-setup
|                       (Auth\MfaSetupQr, taak 9.3)
|              └── geverifieerde TOTP → /dashboard
|
|     /wachtwoord-vergeten   (Auth\PasswordForgotForm, taak 9.4)
|         └── (timing-safe) altijd dezelfde NL-bevestiging; user
|             ontvangt mail met resetlink naar:
|     /wachtwoord-reset?token=…  (Auth\PasswordResetForm, taak 9.4)
|         └── op succes → /inloggen?reset=ok
|
| Middleware:
|   - Het `web`-middleware-groep (cookies, session, CSRF, share-errors,
|     SubstituteBindings) wordt automatisch op ALLE routes uit dit bestand
|     toegepast door `bootstrap/app.php::withRouting(web: ...)`. We wikkelen
|     de auth-routes desondanks expliciet in `Route::middleware(['web'])
|     ->group(...)` zodat:
|       (a) de intentie "hier is CSRF + sessie verplicht" bij codereview
|           direct zichtbaar is;
|       (b) de routes correct blijven werken indien Laravel ooit besluit
|           het auto-applyen van `web` op `withRouting(web: ...)` aan te
|           passen;
|       (c) de tests in `Tests\Feature\Routes\AuthWebRoutesTest`
|           expliciet kunnen verifiëren dat de `web`-middleware-naam in
|           `gatherMiddleware()` voorkomt.
|     Deze keuze sluit aan bij de patterns in `routes/api.php`, waar elke
|     beveiligde groep ook expliciet `Route::middleware([...])->group(...)`
|     gebruikt voor leesbaarheid.
|
| Taak 9.5 voegt expliciet GEEN auth-guard, "redirect-if-already-
| authenticated"-middleware of session-flash-logica toe — die hoort bij
| latere taken (zie tasks.md 10.x/11.x). De Livewire-componenten zelf
| beheren hun eigen state en redirects.
*/

use App\Livewire\Accounts\AccountsList;
use App\Livewire\Atw\StatusDashboard;
use App\Livewire\Auth\LoginForm;
use App\Livewire\Auth\MfaSetupQr;
use App\Livewire\Auth\MfaVerifyForm;
use App\Livewire\Auth\PasswordForgotForm;
use App\Livewire\Auth\PasswordResetForm;
use App\Livewire\Dashboard\EmployeeHome;
use App\Livewire\Dashboard\ManagerHome;
use App\Livewire\Hours\LeaveCalendar;
use App\Livewire\Hours\LeaveForm;
use App\Livewire\Hours\LeaveOverview;
use App\Livewire\Hours\MyWeek;
use App\Livewire\Hours\WeekOverviewTable;
use App\Livewire\Objections\ObjectionsList;
use App\Livewire\Objections\ReviewForm;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\Reports\Filters;
use App\Livewire\Reports\YearExport;
use App\Livewire\Settings\EmailTemplates;
use App\Livewire\Settings\HolidaysManager;
use App\Livewire\Settings\LeaveTypesManager;
use App\Livewire\Settings\OrganizationSettings;
use App\Livewire\Settings\ProjectsManager;
use App\Livewire\Settings\SettingsOverview;
use App\Livewire\Settings\TeamsManager;
use App\Models\AuthSession;
use Illuminate\Support\Facades\Route;

// Root-route: redirect naar dashboard als ingelogd, anders naar inloggen.
Route::get('/', function () {
    if (session('auth_session_token')) {
        return redirect('/dashboard');
    }

    return redirect('/inloggen');
});

/*
|--------------------------------------------------------------------------
| Auth-schermen (web-stack: CSRF + sessie + cookies)
|--------------------------------------------------------------------------
|
| Volgorde volgt de user-flow van boven naar beneden:
|     login → MFA verify → MFA setup (eerste keer) → forgot → reset.
| Iedere route is een Livewire-3 full-page-component-route — Livewire
| omwikkelt de `#[Layout('layouts.app')]`-attribuut op de component-class
| automatisch met de hoofdlayout uit
| `resources/views/layouts/app.blade.php`.
*/
Route::middleware(['web'])->group(function () {
    // Req 6.1 — e-mail+wachtwoord-stap.
    Route::get('/inloggen', LoginForm::class)->name('login');

    // Req 6.1 — 6-cijferige TOTP-stap (na succesvolle credentials).
    Route::get('/auth/mfa-verify', MfaVerifyForm::class)->name('mfa.verify');

    // Req 6.11 — request-stap "wachtwoord vergeten".
    Route::get('/wachtwoord-vergeten', PasswordForgotForm::class)->name('password.forgot');

    // Req 6.11 — confirm-stap met token uit `?token=…`-query-param.
    Route::get('/wachtwoord-reset', PasswordResetForm::class)->name('password.reset');

    // ─── Beveiligde routes (vereisen actieve sessie) ─────────────────────
    Route::middleware(['auth.session'])->group(function () {
        // Req 6.1 — eerste-keer QR-setup met secret + 8 recovery codes.
        Route::get('/mfa-setup', MfaSetupQr::class)->name('mfa.setup');
        // Req 6.12 — E-mailcycli beheer: owner kan alle 11 templates bekijken en bewerken.
        Route::get('/instellingen/email', EmailTemplates::class)->name('settings.email-templates');

        // Instellingen-overzicht
        Route::get('/instellingen', SettingsOverview::class)->name('settings.index');
        Route::get('/instellingen/organisatie', OrganizationSettings::class)->name('settings.organization');
        Route::get('/instellingen/teams', TeamsManager::class)->name('settings.teams');
        Route::get('/instellingen/projecten', ProjectsManager::class)->name('settings.projects');
        Route::get('/instellingen/feestdagen', HolidaysManager::class)->name('settings.holidays');
        Route::get('/instellingen/verlof-types', LeaveTypesManager::class)->name('settings.leave-types');

        // Profiel
        Route::get('/profiel', ProfilePage::class)->name('profile');

        // Req 6.8, 6.13, 10.1 — Accountbeheer: lijst met zoeken, create/edit, activeren/deactiveren, soft-delete.
        Route::get('/accounts', AccountsList::class)->name('accounts.index');

        // Dashboard (Req 6.9)
        Route::get('/dashboard', ManagerHome::class)->name('dashboard');
        Route::get('/dashboard/medewerker', EmployeeHome::class)->name('dashboard.employee');

        // Uren (Req 6.3, 6.4)
        Route::get('/uren/week', WeekOverviewTable::class)->name('hours.week');
        Route::get('/uren/mijn-week', MyWeek::class)->name('hours.my-week');

        // Verlof (Req 6.10)
        Route::get('/verlof', LeaveForm::class)->name('leave.index');
        Route::get('/verlof/overzicht', LeaveOverview::class)->name('leave.overview');
        Route::get('/verlof/kalender', LeaveCalendar::class)->name('leave.calendar');

        // Bezwaren (Req 6.4, 6.6)
        Route::get('/bezwaren', ObjectionsList::class)->name('objections.index');
        Route::get('/bezwaren/{id}', ReviewForm::class)->name('objections.review')->whereNumber('id');

        // ATW (Req 6.5)
        Route::get('/atw', StatusDashboard::class)->name('atw.dashboard');

        // Rapportages (Req 6.7)
        Route::get('/rapportages', Filters::class)->name('reports.index');
        Route::get('/rapportages/jaaroverzicht', YearExport::class)->name('reports.year');

        // Uitloggen (web-layer)
        Route::post('/uitloggen', function () {
            // Revoke the API session if bearer token exists in cookie/session
            $sessionToken = session('auth_session_token');
            if ($sessionToken) {
                AuthSession::where('session_token_hash', hash('sha256', $sessionToken))
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }

            session()->invalidate();
            session()->regenerateToken();

            return redirect('/inloggen');
        })->name('logout');
    });
});

/*
|--------------------------------------------------------------------------
| Tijdelijke utility-routes (VERWIJDER NA GEBRUIK)
|--------------------------------------------------------------------------
*/
Route::get('/ops/clear-cache', function () {
    if (request()->query('key') !== 'LaVita2026ClearNow') {
        abort(403, 'Ongeldige sleutel.');
    }

    \Illuminate\Support\Facades\Artisan::call('view:clear');
    \Illuminate\Support\Facades\Artisan::call('route:clear');
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    \Illuminate\Support\Facades\Artisan::call('cache:clear');

    return response("✅ Alle caches gewist.\n\nview:clear ✓\nroute:clear ✓\nconfig:clear ✓\ncache:clear ✓", 200, ['Content-Type' => 'text/plain']);
});

Route::get('/ops/migrate', function () {
    if (request()->query('key') !== 'LaVita2026MigrateNow') {
        abort(403, 'Ongeldige sleutel.');
    }

    $output = [];

    // 1. leave_types tabel
    if (!\Illuminate\Support\Facades\Schema::hasTable('leave_types')) {
        \Illuminate\Support\Facades\Schema::create('leave_types', function (\Illuminate\Database\Schema\Blueprint $table) {
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
            $table->foreign('organization_id', 'fk_leave_types_org')->references('id')->on('organizations')->onDelete('cascade');
        });
        $output[] = 'leave_types: AANGEMAAKT';
    } else {
        $output[] = 'leave_types: al aanwezig';
    }

    // 2. users.annual_leave_days
    if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'annual_leave_days')) {
        \Illuminate\Support\Facades\Schema::table('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedSmallInteger('annual_leave_days')->nullable()->after('team_id');
        });
        $output[] = 'annual_leave_days: TOEGEVOEGD';
    } else {
        $output[] = 'annual_leave_days: al aanwezig';
    }

    // 3. work_entries.leave_type_id
    if (!\Illuminate\Support\Facades\Schema::hasColumn('work_entries', 'leave_type_id')) {
        \Illuminate\Support\Facades\Schema::table('work_entries', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedBigInteger('leave_type_id')->nullable()->after('type');
            $table->index('leave_type_id', 'idx_we_leave_type');
            $table->foreign('leave_type_id', 'fk_we_leave_type')->references('id')->on('leave_types')->onDelete('set null');
        });
        $output[] = 'leave_type_id: TOEGEVOEGD';
    } else {
        $output[] = 'leave_type_id: al aanwezig';
    }

    // 4. Seed verlof-types
    $orgs = \Illuminate\Support\Facades\DB::table('organizations')->get();
    $seeded = 0;
    $types = [
        ['code' => 'VAKANTIE', 'name' => 'Vakantieverlof', 'counts_towards_balance' => true],
        ['code' => 'BIJZONDER', 'name' => 'Bijzonder verlof', 'counts_towards_balance' => false],
        ['code' => 'ONBETAALD', 'name' => 'Onbetaald verlof', 'counts_towards_balance' => false],
        ['code' => 'OUDERSCHAP', 'name' => 'Ouderschapsverlof', 'counts_towards_balance' => false],
    ];
    foreach ($orgs as $org) {
        foreach ($types as $t) {
            if (!\Illuminate\Support\Facades\DB::table('leave_types')->where('organization_id', $org->id)->where('code', $t['code'])->exists()) {
                \Illuminate\Support\Facades\DB::table('leave_types')->insert(['organization_id' => $org->id, 'code' => $t['code'], 'name' => $t['name'], 'counts_towards_balance' => $t['counts_towards_balance'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $seeded++;
            }
        }
    }
    $output[] = "seed: {$seeded} types aangemaakt";

    return response("✅ Migraties voltooid.\n\n" . implode("\n", $output), 200, ['Content-Type' => 'text/plain']);
});
