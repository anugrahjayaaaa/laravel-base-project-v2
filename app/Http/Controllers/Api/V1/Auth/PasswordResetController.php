<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Auth\PasswordResetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use Spatie\Activitylog\Facades\Activity;

class PasswordResetController
{
    public function __invoke(PasswordResetRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                // Update password.
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'password_expires_at' => now()->addDays(
                        (int) config('rate_limits.password_expiration_days', 90)
                    ),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // Laravel returns Password::INVALID_USER, Password::INVALID_TOKEN, etc.
        // Map to appropriate responses.
        if ($status === Password::PASSWORD_RESET) {
            activity('auth.password_reset_completed')
                ->causedBy(null)
                ->withProperties(['ip' => $request->ip()])
                ->log('auth.password_reset_completed');

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
