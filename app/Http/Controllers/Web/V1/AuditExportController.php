<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Audit\AuditExportDownloadAction;
use App\Actions\V1\Audit\AuditExportRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Audit\AuditExportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit export surface (Phase 10 Group E, routes added by P10-B4).
 *
 * ## Two verbs, two different gates
 *
 * The POST is `audit.export` and nothing else: asking for a file is a permission
 * question, and the rate limiter beside it answers "how often". The GET is also
 * `audit.export` PLUS an ownership check inside the action, because that route
 * takes an id and a permission alone does not say whose id it is.
 *
 * ## No audit call in either method
 *
 * Same rule as the viewer: a read writes nothing. The row that records "an
 * administrator exported the audit trail" would be a row in the file the
 * administrator just extracted, which is a strange thing to hand someone.
 */
class AuditExportController extends Controller
{
    /**
     * Queue an export of the current filters.
     *
     * Redirects rather than returning JSON: the caller is a form on the audit
     * page, and a 202 with a body nobody renders is a form that looks broken.
     * The flash says what happens next, because the file is not there yet.
     */
    public function store(
        AuditExportRequest $request,
        AuditExportRequestAction $requestAction,
    ): RedirectResponse {
        $filters = $request->exportFilters();

        $requestAction->run(
            $request->user(),
            $filters,
            $filters['sort'] ?? 'created_at',
            $filters['direction'] ?? 'desc',
        );

        return back()
            ->with('success', 'Your export is being prepared. We will notify you when it is ready to download.');
    }

    /**
     * Stream one export file.
     *
     * `AuditExportDownloadAction` owns the ownership check, the expiry answer and
     * the streamed response — see there for why each status code is what it is.
     */
    public function download(Request $request, AuditExportDownloadAction $downloadAction, string $export): StreamedResponse
    {
        return $downloadAction->run($request->user(), $export);
    }
}
