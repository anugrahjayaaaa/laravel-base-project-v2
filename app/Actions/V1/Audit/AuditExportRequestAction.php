<?php

namespace App\Actions\V1\Audit;

use App\Jobs\GenerateAuditExport;
use App\Models\AuditExport;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Records an export request and queues the job (Phase 10, P10-E4).
 *
 * It also answers the other half of the same question — "where are my exports?" —
 * because the audit page is where an operator looks for the file they just asked
 * for. Splitting that into a second action class would be one method in a file
 * whose name has no verb for it.
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
     * The viewer's own most recent exports, newest first.
     *
     * ## Scoped to the viewer, always
     *
     * The same rule the download route enforces, applied to the LIST: an export
     * is one person's extract of the audit trail, and a shared list would put
     * every operator's file on every operator's screen. `audit.export` is not
     * re-checked here — the route already gated the page, and a viewer without it
     * has no exports to see.
     *
     * Five rows, not a paginator: this is a shortcut to the file you just
     * requested, not a place to manage exports. The notification and the mail are
     * the durable records; this answers "did I already do that?".
     *
     * @return Collection<int, AuditExport>
     */
    public function recentFor(User $user): Collection
    {
        return AuditExport::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * Record the request and queue the job.
     *
     * @param  array<string, mixed>  $filters  as AuditExportRequest::exportFilters()
     */
    public function run(User $user, array $filters, string $direction = 'desc'): AuditExport
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
            $direction,
        )->afterCommit();

        return $export;
    }
}
