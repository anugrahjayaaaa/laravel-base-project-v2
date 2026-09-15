<?php

namespace App\Http\Controllers\Web;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

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
