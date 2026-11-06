<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Member\MemberSelfDashboardAction;
use App\Models\Member;
use App\Support\FeatureCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Dashboard controller, renders the main dashboard view.
 *
 * An account with a loyalty profile gets its own dashboard instead, and only
 * while `member_loyalty` is on: the flag is the module's kill switch, so with
 * it off this route must not read the module's tables.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly MemberSelfDashboardAction $selfDashboardAction,
    ) {
    }

    public function __invoke(Request $request): View
    {
        $member = $request->user()?->member;

        if ($member instanceof Member && FeatureCatalog::isActive('member_loyalty')) {
            return view('pages.members.my-dashboard', $this->selfDashboardAction->run($member));
        }

        return view('pages.dashboard', [
            'title' => 'Dashboard',
            'user' => $request->user(),
        ]);
    }
}
