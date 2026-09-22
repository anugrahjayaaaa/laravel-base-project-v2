<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\User\CreateUserAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\ForceDeleteUserAction;
use App\Actions\User\RestoreUserAction;
use App\Actions\User\UpdateUserAction;
use App\Actions\User\UserIndexAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UserQueryRequest;
use App\Http\Resources\Api\V1\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function __construct(
        private readonly CreateUserAction $createAction,
        private readonly UserIndexAction $indexAction,
        private readonly UpdateUserAction $updateAction,
        private readonly DeleteUserAction $deleteAction,
        private readonly RestoreUserAction $restoreAction,
        private readonly ForceDeleteUserAction $forceDeleteAction,
    ) {}

    public function index(UserQueryRequest $request): JsonResponse
    {
        $status = $request->validated('status') ?? 'active';

        $users = $this->indexAction->run(
            search: $request->validated('search'),
            status: $status,
            sort: $request->validated('sort', 'created_at'),
            direction: $request->validated('direction', 'desc'),
            perPage: $request->validated('per_page', 10),
        );

        return $this->respond('', 200, [
            'users' => UserResource::collection($users),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

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

    public function show(User $user): JsonResponse
    {
        return $this->respond('', 200, [
            'user' => new UserResource($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->updateAction->run($user, $request->validated());

        $this->audit('user.updated', $user, $request->user());

        return $this->respond('User updated successfully.', 200, [
            'message' => 'User updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->deleteAction->run($user, $request->user());

        $this->audit('user.deleted', $user, $request->user());

        return $this->respond('User deleted successfully.', 200);
    }

    public function forceDelete(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);

        $this->forceDeleteAction->run($user, $request->user());

        $this->audit('user.force_deleted', $user, $request->user());

        return $this->respond('User permanently deleted.', 200);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);

        $this->restoreAction->run($user);

        $this->audit('user.restored', $user, $request->user());

        return $this->respond('User restored successfully.', 200, [
            'message' => 'User restored successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }
}
