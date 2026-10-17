<?php

namespace App\Support\Vantage;

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\JobRestorer;
use Storvia\Vantage\Support\VantageLogger;

/**
 * Restores a job for Vantage's retry button from the payload the framework
 * already stored, instead of the one Vantage keeps.
 *
 * `POST /vantage/jobs/{id}/retry` rebuilds the job from `vantage_jobs.payload`,
 * a column that only has content when Vantage's `store_payload` is on. This
 * project keeps it off (`config/vantage.php`, ADR-022) because that column
 * would persist export filters, model ids and mail arguments for every
 * `queues.view` holder to read. Left alone, the button was a dead end: the
 * payload was always null, `JobRestorer::restore()` returned null, and every
 * retry answered "Payload might be missing or corrupted" — a route and a
 * permission that looked live and could never succeed.
 *
 * The framework stores the payload of a permanently failed job anyway, in the
 * same record `queue:retry` reads back — `queue:failer`, written by
 * `WorkCommand::logFailedJob()` on the final attempt. Its `uuid` column is the
 * queue payload's own uuid, which is the value Vantage records per attempt, so
 * the lookup is exact rather than a guess.
 *
 * ponytail: the two stores age differently. `vantage_jobs` is swept by
 * `vantage:prune` at `vantage.retention_days` (14), `failed_jobs` by
 * `queue:prune-failed` or an operator's `queue:flush` — so a row whose twin is
 * already gone refuses the retry instead of dispatching something else.
 * Revisit only if the two retentions are ever aligned.
 */
final class FailedJobPayloadRestorer extends JobRestorer
{
    /**
     * @param  FailedJobProviderInterface  $failedJobs  The `queue.failer` store
     *   (driver `database-uuids` on this install) — the same one `queue:retry`
     *   reads, so both retry paths answer from one copy of the payload.
     */
    public function __construct(private readonly FailedJobProviderInterface $failedJobs)
    {
    }

    /**
     * Restore the job a retry row points at.
     *
     * When Vantage holds a payload of its own, the package's own path runs
     * unchanged and is preferred. Otherwise the payload comes from the
     * failed-job store.
     *
     * @param  VantageJob  $job  The attempt row the retry button belongs to.
     * @param  string  $expectedJobClass  `$job->job_class`; the unserialize
     *   inside the parent is restricted to this class.
     * @return object|null The job instance, or null when no payload can be
     *   trusted — the vendor controller then refuses the retry.
     */
    public function restore(VantageJob $job, string $expectedJobClass): ?object
    {
        if (filled($job->payload)) {
            return parent::restore($job, $expectedJobClass);
        }

        $payload = $this->failedPayload((string) $job->uuid);

        if ($payload === null) {
            // Logged rather than silent: the two stores age differently, and
            // "this attempt's failed_jobs record is gone" is the one reason a
            // retry button shown to an operator cannot work.
            VantageLogger::info('Queue Monitor: no failed-job record for this retry', [
                'job_id' => $job->id,
                'uuid' => $job->uuid,
            ]);

            return null;
        }

        // Cloned, because the package's security checks are worth reusing and
        // they read `$job->payload`. Writing the framework's payload onto the
        // ROW would leave it dirty — one later `save()` anywhere would persist
        // exactly the payload ADR-022 keeps out of this table.
        $probe = clone $job;
        $probe->payload = ['raw_payload' => $payload];

        return parent::restore($probe, $expectedJobClass);
    }

    /**
     * The raw queue payload the framework logged for `$uuid`, decoded.
     *
     * `failed_jobs.payload` is the queue payload verbatim — the string
     * `queue:retry` re-pushes — so its serialized command sits under
     * `data.command`, which is where `raw_payload.data.command` above points.
     *
     * @return array<string, mixed>|null
     */
    private function failedPayload(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        $raw = $this->failedJobs->find($uuid)->payload ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
