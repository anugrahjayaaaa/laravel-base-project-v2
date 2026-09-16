<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\PasswordChangeController as ApiPasswordChangeController;
use App\Http\Controllers\Api\V1\Auth\PasswordForgotController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\UnlockController;
use App\Http\Controllers\Api\V1\HealthCheck\HealthCheckController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. The routes are automatically
| prefixed with /api by Laravel's ApplicationBuilder::withRouting().
|
*/

Route::prefix('v1')->group(function () {
    /*
    | FOUND-010: System health check endpoint
    | GET /api/v1/health
    | See docs/base/features/monitoring.md § Health Check Endpoint
    | and docs/base/infrastructure/observability.md § System Health.
    */
    Route::get('/health', HealthCheckController::class);

    // Public auth routes (guest).
    Route::prefix('auth')->group(function () {
        Route::post('/login', LoginController::class)->name('api.v1.auth.login')->middleware('throttle:login');
        Route::post('/password/forgot', PasswordForgotController::class)->name('api.v1.auth.password.forgot')->middleware('throttle:forgot-password');
        Route::post('/password/reset', PasswordResetController::class)->name('api.v1.auth.password.reset')->middleware('throttle:forgot-password');
        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->name('verification.verify.api')->middleware('signed');
    });

    // Protected API endpoints: require Sanctum auth + non-expired password.
    // The middleware exempts verification, logout, and email.resend routes
    // by route-name substring (see web.php for the matching pattern).
    // NOTE: the password-change route is intentionally NOT in this group —
    // it must remain reachable while the password is expired/must-change.
    Route::middleware(['auth:sanctum', 'password.change.required'])->group(function () {
        Route::post('/auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::post('/auth/logout-all', LogoutAllController::class)->name('api.v1.auth.logout-all');
        Route::post('/auth/email/resend', ResendVerificationController::class)->name('api.v1.auth.email.resend')->middleware('throttle:resend-verification');
        Route::post('/users/{user}/unlock', UnlockController::class)->name('api.v1.users.unlock');
    });

    // Authenticated password change — reachable even when the password is
    // expired / must-change. Uses auth:sanctum only, deliberately NOT
    // password.change.required, so an expired user can always recover.
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/auth/password/change', ApiPasswordChangeController::class)->name('api.v1.auth.password.change');
    });
});
