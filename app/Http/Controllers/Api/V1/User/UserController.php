<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\User\CreateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly CreateUserAction $createAction,
    ) {}

    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->createAction->run($request->validated());

        $this->audit('user.created', $user, $request->user());

        return response()->json([
            'data' => ['message' => 'User created successfully.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ], 201);
    }
}
