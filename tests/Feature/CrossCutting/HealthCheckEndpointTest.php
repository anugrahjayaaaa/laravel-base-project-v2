<?php

namespace Tests\Feature\CrossCutting;

use App\Services\HealthCheck\HealthCheckService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HealthCheckEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The uncached set is real work — a PDO connect, a cache write and read
     * back, a queue `size()` and three storage round trips — behind an endpoint
     * anyone can call. These four assert the cache actually stands between the
     * caller and that work, and that it can never make the answer worse.
     */
    private const RESULTS_KEY = 'health_check:results';

    /**
     * The fallback store is the real disk and outlives the per-test app, while
     * the default store does not: a set another health test left behind would be
     * served here instead of the fresh one this test is about.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->forget(self::RESULTS_KEY);
    }

    public function test_health_check_returns_ok_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_health_check_response_structure(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks' => [
                    'database',
                    'cache',
                    'queue',
                    'storage',
                ],
            ]);
    }

    public function test_health_check_status_is_ok_when_all_pass(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok')
            ->assertJsonPath('checks.queue', 'ok')
            ->assertJsonPath('checks.storage', 'ok');
    }

    public function test_health_check_timestamp_is_iso8601(): void
    {
        $response = $this->getJson('/api/v1/health');

        $timestamp = $response->json('timestamp');
        $this->assertIsString($timestamp);
        $this->assertNotEmpty($timestamp);

        // Should be parseable by Carbon
        Carbon::parse($timestamp);
    }

    /**
     * A stored set is served as-is, which is the whole point of the cache: with
     * the fabricated `fail` in it, an answer of `database: ok` would mean the
     * checks ran again and the cache was never consulted.
     */
    public function test_the_cached_set_is_served_instead_of_running_the_checks_again(): void
    {
        Cache::put(self::RESULTS_KEY, [
            'database' => 'fail',
            'cache'    => 'ok',
            'queue'    => 'ok',
            'storage'  => 'ok',
        ], 10);

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.database', 'fail');
    }

    /**
     * The write half, and the reason the payload is safe to store: every value
     * is a string. Caching anything richer through the Redis store is the trap
     * this project has already paid for once — a hit returns
     * `__PHP_Incomplete_Class` instead of the object.
     */
    public function test_a_completed_set_is_stored_for_the_next_caller(): void
    {
        $this->getJson('/api/v1/health')->assertStatus(200);

        $stored = Cache::get(self::RESULTS_KEY);

        $this->assertSame([
            'database' => 'ok',
            'cache'    => 'ok',
            'queue'    => 'ok',
            'storage'  => 'ok',
        ], $stored);
    }

    /**
     * The cache is one of the things being checked, so it cannot be a
     * dependency of reporting it. Without the fallback the endpoint answers 500
     * whenever the store behind `remember()` is unreachable — hiding `cache:
     * fail`, the answer a caller needs in exactly that moment.
     *
     * The facade is swapped for a hand-written store rather than mocked: a
     * `Cache::shouldReceive()` leaves a Mockery handler on the facade, which
     * outlives the test and takes the next one's `tearDown()` with it. The
     * original is restored in `finally` so nothing survives this method.
     */
    public function test_an_unreachable_cache_store_is_reported_rather_than_thrown(): void
    {
        $real = app('cache');

        try {
            Cache::swap(new class () {
                public function remember(string $key, int $ttl, Closure $callback): array
                {
                    throw new RuntimeException('cache store unreachable');
                }

                public function put(string $key, mixed $value, int $ttl): bool
                {
                    return true;
                }

                public function get(string $key): mixed
                {
                    return null;
                }
            });

            $checks = app(HealthCheckService::class)->check();
        } finally {
            Cache::swap($real);
        }

        $this->assertSame('fail', $checks['cache']);
        $this->assertSame('ok', $checks['database']);
    }

    protected function tearDown(): void
    {
        Cache::forget('__health_check__');
        $disk = config('filesystems.default');
        Storage::disk($disk)->delete('__health_check__');

        parent::tearDown();
    }
}
