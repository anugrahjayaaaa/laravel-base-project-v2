<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\V1\Auth\AuthChangePasswordAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\User\ProfileUpdateRequest;
use App\Http\Resources\Api\V1\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * API profile controller, get and update authenticated user profile.
 */
class ProfileController extends Controller
{
    /**
     * @param  UserUpdateAction  $updateAction
     * @param  AuthChangePasswordAction  $changePasswordAction
     */
    public function __construct(
        private readonly UserUpdateAction $updateAction,
        private readonly AuthChangePasswordAction $changePasswordAction,
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
        }

        // No audit calls here — the actions own their rows, inside their own
        // transactions. See Web\V1\ProfileController::update() for the same
        // three events and why none of them belongs at the controller.

        if ($request->filled('email') && $data['email'] !== $user->getOriginal('email')) {
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
