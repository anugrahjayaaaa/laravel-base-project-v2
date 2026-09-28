<?php

use App\Http\Controllers\Web\V1\Auth\AuthController;
use App\Http\Controllers\Web\V1\DashboardController;
use App\Http\Controllers\Web\V1\PermissionController;
use App\Http\Controllers\Web\V1\ProfileController;
use App\Http\Controllers\Web\V1\RoleController;
use App\Http\Controllers\Web\V1\SystemSettingController;
use App\Http\Controllers\Web\V1\UserStateController;
use App\Http\Controllers\Web\V1\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Public routes — no authentication required
// ---------------------------------------------------------------------------
Route::get('/', function () {
    return view('pages.welcome', [
        'title' => config('app.name', 'Laravel Base Project'),
        'laravelVersion' => app()->version(),
    ]);
});

// ---------------------------------------------------------------------------
// Auth routes — login, password reset, email verification
// ---------------------------------------------------------------------------
Route::controller(AuthController::class)->group(function () {
    Route::get('/login', 'showLogin')->name('login');
    Route::post('/login', 'login')
        ->name('login.submit')
        ->middleware('throttle:login');

    Route::get('/forgot-password', 'showForgotPassword')->name('password.request');
    Route::post('/forgot-password', 'sendPasswordResetLink')
        ->name('password.email')
        ->middleware('throttle:forgot-password');

    Route::get('/reset-password', 'showResetPassword')->name('password.reset');
    Route::post('/reset-password', 'resetUserPassword')
        ->name('password.update')
        ->middleware('throttle:reset-password');

    // Both verbs check `registration_enabled` in the controller — a disabled
    // feature should 404, not 403, so no `can:` gate here.
    Route::get('/register', 'showRegister')->name('register');
    Route::post('/register', 'register')
        ->name('register.submit')
        ->middleware('throttle:register');

    Route::get('/verify-email', 'showVerifyEmail')->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', 'verifyEmail')->name('verification.verify');
    Route::post('/email/resend', 'resendVerification')
        ->name('verification.resend')
        ->middleware('throttle:resend-verification');
});

// ---------------------------------------------------------------------------
// Authenticated + verified + valid account state
// ---------------------------------------------------------------------------
Route::middleware(['auth:web,sanctum', 'verified', 'password.change.required', 'account.state'])->group(function () {

    // Auth management
    Route::controller(AuthController::class)->group(function () {
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/sessions', 'showSessions')->name('sessions');
        Route::post('/sessions/logout-all', 'logoutAllDevices')->name('sessions.logout-all');
    });

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/settings', [SystemSettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SystemSettingController::class, 'update'])->name('settings.update');

    // Profile
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'show')->name('profile.show');
        Route::put('/profile', 'update')->name('profile.update');
        Route::get('/password/expired', 'showExpiredPassword')->name('password.expired');
        Route::get('/password/change', 'show')->name('password.change');
        Route::put('/password/change', 'ChangePasswordAction')->name('password.change.update');
    });

    // Users
    Route::resource('users', UserController::class)->except(['restore', 'force-delete', 'resend-verification']);
    Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::delete('/users/{user}/force', [UserController::class, 'forceDelete'])->name('users.force-delete');
    Route::bind('user', function ($id) {
        return User::withTrashed()->findOrFail($id);
    });
    Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resend-verification')->middleware('throttle:resend-verification');
    Route::post('/users/{user}/request-email-change', [UserController::class, 'requestEmailChange'])->name('users.request-email-change');
    Route::post('/users/{user}/cancel-email-change', [UserController::class, 'cancelEmailChange'])->name('users.cancel-email-change');
    Route::get('/email/verify-change/{user}', [UserController::class, 'verifyEmailChange'])
        ->name('email.verify-change')->middleware(['signed', 'throttle:email-verification']);
    Route::post('/users/bulk-action', [UserController::class, 'bulkAction'])->name('users.bulk-action')->middleware('throttle:bulk-action');

    // User state toggles (Activate / Deactivate / Lock / Unlock)
    Route::middleware(['throttle:user-state-actions'])->group(function () {
        Route::post('/users/{user}/activate', [UserStateController::class, 'activate'])->name('users.activate');
        Route::post('/users/{user}/deactivate', [UserStateController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/lock', [UserStateController::class, 'lock'])->name('users.lock');
        Route::post('/users/{user}/unlock', [UserStateController::class, 'unlock'])->name('users.unlock');
    });

    // -----------------------------------------------------------------------
    // Roles & permissions — Phase 6 Group A (UI only).
    //
    // NO `can:` gate here yet, and that is deliberate and temporary: the
    // permission rows themselves are seeded in Group B (P6-B1), so a gate now
    // would deny everyone including superadmin. P6-D1 wraps these in
    // `can:roles.view` / `can:roles.create` / `can:roles.update` /
    // can:roles.delete` / `can:permissions.view` once the catalogue exists.
    // -----------------------------------------------------------------------
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');

    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
});
