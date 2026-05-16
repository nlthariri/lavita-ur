<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Transitie\AtwModule\AtwModuleController;
use App\Http\Controllers\Transitie\AuditModule\AuditModuleController;
use App\Http\Controllers\Transitie\AuthModule\AuthModuleController;
use App\Http\Controllers\Transitie\AuthModule\PasswordResetController;
use App\Http\Controllers\Transitie\EmailFlowsModule\EmailFlowsModuleController;
use App\Http\Controllers\Transitie\ObjectionsModule\ObjectionsModuleController;
use App\Http\Controllers\Transitie\ReportsModule\ReportsModuleController;
use App\Http\Controllers\Transitie\SystemModule\HealthController;
use App\Http\Controllers\Transitie\WorkEntriesModule\WorkEntriesModuleController;

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

// Beveiligde interne routes (bearer token + rate-limiter)
Route::middleware(['internal.auth', 'throttle:api'])->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthModuleController::class, 'postAuthLogout']);
    Route::post('/auth/mfa/setup', [AuthModuleController::class, 'postAuthMfaSetup']);
    Route::post('/auth/accounts', [AuthModuleController::class, 'postInternalAuthAccounts']);

    // Werkregels
    Route::post('/internal/work-entries', [WorkEntriesModuleController::class, 'postInternalWorkEntries']);
    Route::get('/internal/work-entries', [WorkEntriesModuleController::class, 'getInternalWorkEntries']);

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

    // E-mail flows
    Route::post('/internal/email/dispatch', [EmailFlowsModuleController::class, 'postInternalEmailDispatch']);
    Route::put('/internal/email/templates/{type}', [EmailFlowsModuleController::class, 'putInternalEmailTemplate']);
    Route::get('/internal/email/templates/{type}', [EmailFlowsModuleController::class, 'getInternalEmailTemplate']);
    Route::post('/internal/jobs/monthly-report', [EmailFlowsModuleController::class, 'postInternalJobsMonthlyReport']);

    // Audit
    Route::get('/internal/audit/export', [AuditModuleController::class, 'getAuditExport']);
});


