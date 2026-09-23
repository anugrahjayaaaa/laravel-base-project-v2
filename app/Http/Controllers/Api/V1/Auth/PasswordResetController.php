<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\ResetPasswordAction;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    public function __invoke(
        PasswordResetRequest $request,
        LoginThrottle $throttle,
        ResetPasswordAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $email = $data['email'];
        $ip = $request->ip();
        $result = $action->run($email, $ip, $request, $throttle);

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error']['message'],
                'code' => 'RESET_TOKEN_INVALID',
            ], $result['error']['status']);
        }

        if ($result['status'] === Password::PASSWORD_RESET && $result['user']) {
            $this->audit('auth.password_reset_completed', $result['user'], $result['user'], [
                'ip' => $ip,
            ]);
        }

        if ($result['status'] === Password::PASSWORD_RESET) {
            return response()->json([
                'data' => ['message' => 'Password reset successfully.'],
                'meta' => [
                    'request_id' => app('request_id'),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        }

        return response()->json([
            'message' => 'Invalid or expired token.',
            'code' => 'RESET_TOKEN_INVALID',
        ], 400);
    }
}
