<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\SendPasswordResetLinkAction;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordForgotRequest;
use Illuminate\Http\JsonResponse;

/**
 * API auth controller — send password reset link.
 */
class PasswordForgotController extends Controller
{
    /**
     * Send a password reset link to the given email.
     *
     * @param  PasswordForgotRequest  $request
     * @param  LoginThrottle  $throttle
     * @param  SendPasswordResetLinkAction  $action
     * @return JsonResponse
     */
    public function __invoke(
        PasswordForgotRequest $request,
        LoginThrottle $throttle,
        SendPasswordResetLinkAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $email = $data['email'];
        $ip = $request->ip();
        $result = $action->run($email, $ip, $request, $throttle);

        if (isset($result['error'])) {
            $this->audit('auth.password_reset_requested', $result['user'], $result['user'], [
                'ip' => $ip,
            ]);

            return response()->json([
                'message' => 'If the email exists, a reset link has been sent.',
                'code' => 'ACCOUNT_LOCKED',
            ], 403);
        }

        if ($result['user']) {
            $this->audit('auth.password_reset_requested', $result['user'], $result['user'], [
                'ip' => $ip,
            ]);
        }

        return response()->json([
            'data' => ['message' => 'If the email exists, a reset link has been sent.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
