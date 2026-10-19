<?php

namespace Tests\Feature\Vantage;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\WorkerOptions;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * The `vantage` flag reaches a WORKER, not just the dashboard — Phase 11 fix #1.
 *
 * Vantage's recorders re-read `config('vantage.enabled')` inside `handle()`, so
 * that config key is the call-time switch — and nothing was setting it from the
 * flag. `VANTAGE_ENABLED` was the only switch that reached a job, which left the
 * flag answering for the page and not for the writes.
 *
 * `Looping` fires once per daemon iteration, before the job is fetched. It is
 * the hook that works: the package listens on `JobProcessing` and registered
 * first (packages boot before app providers), so a listener of ours on the same
 * event would run after it and be one job behind.
 */
class VantageRecordingKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The runtime config, read where the worker reads it.
     *
     * Asserted through the config key rather than through a dispatched job,
     * because the key is the whole contract: the package reads it at `handle()`
     * time and this is the only thing that writes it per cycle.
     */
    public function test_the_worker_loop_pulls_the_flag_onto_the_runtime_config(): void
    {
        Feature::activate('vantage');
        config(['vantage.enabled' => false]);

        event($this->loop());

        $this->assertTrue(config('vantage.enabled'), 'the loop did not switch recording on for an active flag');

        Feature::deactivate('vantage');
        Feature::flushCache();

        event($this->loop());

        $this->assertFalse(
            config('vantage.enabled'),
            'a job processed after this loop would still be recorded for a switched-off module'
        );
    }

    /**
     * And the switch actually stops a write.
     *
     * The synchronous connection raises `JobProcessing` inside the request, so a
     * job dispatched here reaches the package's own listener — the thing that
     * reads the config this test sets. Recording off must mean no row, not a row
     * with a flag on it.
     */
    public function test_a_switched_off_flag_stops_the_job_row(): void
    {
        Feature::activate('vantage');
        event($this->loop());

        dispatch(new KillSwitchProbeJob());

        $this->assertDatabaseCount('vantage_jobs', 1);

        Feature::deactivate('vantage');
        Feature::flushCache();

        event($this->loop());

        dispatch(new KillSwitchProbeJob());

        $this->assertDatabaseCount(
            'vantage_jobs',
            1
        );
    }

    private function loop(): Looping
    {
        return new Looping('sync', 'default', new WorkerOptions());
    }
}

/**
 * The smallest job that can be recorded: no constructor, no work.
 *
 * Defined here rather than reused from `app/Jobs` so the test does not depend on
 * a real job's behaviour — the subject is whether the package writes a row.
 */
class KillSwitchProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public function handle(): void
    {
        //
    }
}
