<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\AuthLogoutAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API auth controller, logout current token.
 */
class LogoutController extends Controller
{
    /**
     * Delete the current access token (logout).
     *
     * @param  Request  $request
     * @param  AuthLogoutAction  $action
     * @return JsonResponse
     */
    public function __invoke(Request $request, AuthLogoutAction $action): JsonResponse
    {
        $action->run($request);

        return $this->respond('Logged out successfully.');
    }
}
