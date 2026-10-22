<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\V1\Auth\AuthListSessionsAction;
use App\Actions\V1\Auth\AuthRevokeSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Session\SessionResource;
use App\Support\ApiError;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * API session controller, list and revoke the caller's own tokens.
 */
class SessionController extends Controller
{
    /**
     * @param  AuthListSessionsAction    $listSessionsAction
     * @param  AuthRevokeSessionAction   $revokeSessionAction
     */
    public function __construct(
        private readonly AuthListSessionsAction $listSessionsAction,
        private readonly AuthRevokeSessionAction $revokeSessionAction,
    ) {
    }

    /**
     * List the authenticated user's active sessions.
     *
     * 403 is the `feature:sessions` kill switch.
     *
     * @return JsonResponse
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    public function index(): JsonResponse
    {
        $tokens = $this->listSessionsAction->run(Auth::user());

        return $this->respond('', 200, ['sessions' => SessionResource::collection($tokens)]);
    }

    /**
     * Revoke one of the caller's own sessions.
     *
     * 404 covers both "no such token" and "someone else's token" on purpose —
     * the second answer would tell an authenticated caller which ids exist.
     *
     * @param  Request  $request
     * @param  string  $session
     * @return JsonResponse
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    #[Response(status: 404, type: ApiError::DOC_TYPE)]
    public function destroy(Request $request, string $session): JsonResponse
    {
        $this->revokeSessionAction->run($request->user(), $session);

        return $this->respond('Session revoked successfully.', 200);
    }
}
