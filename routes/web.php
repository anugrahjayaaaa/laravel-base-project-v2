<?php

use App\Http\Controllers\Web\V1\Auth\WebAuthController;
use App\Http\Controllers\Web\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('pages.welcome', ['title' => config('app.name', 'Laravel')]);
});

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

Route::middleware('auth')->group(function () {
    Route::controller(WebAuthController::class)->group(function () {
        Route::post('/logout', 'logout')->name('logout');

        Route::get('/sessions', 'showSessions')->name('sessions');
        
        Route::post('/sessions/logout-all', 'logoutAllDevices')->name('sessions.logout-all');
    });
});

// Authenticated routes — require Sanctum auth + email verification.
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});
