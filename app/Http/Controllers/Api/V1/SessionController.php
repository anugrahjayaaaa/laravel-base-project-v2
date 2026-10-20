<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\V1\Auth\AuthListSessionsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Session\SessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * API session controller, list active tokens.
 */
class SessionController extends Controller
{
    /**
     * @param  AuthListSessionsAction  $listSessionsAction
     */
    public function __construct(
        private readonly AuthListSessionsAction $listSessionsAction,
    ) {
    }

    /**
     * List the authenticated user's active sessions.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $tokens = $this->listSessionsAction->run(Auth::user());

        return $this->respond('', 200, ['sessions' => SessionResource::collection($tokens)]);
    }
}
