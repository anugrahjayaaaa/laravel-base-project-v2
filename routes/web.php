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

    Route::get('/settings', [SystemSettingController::class, 'index'])->name('settings.index')->can('settings.view');
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
    // Per-ability gates (P6-C14/C15/C18). The Form Requests authorize the
    // writes; nothing was checking the reads, so any authenticated session could
    // list and view users. Route::resource registered PUT and PATCH for update
    // under one name — preserved below.
    Route::get('/users', [UserController::class, 'index'])->name('users.index')->can('users.view');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create')->can('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store')->can('users.create');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show')->can('users.view');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('users.update');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update')->can('users.update');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update')->can('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy')->can('users.delete');
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
    // Roles & permissions — Phase 6.
    //
    // Group A added the read routes only, and pointed the create/edit forms at
    // roles.index as a placeholder because no save route existed yet.
    //
    // Gated as of Group C2: the catalogue these gates check against is seeded in
    // Group B (P6-B1), so the original reason to defer them is gone. The write
    // routes were never unguarded — their Form Requests authorized on the same
    // roles.* permissions — and now the reads are closed the same way.
    // -----------------------------------------------------------------------
    Route::post('/roles/bulk-action', [RoleController::class, 'bulkAction'])->name('roles.bulk-action')->middleware('throttle:bulk-action');
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index')->can('roles.view');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create')->can('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit')->can('roles.update');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    // Both take the raw id, NOT an implicit {role} binding: that binding resolves
    // through the SoftDeletes global scope, so it 404s every trashed role —
    // exactly the rows these two routes exist for. The controller looks the row
    // up with onlyTrashed() instead. POST, not GET, for both: they mutate.
    Route::post('/roles/{role}/restore', [RoleController::class, 'restore'])->name('roles.restore');
    Route::delete('/roles/{role}/force', [RoleController::class, 'forceDelete'])->name('roles.force-delete');

    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index')->can('permissions.view');
});
