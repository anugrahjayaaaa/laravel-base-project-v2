<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\LogoutAllDevicesAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API auth controller — logout all devices.
 */
class LogoutAllController extends Controller
{
    /**
     * Log out all devices for the current user.
     *
     * @param  Request  $request
     * @param  LogoutAllDevicesAction  $action
     * @return JsonResponse
     */
    public function __invoke(Request $request, LogoutAllDevicesAction $action): JsonResponse
    {
        $action->run($request->user());

        $this->audit('auth.logout_all', $request->user(), $request->user());

        return $this->respond('All devices logged out successfully.');
    }
}
