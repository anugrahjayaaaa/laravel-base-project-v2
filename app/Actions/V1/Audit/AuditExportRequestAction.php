<?php

namespace App\Actions\V1\Audit;

use App\Enums\AuditExportStatus;
use App\Jobs\GenerateAuditExport;
use App\Models\AuditExport;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
 * `->afterCommit()` at the dispatch site, because every queue connection here
 * runs `after_commit => false`. Dispatching inside a transaction would let a
 * worker run `findOrFail()` before this insert is visible and fail the job on
 * the row it was created for.
 *
 * It is set HERE rather than as a property on the job:
 * `Illuminate\Bus\Queueable` already declares `public $afterCommit`, and PHP
 * refuses a redeclaration whose default differs — a property on the job is a
 * fatal at class-load, not a lint error. `GenerateAuditExport` says so at the
 * property it deliberately does not declare.
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

    /**
     * Run one failed export again, on its own row.
     *
     * ## The SAME row, never a new one
     *
     * The row is the request — it carries the filters, the owner and the window.
     * A retry that cloned the row would be a second export with a second id and a
     * second file, and the failed row it was meant to replace would stay in the
     * list forever saying `Failed` next to a file that does exist. Reusing the row
     * is also what keeps `diskPath()` honest: the path is keyed on the id, so the
     * new CSV OVERWRITES the old one rather than sitting beside it.
     *
     * ## `failed` is a precondition, not a nicety
     *
     * Re-dispatching a `pending` row is the one genuinely dangerous version of this
     * action: two workers then build the same file concurrently and the second
     * `row_count` overwrites the first, so the number reported is whichever
     * finished last. Re-dispatching a `ready` row is merely pointless. Both are
     * refused with 409 rather than absorbed.
     *
     * The precondition is enforced TWICE, because reading it is not enforcing it:
     * the aborts below answer the ordinary request from a snapshot, and two
     * simultaneous POSTs can both read `failed` before either of them writes. The
     * `UPDATE` below is the one that actually decides — it carries the same
     * conditions in its `WHERE`, so the database lets exactly one request claim
     * the row and the loser is refused instead of dispatching a second job.
     *
     * ## The cap is enforced HERE, not in the view
     *
     * The Blade hides the button past `AuditExport::maxRetries()` so the operator is not offered
     * something that will be refused. That is presentation. A POST sent straight to
     * the URL is the actual control point, and it is the API client that will send
     * it — a cap enforced only in a template is a cap with a hole shaped like an
     * HTTP request.
     *
     * ## `expires_at` is deliberately NOT extended
     *
     * The migration says the window is seven days FROM THE REQUEST, and that is
     * the retention rule this project settled (`docs/base/operations/retention.md`).
     * A retry on day six therefore produces a file that is nearly expired on
     * arrival. That is the honest reading of the rule, not an oversight; moving the
     * window is a retention decision, and it should not happen as a side effect of
     * clicking a button.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 404 unknown id,
     *                                                                 403 not yours,
     *                                                                 409 nothing to retry
     */
    public function retry(User $user, string $export): AuditExport
    {
        $row = AuditExport::query()->find($export);

        abort_if($row === null, 404, 'Export not found.');
        abort_unless($row->isOwnedBy($user), 403, 'This export belongs to another operator.');
        abort_if($row->status !== AuditExportStatus::FAILED, 409, 'This export is not in a failed state.');
        abort_if($row->isRetryExhausted(), 409, 'This export has already been retried '.$row->retry_count.' times. Please contact an administrator.');

        // The claim. Four conditions, one statement, no transaction: a single
        // UPDATE is atomic on every driver this runs on, so two requests that both
        // passed the aborts above still cannot both win. Without it the guard in
        // the docblock is a TOCTOU — both read `failed`, both write `pending`, and
        // two workers build the same path while the loser's `N+1` erases the
        // winner's click. A loser here affects 0 rows and is refused below.
        $claimed = AuditExport::query()
            ->whereKey($row->getKey())
            ->where('status', AuditExportStatus::FAILED)
            ->where('retry_count', $row->retry_count)
            ->update([
                'status' => AuditExportStatus::PENDING,
                // Cleared, not merely overwritten later: `isDownloadable()` reads all
                // three, and a `pending` row carrying yesterday's `path` would answer
                // true until the job finished.
                'path' => null,
                'row_count' => null,
                'completed_at' => null,
                'retry_count' => $row->retry_count + 1,
            ]);

        abort_if($claimed === 0, 409, 'This export changed while you were retrying it. Refresh the page and try again.');

        $row->refresh();

        GenerateAuditExport::dispatch(
            $row->getKey(),
            $row->filters,
            $row->filters['direction'] ?? 'desc',
        )->afterCommit();

        // The one place an operator learns WHICH export stopped working. The job's
        // own `attempt_failed` line carries the exception, but it is keyed on an id
        // nobody sees from the UI.
        Log::info('audit.export.retried', [
            'export_id' => $row->getKey(),
            'user_id' => $row->user_id,
            'retry' => $row->retry_count,
            'max_retries' => AuditExport::maxRetries(),
        ]);

        if ($row->isRetryExhausted()) {
            // The last click, not the first refusal: an admin looking for the rows
            // that need a human gets this one from the log rather than by asking
            // every user to recount their own clicks.
            Log::warning('audit.export.retries_exhausted', [
                'export_id' => $row->getKey(),
                'user_id' => $row->user_id,
            ]);
        }

        return $row;
    }
}
