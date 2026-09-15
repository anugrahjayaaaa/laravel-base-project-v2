<?php

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
});
