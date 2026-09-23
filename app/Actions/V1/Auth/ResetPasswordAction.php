<?php

namespace App\Actions\V1\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Reset a user's password via token with expiration enforcement.
 */
class ResetPasswordAction
{
    /**
     * Reset the user's password using Laravel's Password broker.
     *
     * @param  string        $email
     * @param  string        $ip
     * @param  Request       $request
     * @param  LoginThrottle $throttle
     * @return array         ['user' => User, 'status' => string] or ['error' => array]
     */
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

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),

            function ($user, string $password) {
                $days = SystemSetting::getInt('auth_password_expiration_days', 90);
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'password_expires_at' => now()->addDays($days),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return ['user' => $user, 'status' => $status];
        }

        return ['error' => ['message' => 'Invalid or expired token.', 'status' => 400], 'user' => $user];
    }
}
