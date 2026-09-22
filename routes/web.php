<?php

use App\Http\Controllers\Web\V1\AdminUserController;
use App\Http\Controllers\Web\V1\Auth\WebAuthController;
use App\Http\Controllers\Web\V1\DashboardController;
use App\Http\Controllers\Web\V1\SystemSettingController;
use App\Http\Controllers\Web\V1\UserStateController;
use App\Http\Controllers\Web\V1\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Public routes — no authentication required
// ---------------------------------------------------------------------------
Route::get('/', function () {
    return view('pages.welcome', ['title' => config('app.name', 'Laravel Base Project')]);
});

// ---------------------------------------------------------------------------
// Auth routes — login, password reset, email verification
// ---------------------------------------------------------------------------
Route::controller(WebAuthController::class)->group(function () {
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

    Route::get('/verify-email', 'showVerifyEmail')->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', 'verifyEmail')->name('verification.verify');
    Route::post('/email/resend', 'resendVerification')
        ->name('verification.resend')
        ->middleware('throttle:resend-verification');
});

// ---------------------------------------------------------------------------
// Authenticated + verified + valid account state
// ---------------------------------------------------------------------------
Route::middleware(['auth', 'verified', 'account.state'])->group(function () {

    // Auth management
    Route::controller(WebAuthController::class)->group(function () {
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/sessions', 'showSessions')->name('sessions');
        Route::post('/sessions/logout-all', 'logoutAllDevices')->name('sessions.logout-all');
    });

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/settings', [SystemSettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SystemSettingController::class, 'update'])->name('settings.update');

    // Profile
    Route::controller(\App\Http\Controllers\Web\V1\ProfileController::class)->group(function () {
        Route::get('/profile', 'show')->name('profile.show');
        Route::put('/profile', 'update')->name('profile.update');
    });
    Route::bind('user', function ($id) {
        return User::withTrashed()->findOrFail($id);
    });
    Route::resource('users', UserController::class)->except(['restore', 'force-delete', 'resend-verification']);
    Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::delete('/users/{id}/force', [UserController::class, 'forceDelete'])->name('users.force-delete');
    Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resend-verification');
    Route::post('/users/{user}/request-email-change', [UserController::class, 'requestEmailChange'])->name('users.request-email-change');
    Route::post('/users/{user}/cancel-email-change', [UserController::class, 'cancelEmailChange'])->name('users.cancel-email-change');
    Route::get('/email/verify-change/{user}/{token}', [UserController::class, 'verifyEmailChange'])->name('email.verify-change');

    // User state toggles (Activate / Deactivate / Lock / Unlock)
    Route::middleware(['throttle:user-state-actions'])->group(function () {
        Route::post('/users/{user}/activate', [UserStateController::class, 'activate'])->name('users.activate');
        Route::post('/users/{user}/deactivate', [UserStateController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/lock', [UserStateController::class, 'lock'])->name('users.lock');
        Route::post('/users/{user}/unlock', [UserStateController::class, 'unlock'])->name('users.unlock');
    });
});