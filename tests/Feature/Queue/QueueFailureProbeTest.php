<?php

namespace Tests\Feature\Queue;

use App\Jobs\QueueFailureProbe;
use App\Support\QueueName;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The manual check `docs/base/features/monitoring.md` points operators at has to
 * be dispatchable and has to actually fail: a probe that quietly stops throwing
 * would read as a healthy queue, and a broken command would waste the operator's
 * run at the one moment they are trying to prove the retry path still works.
 */
class QueueFailureProbeTest extends TestCase
{
    public function test_the_probe_command_dispatches_one_failing_job(): void
    {
        Queue::fake();

        $this->artisan('queue:probe-failure')->assertSuccessful();

        Queue::assertPushed(QueueFailureProbe::class, 1);
        Queue::assertPushedOn(QueueName::SWEEPS, QueueFailureProbe::class);
    }

    public function test_the_probe_job_always_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new QueueFailureProbe())->handle();
    }
}
