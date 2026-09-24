<?php

namespace App\Actions\V1\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Resend email verification notification with rate limiting.
 */
class ResendVerificationAction
{
    /**
     * Send a new verification email if the user hasn't verified yet.
     *
     * @param  string  $email
     * @param  string  $ip
     * @return array
     */
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
        $maxAttempts = SystemSetting::getInt('email_verification_rate_limit', 5);

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

    /**
     * Generate a rate-limit key for the email/IP pair.
     *
     * @param  string  $email
     * @param  string  $ip
     * @return string
     */
    protected function key(string $email, string $ip): string
    {
        return sha1(Str::lower(trim($email)) . '|' . $ip);
    }
}
