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
