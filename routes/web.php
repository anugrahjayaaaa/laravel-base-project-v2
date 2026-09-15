<?php

use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('pages.welcome', ['title' => config('app.name', 'Laravel')]);
});

Route::get('/dashboard', DashboardController::class)
    ->name('dashboard');
