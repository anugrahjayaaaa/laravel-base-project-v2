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

        $action->run(
            user: $user,
            currentPassword: $request->currentPassword(),
            newPassword: $request->password(),
        );

        $this->audit('auth.password_change.completed', $user, $user);

        return response()->json([
            'data' => ['message' => 'Password changed successfully.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
