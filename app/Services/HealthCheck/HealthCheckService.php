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
     * Where a completed set of checks is kept between callers.
     */
    private const CACHE_KEY = 'health_check:results';

    /**
     * How long a completed set of checks is reused, in seconds.
     *
     * One set costs seven round trips — a PDO connect, a cache write AND a read
     * back, a queue `size()` and three storage operations — answering a
     * question that changes on the order of minutes, paid again on every
     * request to an endpoint that has no gate in front of it. Ten seconds is a third of the shortest interval a
     * legitimate caller polls at, so the status returned is never one the
     * caller would have measured differently; what it buys is a ceiling of six
     * executions a minute per server however many callers arrive.
     */
    private const CACHE_TTL_SECONDS = 10;

    /**
     * Run all health checks and return the results.
     *
     * @return array<string, string>
     */
    public function check(): array
    {
        try {
            return Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                fn (): array => $this->runChecks()
            );
        } catch (\Throwable $e) {
            // `Cache::remember` cannot answer without the cache store, and the
            // cache store is one of the things being checked. Without this
            // branch, the one endpoint you reach when Redis is down answers 500
            // instead of reporting `cache: fail` — it would hide the failure it
            // exists to name.
            Log::error('health_check.results_cache.failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return $this->runChecks();
        }
    }

    /**
     * The uncached work: one round trip per subsystem.
     *
     * @return array<string, string>
     */
    private function runChecks(): array
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

            // Read it back: a store that accepts a write and silently drops it
            // (disk full, unreachable Redis) answers `ok` on the write alone.
            return Cache::get('__health_check__') === true ? 'ok' : 'fail';
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
