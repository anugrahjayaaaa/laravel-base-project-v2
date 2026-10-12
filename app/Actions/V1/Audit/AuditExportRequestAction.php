<?php

namespace App\Actions\V1\Audit;

use App\Jobs\GenerateAuditExport;
use App\Models\AuditExport;
use App\Models\User;

/**
 * Records an export request and queues the job (Phase 10, P10-E4).
 *
 * ## The row before the job, never the other way round
 *
 * The job needs a row to fill in, and the download route needs a row to check
 * ownership against. Writing it here, inside the same call, means the two can
 * never disagree — and a `pending` row exists the moment the user sees a
 * confirmation, instead of after a worker happens to pick the job up.
 *
 * ## The dispatch is after commit
 *
 * `afterCommit = true` on the job, because every queue connection here runs
 * `after_commit => false`. Dispatching inside a transaction would let a worker run
 * `findOrFail()` before this insert is visible and fail the job on the row it was
 * created for.
 */
class AuditExportRequestAction
{
    /**
     * Record the request and queue the job.
     *
     * @param  array<string, mixed>  $filters  as AuditExportRequest::exportFilters()
     */
    public function run(User $user, array $filters, string $sort = 'created_at', string $direction = 'desc'): AuditExport
    {
        $export = AuditExport::query()->create([
            'user_id' => $user->getKey(),
            'filters' => $filters,
            // Set here rather than at completion: the download window is the
            // requester's seven days from the moment they asked, and the download
            // route must be able to answer "is this expired" without knowing when
            // the job finished.
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        GenerateAuditExport::dispatch(
            $export->getKey(),
            $filters,
            $sort,
            $direction,
        )->afterCommit();

        return $export;
    }
}
