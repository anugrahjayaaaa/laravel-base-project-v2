<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Member\MemberAnalyticsStatsAction;
use App\Actions\V1\Member\MemberDashboardAction;
use App\Actions\V1\Member\MemberExpiryBroadcastAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Member\MemberAnalyticsQueryRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Membership dashboard and its bulk expiry warning trigger (Phase 18).
 */
class MemberDashboardController extends Controller
{
    public function __construct(
        private readonly MemberDashboardAction $dashboardAction,
        private readonly MemberAnalyticsStatsAction $analyticsAction,
        private readonly MemberExpiryBroadcastAction $broadcastAction,
    ) {
    }

    /**
     * The A3 counters and the Group F analytics share one screen, so the view
     * model is the merge of the two reads. The timeframe arrives from the query
     * string through the Form Request — nothing is remembered in the session.
     */
    public function index(MemberAnalyticsQueryRequest $request): View
    {
        return view('pages.members.dashboard', array_merge(
            $this->dashboardAction->run(),
            $this->analyticsAction->run($request->window()),
        ));
    }

    /**
     * Queue the expiring-members warning run. The scan and every mail happen
     * on a worker; this request only resolves the window, records who fired it
     * and dispatches (C2). No request body to read: the route's
     * `can:members.notify` is the gate.
     */
    public function broadcastExpiry(Request $request): RedirectResponse
    {
        $this->broadcastAction->run($request->user() ?? abort(403));

        return back()->with('status', 'Expiry notifications queued.');
    }
}
