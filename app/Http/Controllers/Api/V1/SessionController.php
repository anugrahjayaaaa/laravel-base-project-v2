<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\ListUserSessionsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function __construct(
        private readonly ListUserSessionsAction $listSessionsAction,
    ) {}

    public function index(): JsonResponse
    {
        $tokens = $this->listSessionsAction->run(Auth::user())->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
            ];
        });

        return $this->respond('', 200, ['sessions' => $tokens]);
    }
}