<?php

namespace App\Http\Controllers\Web\V1;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Dashboard controller — renders the main dashboard view.
 */
class DashboardController extends Controller
{
    /**
     * Render the dashboard page.
     *
     * @param  Request  $request
     * @return \Illuminate\Contracts\View\View
     */
    public function __invoke(Request $request)
    {
        return view('pages.dashboard', [
            'title' => 'Dashboard',
            'user' => $request->user(),
        ]);
    }
}
