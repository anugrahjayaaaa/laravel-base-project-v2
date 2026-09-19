<?php

namespace App\Http\Controllers\Web\V1;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        return view('pages.dashboard', [
            'title' => 'Dashboard',
            'user' => $request->user(),
        ]);
    }
}
