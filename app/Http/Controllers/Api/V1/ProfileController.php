<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\ChangePassword;
use App\Actions\User\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProfileUpdateRequest;
use App\Http\Resources\Api\V1\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function __construct(
        private readonly UpdateUserAction $updateAction,
        private readonly ChangePassword $changePasswordAction,
    ) {}

    public function show(): JsonResponse
    {
        return $this->respond('', 200, [
            'user' => new UserResource(Auth::user()),
        ]);
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->safe()->only(['name', 'username', 'email']);

        ($this->updateAction)->run($user, $data);

        if ($request->filled('password')) {
            ($this->changePasswordAction)->run(
                user: $user,
                currentPassword: $request->input('current_password'),
                newPassword: $request->input('password'),
            );
            $this->audit('auth.password_changed', $user, $user);
        }

        $this->audit('user.profile_updated', $user, $user);

        return $this->respond('Profile updated successfully.', 200, [
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }
}