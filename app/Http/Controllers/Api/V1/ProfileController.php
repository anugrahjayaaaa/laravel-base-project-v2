<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\V1\Auth\ChangePassword;
use App\Actions\V1\User\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProfileUpdateRequest;
use App\Http\Resources\Api\V1\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * API profile controller — get and update authenticated user profile.
 */
class ProfileController extends Controller
{
    /**
     * @param  UpdateUserAction  $updateAction
     * @param  ChangePassword  $changePasswordAction
     */
    public function __construct(
        private readonly UpdateUserAction $updateAction,
        private readonly ChangePassword $changePasswordAction,
    ) {}

    /**
     * Get the authenticated user's profile.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return $this->respond('', 200, [
            'user' => new UserResource(Auth::user()),
        ]);
    }

    /**
     * Update user profile (API). Changes password if provided;
     * requests email change if email was modified.
     *
     * @param  ProfileUpdateRequest  $request
     * @return JsonResponse
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        ($this->updateAction)->run($user, $data);

        if ($request->filled('password')) {
            ($this->changePasswordAction)->run(
                user: $user,
                currentPassword: $data['current_password'],
                newPassword: $data['password'],
            );
            $this->audit('auth.password_changed', $user, $user);
        }

        $this->audit('user.profile_updated', $user, $user);

        if ($request->filled('email') && $data['email'] !== $user->getOriginal('email')) {
            $this->audit('user.email_change_requested', $user, $user, ['pending_email' => $data['email']]);
            return $this->respond('Verification email sent to new email address.', 200, [
                'message' => 'Verification email sent to new email address.',
                'user' => new UserResource($user->fresh()),
            ]);
        }

        return $this->respond('Profile updated successfully.', 200, [
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }
}
