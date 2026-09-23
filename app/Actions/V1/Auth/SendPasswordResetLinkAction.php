<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class SendPasswordResetLinkAction
{
    public function run(
        string $email,
        string $ip,
        Request $request,
        LoginThrottle $throttle,
    ): array {
        $user = User::where('email', $email)->first();

        if ($user && $throttle->isLocked($user->email, $ip)) {
            return ['error' => ['message' => 'Account is locked.', 'status' => 403], 'user' => $user];
        }

        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Exception $e) {
            Log::error('Send password reset link failed', ['email' => $email, 'error' => $e->getMessage()]);

            return ['error' => ['message' => 'Failed to send reset link. Please try again later.', 'status' => 500]];
        }

        return ['user' => $user];
    }
}