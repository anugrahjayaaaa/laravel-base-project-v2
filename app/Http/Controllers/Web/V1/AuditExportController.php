<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Audit\AuditExportDownloadAction;
use App\Actions\V1\Audit\AuditExportRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Audit\ActivityQueryRequest;
use App\Http\Requests\V1\Audit\AuditExportRequest;
use Illuminate\Contracts\View\View;
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
     *
     * ## `status`, not `success`
     *
     * `status` is the house flash key for an admin action — `UserController`
     * uses it on every index redirect, and every index view carries its own
     * `@if (session('status'))` block (`pages/users/index` and five others).
     * `success` is the auth-flow key. A flash nobody's view renders is a flash
     * the browser is told about and the operator never sees, which is the same
     * invisible failure as a form that posts and does nothing.
     */
    public function store(
        AuditExportRequest $request,
        AuditExportRequestAction $requestAction,
    ): RedirectResponse {
        $filters = $request->exportFilters();

        $requestAction->run(
            $request->user(),
            $filters,
            $filters['direction'] ?? 'desc',
        );

        return back()
            ->with('status', 'Your export is being prepared. We will notify you when it is ready to download.');
    }

    /**
     * The viewer's own exports, as a bare HTML fragment.
     *
     * ## Why this is not the index page
     *
     * The audit page renders once per request, so a `pending` row stays `pending`
     * on screen until somebody reloads — while the notification that the job
     * finished has already updated the bell. The operator gets a signal and then
     * has to reload to act on it. This endpoint is what lets the row resolve in
     * place.
     *
     * The SAME partial, re-rendered with the SAME scoped action, is the answer
     * rather than a JSON payload plus a second renderer in JavaScript: there is
     * one place that decides what a `pending` row looks like, and a fragment that
     * is rendered by that place cannot drift from the page. `layouts/partials/
     * notification-preview` already established this shape.
     *
     * ## `audit.view`, not `audit.export`
     *
     * The permission that guards the PAGE is what guards this, because this
     * returns part of that page. The data itself is already scoped to the
     * viewer inside `recentFor()` — a viewer without `audit.export` simply has
     * nothing of their own to see here, which is the same answer the page gives.
     *
     * Throttled like the bell's poll: one tab polling every few seconds is a
     * handful of requests a minute, and the ceiling exists to stop a stuck retry
     * loop or a hostile client turning a table refresh into load.
     */
    public function recent(Request $request, AuditExportRequestAction $exportAction): View
    {
        return view('pages.activity-logs._exports', [
            'myExports' => $exportAction->recentFor($request->user()),
            // The partial reads this to label a file with the narrowing filters
            // only. It is passed here as well as on the index because this
            // endpoint renders the partial on its own, and a fragment that
            // depended on a variable only its parent supplied is a 500 waiting
            // for the first poll.
            'filterKeys' => ActivityQueryRequest::FILTER_KEYS,
        ]);
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
