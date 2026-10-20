<?php

namespace App\Services\HealthCheck;

use Illuminate\Contracts\Cache\Repository;
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
     * The store asked when the configured one cannot answer.
     *
     * The local disk, which a dead Redis does not take with it — and the one
     * store among the four subsystems this endpoint checks that is not itself
     * checked (`cache` is the other cache; `storage` is the application disk).
     */
    private const FALLBACK_STORE = 'file';

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
     * A READ, then a best-effort write, rather than `Cache::remember()`. The
     * difference is the ceiling `CACHE_TTL_SECONDS` promises: `remember()` asks
     * the store for the key, and on a miss it runs the callback BEFORE trying to
     * store the result — so a store that cannot be written runs the whole probe
     * set on every request and only then fails. Reading first means a store that
     * cannot answer costs a read and nothing else, and the store that CAN answer
     * is the one that decides whether the probes run.
     *
     * @return array<string, string>
     */
    public function check(): array
    {
        $cached = $this->cachedResults();

        if ($cached !== null) {
            return $cached;
        }

        $checks = $this->runChecks();

        $this->rememberResults($checks);

        return $checks;
    }

    /**
     * The stored set, from whichever store can answer.
     *
     * The configured store first, so a healthy install is unchanged. It is a
     * cache store, and a cache store is one of the things being checked: without
     * a second answer, the one endpoint you reach when Redis is down answers 500
     * instead of reporting `cache: fail` — hiding the failure it exists to name.
     *
     * @return array<string, string>|null
     */
    private function cachedResults(): ?array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // Expected while the cache is the broken subsystem, and reported as
            // `cache: fail` in the answer itself. Not logged: this runs on every
            // request from an untroubled uptime monitor, and the payload already
            // names the failure.
        }

        try {
            $cached = $this->fallbackStore()->get(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // Neither store can answer, so the ceiling is gone and every request
            // runs the probes. That is worth a log line the payload cannot give.
            Log::error('health_check.results_cache.failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return null;
        }

        return is_array($cached) ? $cached : null;
    }

    /**
     * Store the completed set, in both stores, best effort.
     *
     * Both, not just the one asked first: the fallback has to be warm for the
     * request that finds the configured store down, and a store that cannot be
     * written is a store this must not fail on — there is nothing left to do
     * about it here, and the next request that finds no stored set runs the
     * checks again, which is what this branch always did.
     */
    private function rememberResults(array $checks): void
    {
        try {
            Cache::put(self::CACHE_KEY, $checks, self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            //
        }

        try {
            $this->fallbackStore()->put(self::CACHE_KEY, $checks, self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            //
        }
    }

    /**
     * The store the fallback read and write go to.
     */
    private function fallbackStore(): Repository
    {
        return Cache::store(self::FALLBACK_STORE);
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
