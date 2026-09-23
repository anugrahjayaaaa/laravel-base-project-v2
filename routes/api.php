<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordChangeController as ApiPasswordChangeController;
use App\Http\Controllers\Api\V1\Auth\PasswordForgotController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\User\UserController;
use App\Http\Controllers\Api\V1\User\UserStateController;
use App\Http\Controllers\Api\V1\HealthCheck\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------------------
    // System health
    // ---------------------------------------------------------------------------
    Route::get('/health', HealthCheckController::class);

    // ---------------------------------------------------------------------------
    // Public auth routes — no authentication required
    // ---------------------------------------------------------------------------
    Route::prefix('auth')->group(function () {
        Route::post('/login', LoginController::class)->name('api.v1.auth.login')->middleware('throttle:login');
        Route::post('/password/forgot', PasswordForgotController::class)->name('api.v1.auth.password.forgot')->middleware('throttle:forgot-password');
        Route::post('/password/reset', PasswordResetController::class)->name('api.v1.auth.password.reset')->middleware('throttle:reset-password');
        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->name('verification.verify.api')->middleware('signed');

        // Backwards-compat: old unlock endpoint -> UserStateController.
        Route::post('/unlock', fn () => to_route('api.v1.users.unlock', ['user' => request()->route('user')]))->name('api.v1.auth.unlock');
    });

    // ---------------------------------------------------------------------------
    // Protected routes — Sanctum auth + email verified + non-expired password
    // ---------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'verified', 'password.change.required', 'account.state', 'throttle:user-state-actions'])->group(function () {

        // Auth management
        Route::post('/auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::post('/auth/logout-all', LogoutAllController::class)->name('api.v1.auth.logout-all');
        Route::post('/auth/email/resend', ResendVerificationController::class)->name('api.v1.auth.email.resend')->middleware('throttle:resend-verification');

        // Profile (authenticated user only)
        Route::controller(ProfileController::class)->group(function () {
            Route::get('/profile', 'show')->name('api.v1.profile.show');
            Route::put('/profile', 'update')->name('api.v1.profile.update');
        });

        // Sessions
        Route::controller(SessionController::class)->group(function () {
            Route::get('/sessions', 'index')->name('api.v1.sessions');
        });

        // System Settings
        Route::controller(SystemSettingController::class)->group(function () {
            Route::get('/settings', 'index')->name('api.v1.settings.index');
            Route::put('/settings', 'update')->name('api.v1.settings.update');
        });

        // User state management (Activate / Deactivate / Lock / Unlock)
        Route::middleware(['throttle:user-state-actions'])->group(function () {
            Route::post('/users/{user}/activate', [UserStateController::class, 'activate'])->name('api.v1.users.activate');
            Route::post('/users/{user}/deactivate', [UserStateController::class, 'deactivate'])->name('api.v1.users.deactivate');
            Route::post('/users/{user}/lock', [UserStateController::class, 'lock'])->name('api.v1.users.lock');
            Route::post('/users/{user}/unlock', [UserStateController::class, 'unlock'])->name('api.v1.users.unlock');
        });

        // User CRUD — resource + custom actions
        Route::resource('users', UserController::class)->names('api.v1.users')->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::delete('/users/{user}/force', [UserController::class, 'forceDelete'])->name('api.v1.users.force-delete');
        Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('api.v1.users.restore');

        // Email change flow
        Route::post('/users/{user}/request-email-change', [UserController::class, 'requestEmailChange'])->name('api.v1.users.request-email-change');
        Route::post('/users/{user}/cancel-email-change', [UserController::class, 'cancelEmailChange'])->name('api.v1.users.cancel-email-change');
        Route::get('/email/verify-change/{user}/{token}', [UserController::class, 'verifyEmailChange'])->name('api.v1.email.verify-change')->middleware('signed');
        Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('api.v1.users.resend-verification')->middleware('throttle:resend-verification');
        Route::post('/users/bulk-action', [UserController::class, 'bulkAction'])->name('api.v1.users.bulk-action');
    });

    // ---------------------------------------------------------------------------
    // Password change — reachable even when password is expired/must-change.
    // Uses auth:sanctum only, NOT password.change.required, so an expired
    // user can always recover.
    // ---------------------------------------------------------------------------
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/auth/password/change', ApiPasswordChangeController::class)->name('api.v1.auth.password.change');
    });
});
