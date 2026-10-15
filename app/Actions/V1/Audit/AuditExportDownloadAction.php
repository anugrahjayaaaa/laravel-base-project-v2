<?php

namespace App\Actions\V1\Audit;

use App\Models\AuditExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves one export file, to the person who asked for it (Phase 10, P10-E4).
 *
 * ## The ownership check is the whole reason this is an Action
 *
 * `audit.export` answers "may this person extract the audit trail at all". It
 * does not answer "may this person download extract #41", and the route only
 * carries the permission. Without this check, every operator holding the
 * permission can enumerate ids and pull another operator's file — which is the
 * entire table, including the rows about the person asking. That is the one IDOR
 * surface in this phase, and `docs/planning/phase-10-audit-trail.md` §10 names it.
 *
 * The check itself is `AuditExport::isOwnedBy()`. It was a private method here
 * until the retry route needed the same answer, and two copies of an ownership
 * check is one copy too many — the copy nobody updates is the route nobody tests.
 *
 * A superadmin bypasses it, matching `Gate::before()`: the role exists to be the
 * break-glass account, and a break-glass account that cannot open an export is
 * just a role that cannot finish an incident review.
 *
 * ## 410 for expired, not 404
 *
 * The file existed and was downloadable; it is gone now. A 404 would tell the
 * requester the id was wrong and send them looking for a typo. It is also the
 * status a client can act on — "ask again" rather than "keep trying".
 */
class AuditExportDownloadAction
{
    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 not yours,
     *                                                                 404 unknown id,
     *                                                                 410 expired,
     *                                                                 404 swept file
     */
    public function run(User $user, string $export): StreamedResponse
    {
        $row = AuditExport::query()->find($export);

        if ($row === null) {
            abort(404, 'Export not found.');
        }

        if (! $row->isOwnedBy($user)) {
            abort(403, 'This export belongs to another operator.');
        }

        if ($row->isExpired()) {
            abort(410, 'This export has expired and its file has been deleted.');
        }

        if ($row->path === null || ! Storage::disk('local')->exists($row->path)) {
            // The row outlived its file: the sweep unlinks the file before the
            // row is swept, and a restored backup can produce this. 404 rather
            // than 410 — the row is still inside its window, so there is nothing
            // expired to report.
            abort(404, 'The export file is no longer available. Request it again.');
        }

        return Storage::disk('local')->download($row->path, $row->downloadName());
    }
}
