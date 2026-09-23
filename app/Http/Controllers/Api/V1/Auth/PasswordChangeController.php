<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Actions\Auth\ChangePassword;
use App\Http\Requests\Auth\PasswordChangeRequest;
use Illuminate\Http\JsonResponse;

class PasswordChangeController extends Controller
{
    public function __invoke(
        PasswordChangeRequest $request,
        ChangePassword $action,
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
