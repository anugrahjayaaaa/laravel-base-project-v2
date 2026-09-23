<?php

namespace App\Actions\V1\User;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminResendVerificationAction
{
    public function run(User $user, string $ip): array
    {
        if ($user->hasVerifiedEmail()) {
            return ['error' => ['message' => 'Email already verified.', 'status' => 422]];
        }

        $key = $this->key($user->email, $ip);
        $maxAttempts = SystemSetting::getInt('auth_email_verification_rate_limit', 5);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return ['error' => ['message' => "Too many requests. Try again in " . (int) ceil($seconds / 60) . " minute(s).", 'status' => 429]];
        }

        $user->sendEmailVerificationNotification();
        RateLimiter::hit($key, 3600);

        return ['user' => $user];
    }

    protected function key(string $email, string $ip): string
    {
        return sha1(Str::lower(trim($email)) . '|' . $ip);
    }
}