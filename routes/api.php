<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
    Route::get('/health', function () {
        $checks = [];
        $allOk  = true;

        // Database connectivity
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
            $allOk = false;
        }

        // Cache write/read
        try {
            Cache::put('__health_check__', true, 1);
            $checks['cache'] = 'ok';
        } catch (\Throwable $e) {
            $checks['cache'] = 'fail';
            $allOk = false;
        }

        // Queue connectivity — resolve queue size to verify connection
        try {
            Queue::size();
            $checks['queue'] = 'ok';
        } catch (\Throwable $e) {
            $checks['queue'] = 'fail';
            $allOk = false;
        }

        // Storage write/read on default disk
        try {
            $disk = config('filesystems.default');
            Storage::disk($disk)->put('__health_check__', 'ok');
            $read = Storage::disk($disk)->get('__health_check__');
            Storage::disk($disk)->delete('__health_check__');
            $checks['storage'] = $read === 'ok' ? 'ok' : 'fail';
            if ($checks['storage'] !== 'ok') {
                $allOk = false;
            }
        } catch (\Throwable $e) {
            $checks['storage'] = 'fail';
            $allOk = false;
        }

        return response()->json([
            'status'    => $allOk ? 'ok' : 'fail',
            'timestamp' => now()->toIso8601String(),
            'checks'    => $checks,
        ], $allOk ? 200 : 503);
    });
});
