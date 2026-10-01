<?php

namespace App\Actions\V1\User;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Admin-triggered resend of email verification notification.
 */
class UserAdminResendVerificationAction
{
    /**
     * Resend the verification email to the user.
     *
     * @param  User   $user
     * @param  string $ip
     * @return array
     */
    public function run(User $user, string $ip): array
    {
        // The single gate for both the web and the API caller. `disabled` means
        // nobody may send, whoever is asking — gating only the controller left
        // the API endpoint able to send in a mode that claims it cannot.
        // `admin` is deliberately allowed: reissuing a link is the whole point
        // of that mode.
        if (SystemSetting::getString('email_verification_mode', 'public') === 'disabled') {
            return ['error' => ['message' => 'Verification emails are disabled.', 'status' => 403]];
        }

        if ($user->hasVerifiedEmail()) {
            return ['error' => ['message' => 'Email already verified.', 'status' => 422]];
        }

        $key = $this->key($user->email, $ip);
        $maxAttempts = SystemSetting::getInt('email_verification_rate_limit', 5);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return ['error' => ['message' => "Too many requests. Try again in " . (int) ceil($seconds / 60) . " minute(s).", 'status' => 429]];
        }

        $user->sendEmailVerificationNotification();
        RateLimiter::hit($key, 3600);

        return ['user' => $user];
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
