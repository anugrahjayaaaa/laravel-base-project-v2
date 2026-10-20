<?php

namespace App\Jobs;

use App\Jobs\Concerns\ReadsCorrelationId;
use App\Support\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A job that always throws — the manual check for the queue monitoring
 * screens and for Vantage's retry button.
 *
 * Nothing dispatches it automatically: `php artisan queue:probe-failure` pushes
 * one, and a worker turns it into the pair those screens read — a
 * `failed_jobs` row (the payload Vantage's retry restores from, see
 * `App\Support\Vantage\FailedJobPayloadRestorer`) plus a `vantage_jobs` row with
 * `status = failed`. Being able to produce that pair on demand is worth a job,
 * because Vantage's retry is the one path in the app that depends on two stores
 * agreeing, and nothing else in the app fails on purpose.
 *
 * `$tries = 1` is what makes it usable: the failure lands on the first attempt
 * instead of after a backoff, so it shows up at the worker's next poll. Every
 * retry from `/vantage` then fails again in the same way — which is the point,
 * since the new attempt appears in the retry chain (`retried_from_id`) and shows
 * the whole path working.
 *
 * Rides `sweeps` because that queue is declared and `bin/run-workers.sh`
 * already listens to it; a diagnostic on a queue of its own would mean another
 * `--queue` entry for no gain.
 */
class QueueFailureProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ReadsCorrelationId;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue(QueueName::SWEEPS);
    }

    /**
     * One attempt, so the first exception is the permanent failure.
     *
     * @var int
     */
    public $tries = 1;

    /** @var int */
    public $timeout = 30;

    /** @var int */
    public $maxExceptions = 1;

    /**
     * Declared, never reached: `$tries = 1` makes the first attempt the last.
     * The queue convention requires an explicit retry chain on every job, and
     * this job is short enough that a reader copies from it.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60];
    }

    /**
     * The failure is the expected outcome, so the log line says so rather than
     * reading like an incident.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Queue failure probe failed (deliberate)', [
            'request_id' => $this->requestId(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    public function handle(): void
    {
        throw new RuntimeException('QueueFailureProbe: deliberate failure for the queue monitoring screens');
    }
}
