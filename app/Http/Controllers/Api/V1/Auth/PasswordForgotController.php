<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Auth\PasswordForgotRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordForgotController
{
    public function __invoke(PasswordForgotRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Always return the same response regardless of email existence.
        // Prevents user-enumeration via timing or response differences.
        activity('auth.password_reset_requested')
            ->causedBy(null)
            ->withProperties(['ip' => $request->ip()])
            ->log('auth.password_reset_requested');

        return response()->json([
            'data' => ['message' => 'If the email exists, a reset link has been sent.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
