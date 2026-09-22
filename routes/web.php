<?php

use App\Http\Controllers\Web\V1\AdminUserController;
use App\Http\Controllers\Web\V1\Auth\WebAuthController;
use App\Http\Controllers\Web\V1\DashboardController;
use App\Http\Controllers\Web\V1\UserStateController;
use App\Http\Controllers\Web\V1\UserController;
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

    // User CRUD
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');
    Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::delete('/users/{id}/force', [UserController::class, 'forceDelete'])->name('users.force-delete');
    Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resend-verification');

    // User state toggles (Activate / Deactivate / Lock / Unlock)
    Route::middleware(['throttle:user-state-actions'])->group(function () {
        Route::post('/users/{user}/activate', [UserStateController::class, 'activate'])->name('users.activate');
        Route::post('/users/{user}/deactivate', [UserStateController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/lock', [UserStateController::class, 'lock'])->name('users.lock');
        Route::post('/users/{user}/unlock', [UserStateController::class, 'unlock'])->name('users.unlock');
    });
});