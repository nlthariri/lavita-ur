<?php

use App\Http\Controllers\Transitie\AccountsModule\AccountsModuleController;
use App\Http\Controllers\Transitie\AtwModule\AtwModuleController;
use App\Http\Controllers\Transitie\AuditModule\AuditModuleController;
use App\Http\Controllers\Transitie\AuthModule\AuthModuleController;
use App\Http\Controllers\Transitie\AuthModule\PasswordResetController;
use App\Http\Controllers\Transitie\CostCentersModule\CostCentersModuleController;
use App\Http\Controllers\Transitie\EmailFlowsModule\EmailFlowsModuleController;
use App\Http\Controllers\Transitie\HolidaysModule\HolidaysModuleController;
use App\Http\Controllers\Transitie\ObjectionsModule\ObjectionsModuleController;
use App\Http\Controllers\Transitie\ProjectsModule\ProjectsModuleController;
use App\Http\Controllers\Transitie\ReportsModule\ReportsModuleController;
use App\Http\Controllers\Transitie\SystemModule\HealthController;
use App\Http\Controllers\Transitie\WorkEntriesModule\WorkEntriesModuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Transitie API Routes (Laravel herbouw)
|--------------------------------------------------------------------------
*/

// Publieke auth-routes (geen bearer token vereist, wél fail-closed rate-limiter)
Route::middleware(['throttle.secure:auth'])->group(function () {
    Route::post('/auth/login', [AuthModuleController::class, 'postAuthLogin']);
    Route::post('/auth/password-reset/request', [PasswordResetController::class, 'postRequest']);
    Route::post('/auth/password-reset/confirm', [PasswordResetController::class, 'postConfirm']);
});

// MFA verify: eigen strikte fail-closed rate-limiter (5/min per user_id+IP)
Route::middleware(['throttle.secure:mfa'])->group(function () {
    Route::post('/auth/mfa/verify', [AuthModuleController::class, 'postAuthMfaVerify']);
});

// Publieke operationele health endpoints
Route::get('/health', [HealthController::class, 'getHealth']);
Route::get('/ready', [HealthController::class, 'getReady']);

// Beveiligde interne routes (bearer token + rate-limiter + boekhouder read-only)
Route::middleware(['internal.auth', 'throttle:api', 'bookkeeper.readonly'])->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthModuleController::class, 'postAuthLogout']);
    Route::post('/auth/mfa/setup', [AuthModuleController::class, 'postAuthMfaSetup']);
    Route::post('/auth/accounts', [AuthModuleController::class, 'postInternalAuthAccounts']);

    // Werkregels
    Route::post('/internal/work-entries', [WorkEntriesModuleController::class, 'postInternalWorkEntries']);
    Route::get('/internal/work-entries', [WorkEntriesModuleController::class, 'getInternalWorkEntries']);
    Route::post('/internal/work-entries/copy-week', [WorkEntriesModuleController::class, 'postCopyWeek']);
    Route::get('/internal/work-entries/{id}', [WorkEntriesModuleController::class, 'getInternalWorkEntryById'])->whereNumber('id');
    Route::patch('/internal/work-entries/{id}', [WorkEntriesModuleController::class, 'patchInternalWorkEntryById'])->whereNumber('id');
    Route::delete('/internal/work-entries/{id}', [WorkEntriesModuleController::class, 'deleteInternalWorkEntryById'])->whereNumber('id');

    // Projecten (Requirement 2.4, 2.5)
    Route::get('/internal/projects', [ProjectsModuleController::class, 'getInternalProjects']);
    Route::get('/internal/projects/{id}', [ProjectsModuleController::class, 'getInternalProjectById']);
    Route::post('/internal/projects', [ProjectsModuleController::class, 'postInternalProjects']);
    Route::patch('/internal/projects/{id}', [ProjectsModuleController::class, 'patchInternalProjectById']);
    Route::delete('/internal/projects/{id}', [ProjectsModuleController::class, 'deleteInternalProjectById']);

    // Kostenplaatsen (Requirement 2.6)
    Route::get('/internal/cost-centers', [CostCentersModuleController::class, 'getInternalCostCenters']);
    Route::get('/internal/cost-centers/{id}', [CostCentersModuleController::class, 'getInternalCostCenterById']);
    Route::post('/internal/cost-centers', [CostCentersModuleController::class, 'postInternalCostCenters']);
    Route::patch('/internal/cost-centers/{id}', [CostCentersModuleController::class, 'patchInternalCostCenterById']);
    Route::delete('/internal/cost-centers/{id}', [CostCentersModuleController::class, 'deleteInternalCostCenterById']);

    // Bezwaren
    Route::post('/internal/objections', [ObjectionsModuleController::class, 'postInternalObjections']);
    Route::post('/internal/objections/{id}/review', [ObjectionsModuleController::class, 'postInternalObjectionsIdReview']);
    Route::get('/internal/objections', [ObjectionsModuleController::class, 'getInternalObjections']);

    // ATW
    Route::post('/internal/work-entries/validate-atw', [AtwModuleController::class, 'postInternalWorkEntriesValidateAtw']);
    Route::get('/internal/atw/signals', [AtwModuleController::class, 'getInternalAtwSignals']);

    // Rapporten
    Route::get('/internal/reports/work-entries/pdf', [ReportsModuleController::class, 'getInternalReportsWorkEntriesPdf']);
    Route::get('/internal/reports/work-entries/excel', [ReportsModuleController::class, 'getInternalReportsWorkEntriesExcel']);
    Route::get('/internal/reports/year-export', [ReportsModuleController::class, 'getYearExport']);

    // E-mail flows
    Route::post('/internal/email/dispatch', [EmailFlowsModuleController::class, 'postInternalEmailDispatch']);
    Route::put('/internal/email/templates/{type}', [EmailFlowsModuleController::class, 'putInternalEmailTemplate']);
    Route::get('/internal/email/templates/{type}', [EmailFlowsModuleController::class, 'getInternalEmailTemplate']);
    Route::post('/internal/jobs/monthly-report', [EmailFlowsModuleController::class, 'postInternalJobsMonthlyReport']);

    // Audit
    Route::get('/internal/audit/export', [AuditModuleController::class, 'getAuditExport']);

    // Feestdagen (Requirement 7.6)
    Route::get('/internal/holidays', [HolidaysModuleController::class, 'getInternalHolidays']);

    // Accounts / AVG (Requirement 10)
    Route::delete('/internal/accounts/{id}', [AccountsModuleController::class, 'deleteInternalAccount'])->whereNumber('id');
    Route::get('/internal/accounts/{id}/data-export', [AccountsModuleController::class, 'getInternalAccountDataExport'])->whereNumber('id');
});
