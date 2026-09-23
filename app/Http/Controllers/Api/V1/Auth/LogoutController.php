<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API auth controller — logout current token.
 */
class LogoutController extends Controller
{
    /**
     * Delete the current access token (logout).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        $this->audit('auth.logout', $request->user(), $request->user());

        return $this->respond('Logged out successfully.');
    }
}
