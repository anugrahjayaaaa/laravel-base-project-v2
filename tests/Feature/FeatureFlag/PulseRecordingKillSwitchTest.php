<?php

namespace Tests\Feature\FeatureFlag;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Laravel\Pulse\Facades\Pulse;
use Tests\TestCase;

/**
 * The `pulse` flag stops Pulse WRITING, not just the dashboard — Phase 11 fix #1.
 *
 * The flag used to be route-only: an operator switching Pulse off during an
 * incident stopped the page and kept the ingestion, because the package decides
 * whether to record once, in its own `boot()`, from `PULSE_ENABLED`. The fix is
 * `Pulse::filter()` in `AppServiceProvider::boot()`, which the package consults
 * at INGEST — so the flag is read when there is something to write.
 *
 * `PULSE_ENABLED=false` in `phpunit.xml` and the filter are two different
 * switches, and this file is where that shows: Pulse's own switch stops the
 * recorders from being registered at all (`stopRecording()`), so
 * `startRecording()` is needed to have anything to test. The filter is what
 * makes the flag answer per ingest, in a worker as well as in a request.
 */
class PulseRecordingKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The positive case first, or the negative one proves nothing: "zero entries
     * ingested" is also what a broken ingest returns.
     */
    public function test_the_flag_on_ingests_the_entry(): void
    {
        Pulse::startRecording();
        Pulse::record('request', 'flag-on');

        $this->assertSame(1, Pulse::ingest(), 'precondition: with the flag on, the entry lands');
        $this->assertDatabaseCount('pulse_entries', 1);
    }

    public function test_the_flag_off_drops_the_entry_at_ingest(): void
    {
        Feature::deactivate('pulse');
        Feature::flushCache();

        Pulse::startRecording();
        Pulse::record('request', 'flag-off');

        $this->assertSame(
            0,
            Pulse::ingest(),
            'an entry was ingested for a module the operator switched off'
        );
        $this->assertDatabaseCount('pulse_entries', 0);
    }
}
