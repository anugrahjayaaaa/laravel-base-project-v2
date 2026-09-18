<?php

namespace App\Traits\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;

trait HandlesPasswordResetFlow
{
    protected function sendResetLink(string $email, ?User $user, Request $request): void
    {
        Password::sendResetLink($request->only('email'));

        if ($user) {
            $this->audit('auth.password_reset_requested', $user, $user, [
                'ip' => $request->ip(),
            ]);
        }
    }

    protected function resetPassword(Request $request, ?User $user): string
    {
        return Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
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
    }

    protected function auditPasswordResetCompleted(string $status, ?User $user, Request $request): void
    {
        if ($status === Password::PASSWORD_RESET && $user) {
            $this->audit('auth.password_reset_completed', $user, $user, [
                'ip' => $request->ip(),
            ]);
        }
    }
}