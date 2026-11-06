<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Member\MemberDashboardAction;
use App\Actions\V1\Member\MemberExpiryBroadcastAction;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Membership dashboard and its bulk expiry warning trigger (Phase 18).
 */
class MemberDashboardController extends Controller
{
    public function __construct(
        private readonly MemberDashboardAction $dashboardAction,
        private readonly MemberExpiryBroadcastAction $broadcastAction,
    ) {
    }

    public function index(): View
    {
        return view('pages.members.dashboard', $this->dashboardAction->run());
    }

    /**
     * Queue the expiring-members warning run. The scan and every mail happen
     * on a worker; this request only resolves the window and dispatches (C2).
     * No request data to read: the route's `can:members.notify` is the gate.
     */
    public function broadcastExpiry(): RedirectResponse
    {
        $this->broadcastAction->run();

        return back()->with('status', 'Expiry notifications queued.');
    }
}
