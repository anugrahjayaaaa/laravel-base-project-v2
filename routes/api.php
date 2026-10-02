<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordChangeController as ApiPasswordChangeController;
use App\Http\Controllers\Api\V1\Auth\PasswordForgotController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Permission\PermissionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Role\RoleController;
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
        Route::post('/login', LoginController::class)->name('api.v1.auth.login');
        Route::post('/register', RegisterController::class)->name('api.v1.auth.register')->middleware('throttle:register');
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

        // Auth management. Same split as web.php: `logout` stays outside the
        // sessions gate so a bad flag cannot strand a user in a live session,
        // while `logout-all` is part of the sessions module and is gated. The
        // two call the same actions and write the same audit events, so an
        // ungated API route let a client mass-logout every device even with the
        // module switched off.
        Route::post('/auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::middleware('feature:sessions')->group(function () {
            Route::post('/auth/logout-all', LogoutAllController::class)->name('api.v1.auth.logout-all');
        });
        Route::post('/auth/email/resend', ResendVerificationController::class)->name('api.v1.auth.email.resend')->middleware('throttle:resend-verification');

        // Profile (authenticated user only)
        Route::controller(ProfileController::class)->group(function () {
            Route::get('/profile', 'show')->name('api.v1.profile.show');
            Route::put('/profile', 'update')->name('api.v1.profile.update');
        });

        // Sessions — flag `sessions`
        Route::middleware('feature:sessions')->group(function () {
            Route::controller(SessionController::class)->group(function () {
                Route::get('/sessions', 'index')->name('api.v1.sessions');
            });
        });

        // System Settings — gated to mirror the web matrix (P6-D2). These had no
        // gate at all, so any authenticated token could read every configured
        // value, including the registration and lockout defaults the admin panel
        // was supposed to hide.
        // System Settings — flag `settings`, mirroring the web matrix (P7-D8).
        Route::middleware('feature:settings')->group(function () {
            Route::controller(SystemSettingController::class)->group(function () {
                Route::get('/settings', 'index')->name('api.v1.settings.index')->can('settings.view');
                Route::put('/settings', 'update')->name('api.v1.settings.update')->can('settings.manage');
            });
        });

        // User state management (Activate / Deactivate / Lock / Unlock)
        //
        // Ungated on the API as well as the web: the same controller, the same
        // User state management — flag `users`
        // actions, and neither checked anything, so a valid Sanctum token could
        // lock any account in the system.
        // Flag `users` — mirrors the web matrix (P7-D8). One group, not a
        // per-route call: a flag on 3 of 9 routes is a partial gate.
        Route::middleware('feature:users')->group(function () {
            Route::middleware(['throttle:user-state-actions'])->group(function () {
                Route::post('/users/{user}/activate', [UserStateController::class, 'activate'])->name('api.v1.users.activate')->can('users.activate');
                Route::post('/users/{user}/deactivate', [UserStateController::class, 'deactivate'])->name('api.v1.users.deactivate')->can('users.deactivate');
                Route::post('/users/{user}/lock', [UserStateController::class, 'lock'])->name('api.v1.users.lock')->can('users.lock');
                Route::post('/users/{user}/unlock', [UserStateController::class, 'unlock'])->name('api.v1.users.unlock')->can('users.unlock');
            });

            // User CRUD — resource + custom actions
            // Gated per-ability (P6-C14/C15/C18). The Form Requests already
            // authorize store/update/destroy; `can:` closes the reads, which nothing
            // was checking — before this, any authenticated token could list users.
            Route::get('/users', [UserController::class, 'index'])
                ->name('api.v1.users.index')->can('users.view');
            Route::get('/users/{user}', [UserController::class, 'show'])
                ->name('api.v1.users.show')->can('users.view');
            Route::post('/users', [UserController::class, 'store'])
                ->name('api.v1.users.store')->can('users.create');
            Route::put('/users/{user}', [UserController::class, 'update'])
                ->name('api.v1.users.update')->can('users.update');
            // Route::resource registered PUT and PATCH under one name; same here.
            Route::patch('/users/{user}', [UserController::class, 'update'])
                ->name('api.v1.users.update')->can('users.update');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])
                ->name('api.v1.users.destroy')->can('users.delete');
            Route::delete('/users/{user}/force', [UserController::class, 'forceDelete'])->name('api.v1.users.force-delete')->can('users.force_delete');
            Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('api.v1.users.restore')->can('users.restore');

            // Email change flow
            Route::post('/users/{user}/request-email-change', [UserController::class, 'requestEmailChange'])->name('api.v1.users.request-email-change');
            Route::post('/users/{user}/cancel-email-change', [UserController::class, 'cancelEmailChange'])->name('api.v1.users.cancel-email-change');
            Route::get('/email/verify-change/{user}/{token?}', [UserController::class, 'verifyEmailChange'])->name('api.v1.email.verify-change')->middleware(['signed', 'throttle:email-verification']);
            Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('api.v1.users.resend-verification')->middleware('throttle:resend-verification')->can('users.update');
            // Same reasoning as the web bulk route: the permission depends on the
            // requested action, so AuthorizesBulkAction decides. See routes/web.php.
            Route::post('/users/bulk-action', [UserController::class, 'bulkAction'])->name('api.v1.users.bulk-action')->middleware('throttle:bulk-action');
        });


        // Role management — was browser-only, which meant a non-browser client
        // could administer users but could not administer the roles those users
        // hold. Same actions and Form Requests as the web controller, so the
        // system-role guards (P6-E3) apply here without being restated.
        //
        // `restore` and `force-delete` take an int rather than the model: the
        // default binding only finds LIVE rows, and a trashed role is the only
        // thing either endpoint accepts. The controllers use onlyTrashed().
        Route::middleware('feature:roles')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('api.v1.roles.index')->can('roles.view');
            Route::post('/roles', [RoleController::class, 'store'])->name('api.v1.roles.store')->can('roles.create');
            Route::get('/roles/{role}', [RoleController::class, 'show'])->name('api.v1.roles.show')->can('roles.view');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('api.v1.roles.update')->can('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('api.v1.roles.destroy')->can('roles.delete');
            Route::post('/roles/{role}/restore', [RoleController::class, 'restore'])->name('api.v1.roles.restore')->can('roles.restore');
            Route::delete('/roles/{role}/force', [RoleController::class, 'forceDelete'])->name('api.v1.roles.force-delete')->can('roles.force_delete');
        });


        // Permission catalogue — read-only by design (P6-C7). Index only, on
        // purpose: the catalogue is defined in code, so a write endpoint would
        // be new behaviour rather than an API surface for existing behaviour.
        // The catalogue is defined in code (P6-C7), so switching `roles` off
        // does not invalidate it — which is why it is its own flag.
        Route::middleware('feature:permissions')->group(function () {
            Route::get('/permissions', [PermissionController::class, 'index'])->name('api.v1.permissions.index')->can('permissions.view');
        });
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
