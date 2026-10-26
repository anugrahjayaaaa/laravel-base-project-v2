<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\AuthLogoutAllDevicesAction;
use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API auth controller, logout all devices.
 */
class LogoutAllController extends Controller
{
    /**
     * Log out all devices for the current user.
     *
     * 403 is the `feature:sessions` kill switch, which refuses before this method
     * runs and is therefore invisible to inference.
     *
     * @param  Request  $request
     * @param  AuthLogoutAllDevicesAction  $action
     * @return JsonResponse
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    public function __invoke(Request $request, AuthLogoutAllDevicesAction $action): JsonResponse
    {
        $action->run($request->user());

        return $this->respond('All devices logged out successfully.');
    }
}
