<?php

namespace Tests\Feature\Health;

use App\Services\HealthCheck\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The health endpoint keeps its ceiling when the cache store is the thing that
 * is broken — Phase 11 fix #2.
 *
 * `check()` reuses one completed set of probes for ten seconds so an ungated
 * endpoint cannot be turned into an amplifier: each set is a PDO connect, a
 * cache write and read, a queue `size()` and three storage operations.
 *
 * `Cache::remember()` looks like it does that and does not: on a miss it runs
 * the callback BEFORE trying to store the result, so a store that cannot be
 * written runs the whole set on every request and only then throws. The count
 * below is what tells that apart from the fix.
 *
 * The default store here is broken the way a real one breaks: pointed at a path
 * that cannot be created, so its write raises. Asserted as its own case, because
 * a default store that silently worked would make every assertion below pass for
 * the wrong reason.
 */
class HealthCheckCacheFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten seconds is long enough to survive the previous test in the same
        // run, and the fallback store is the real filesystem.
        Cache::store('file')->forget('health_check:results');

        $this->blockStore('blocked');
        config(['cache.default' => 'blocked']);
        Cache::forgetDriver('blocked');
    }

    public function test_the_default_store_really_cannot_write(): void
    {
        $threw = false;

        try {
            Cache::put('precondition', true, 60);
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'precondition: the configured default store must raise on write');
    }

    /**
     * One set of probes for the ten-second window, with the configured store
     * dead: the reads fall to the fallback and the probes run once.
     */
    public function test_the_fallback_store_holds_the_ceiling_while_the_default_store_is_down(): void
    {
        $service = $this->countingService();

        $service->check();
        $service->check();
        $service->check();

        $this->assertSame(
            1,
            $service->probes,
            'more than one set of probes ran inside one ten-second window, so the ceiling this '
            .'endpoint promises is missing exactly when the cache needs reporting'
        );
    }

    /**
     * The residual, pinned rather than left to be discovered: with nothing left
     * to ask, the checks run per request. Reachable only when both stores are
     * down, which is the one case no store can memoise.
     */
    public function test_with_both_stores_down_the_probes_run_uncached(): void
    {
        $this->blockStore('file');
        Cache::forgetDriver('file');

        $service = $this->countingService();

        $service->check();
        $service->check();

        $this->assertSame(2, $service->probes, 'no store is left, so both requests ran the probes');
    }

    /**
     * The configured store first, unchanged for a healthy install.
     *
     * The reads falling to the fallback only when the first cannot answer is the
     * other half of this: a fix that moved every install onto the file store
     * would pass both cases above.
     */
    public function test_the_configured_store_still_answers_when_it_is_healthy(): void
    {
        config(['cache.default' => 'array']);
        Cache::forgetDriver('array');

        $service = $this->countingService();

        Cache::put('health_check:results', ['database' => 'fail'], 10);

        $this->assertSame(['database' => 'fail'], $service->check());
        $this->assertSame(0, $service->probes, 'the stored set was not served from the configured store');
    }

    /**
     * Counts probe SETS through one overridden probe.
     *
     * The payload is identical whether the set ran once or twice, so the count is
     * the only thing that can tell the two apart.
     */
    private function countingService(): HealthCheckService
    {
        return new class () extends HealthCheckService {
            public int $probes = 0;

            protected function checkDatabase(): string
            {
                $this->probes++;

                return 'ok';
            }
        };
    }

    /**
     * A file store whose base path is a FILE: every write under it raises.
     *
     * Not a mock — the point is that the real driver raises the way the real
     * failure does.
     */
    private function blockStore(string $name): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lbp-health-cache-');

        config(["cache.stores.{$name}" => ['driver' => 'file', 'path' => $file.'/shard']]);
    }
}
