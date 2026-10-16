<?php

namespace App\Jobs\Concerns;

/**
 * The dispatch-time correlation ID, read from the job payload.
 *
 * `GenerateRequestCorrelationId` binds `request_id` on the REQUEST, and the
 * worker that later runs this job is a different process where that binding
 * never happened — so the container read the rest of the application uses
 * (`app()->bound('request_id')`, see `Auditable`) answers null exactly where
 * the correlation matters most. `AppServiceProvider` puts the id into every
 * payload with `Queue::createPayloadUsing()`, and this is the read.
 *
 * Null is still the honest answer for a job with no request behind it: a
 * sweep dispatched by the scheduler carries no request id, so the key reads
 * null rather than being absent, and a log consumer can rely on the field
 * always being present.
 *
 * Phase 11 P11-E3 — `docs/base/infrastructure/queue.md` requires a failure to
 * be observable as event name + exception + request_id.
 */
trait ReadsCorrelationId
{
    /**
     * The request id this job was dispatched under, or null.
     *
     * `$this->job` (from `InteractsWithQueue`) is null when `failed()` is
     * called directly — a test or a manual replay — hence the null-safe chain,
     * and old payloads pushed before the hook existed read null the same way.
     */
    protected function requestId(): ?string
    {
        $id = $this->job?->payload()['request_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
