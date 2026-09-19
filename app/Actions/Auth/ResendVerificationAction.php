<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ResendVerificationAction
{
    public function run(string $email, string $ip): array
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return ['success' => true];
        }

        if ($user->hasVerifiedEmail()) {
            return ['error' => ['message' => 'Email already verified.', 'status' => 422]];
        }

        $key = $this->key($email, $ip);
        $maxAttempts = (int) config('rate_limits.email_verification.rate_limit_per_hour', 5);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return ['error' => ['message' => "Too many requests. Try again in " . (int) ceil($seconds / 60) . " minute(s).", 'status' => 429]];
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Exception $e) {
            \Log::error('Resend verification email failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return ['error' => ['message' => 'Failed to send verification email. Please try again later.', 'status' => 500]];
        }

        RateLimiter::hit($key, 3600);

        return ['success' => true];
    }

    protected function key(string $email, string $ip): string
    {
        return sha1(Str::lower(trim($email)) . '|' . $ip);
    }
}