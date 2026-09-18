<?php

use App\Http\Controllers\Web\V1\Auth\WebAuthController;
use App\Http\Controllers\Web\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('pages.welcome', ['title' => config('app.name', 'Laravel')]);
});

Route::controller(WebAuthController::class)->group(function () {
    Route::get('/login', 'login')->name('login');
    Route::post('/login', 'handleLogin')
        ->name('login.submit')
        ->middleware('throttle:login');

    Route::get('/forgot-password', 'forgotPassword')->name('password.request');
    Route::post('/forgot-password', 'sendResetLink')
        ->name('password.email')
        ->middleware('throttle:forgot-password');

    Route::get('/reset-password', 'resetPassword')->name('password.reset');
    Route::post('/reset-password', 'resetPasswordSubmit')
        ->name('password.update')
        ->middleware('throttle:reset-password');

    Route::get('/verify-email', 'verifyEmail')->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', 'verified')->name('verification.verify');
    Route::post('/email/resend', 'resendVerification')
        ->name('verification.resend')
        ->middleware('auth');

    Route::post('/logout', 'logout')
        ->name('logout')
        ->middleware('auth');
});

// Authenticated routes — require Sanctum auth (consistent with API).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});
