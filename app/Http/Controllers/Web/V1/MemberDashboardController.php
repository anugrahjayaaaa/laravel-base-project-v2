<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Member\MemberDashboardAction;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Membership dashboard (Phase 18, read-only slice — the broadcast trigger
 * ships in B6 and queues its work per C2).
 */
class MemberDashboardController extends Controller
{
    public function __construct(
        private readonly MemberDashboardAction $dashboardAction,
    ) {
    }

    public function index(): View
    {
        return view('pages.members.dashboard', $this->dashboardAction->run());
    }
}
