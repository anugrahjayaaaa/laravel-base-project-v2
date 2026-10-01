<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Actions\V1\Auth\AuthChangePasswordAction;
use App\Http\Requests\V1\Auth\PasswordChangeRequest;
use Illuminate\Http\JsonResponse;

/**
 * API auth controller, change password.
 */
class PasswordChangeController extends Controller
{
    /**
     * Change the authenticated user's password.
     *
     * @param  PasswordChangeRequest  $request
     * @param  AuthChangePasswordAction  $action
     * @return JsonResponse
     */
    public function __invoke(
        PasswordChangeRequest $request,
        AuthChangePasswordAction $action,
    ): JsonResponse {
        $user = $request->user();
        $data = $request->validated();

        $action->run(
            user: $user,
            currentPassword: $data['current_password'],
            newPassword: $data['password'],
        );

        $this->audit('auth.password_changed', $user, $user);

        return response()->json([
            'data' => ['message' => 'Password changed successfully.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
