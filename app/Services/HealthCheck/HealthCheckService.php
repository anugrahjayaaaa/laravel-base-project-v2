<?php

namespace App\Services\HealthCheck;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class HealthCheckService
{
    /**
     * Run all health checks and return the results.
     *
     * @return array<string, string>
     */
    public function check(): array
    {
        $checks = [];

        $checks['database'] = $this->checkDatabase();
        $checks['cache']    = $this->checkCache();
        $checks['queue']    = $this->checkQueue();
        $checks['storage']  = $this->checkStorage();

        return $checks;
    }

    /**
     * Check if all checks passed (no failures).
     *
     * @return bool
     */
    public function isHealthy(array $checks): bool
    {
        return !in_array('fail', $checks, true);
    }

    /**
     * Check database connectivity via PDO.
     *
     * @return string 'ok' or 'fail'
     */
    protected function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();

            return 'ok';
        } catch (\Throwable $e) {
            Log::error('health_check.database.failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return 'fail';
        }
    }

    /**
     * Check cache read/write via a test key.
     *
     * @return string 'ok' or 'fail'
     */
    protected function checkCache(): string
    {
        try {
            Cache::put('__health_check__', true, 1);

            return 'ok';
        } catch (\Throwable $e) {
            Log::error('health_check.cache.failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return 'fail';
        }
    }

    /**
     * Check queue connectivity.
     *
     * @return string 'ok' or 'fail'
     */
    protected function checkQueue(): string
    {
        try {
            Queue::size();

            return 'ok';
        } catch (\Throwable $e) {
            Log::error('health_check.queue.failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return 'fail';
        }
    }

    /**
     * Check storage read/write via a test file.
     *
     * @return string 'ok' or 'fail'
     */
    protected function checkStorage(): string
    {
        try {
            $disk = config('filesystems.default');
            Storage::disk($disk)->put('__health_check__', 'ok');
            $read = Storage::disk($disk)->get('__health_check__');
            Storage::disk($disk)->delete('__health_check__');

            return $read === 'ok' ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            Log::error('health_check.storage.failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return 'fail';
        }
    }
}
