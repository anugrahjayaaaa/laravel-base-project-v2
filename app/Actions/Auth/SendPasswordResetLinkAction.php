<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
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

        Password::sendResetLink($request->only('email'));

        return ['user' => $user];
    }
}