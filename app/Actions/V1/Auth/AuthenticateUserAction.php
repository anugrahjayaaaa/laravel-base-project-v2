<?php

namespace App\Actions\V1\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Support\Facades\Hash;

/**
 * Authenticate a user by email/username and password with throttle and state checks.
 */
class AuthenticateUserAction
{
    /**
     * Attempt to authenticate a user.
     *
     * @param  string        $identifier  Email or username.
     * @param  string        $password
     * @param  string        $ip
     * @param  LoginThrottle $throttle
     * @return array       ['user' => User] or ['error' => array, 'lockedSeconds' => int]
     */
    public function run(
        string $identifier,
        string $password,
        string $ip,
        LoginThrottle $throttle,
    ): array {
        $throttleError = $this->checkThrottle($identifier, $ip, $throttle);

        if ($throttleError) {
            return ['error' => $throttleError];
        }

        $user = $this->findUser($identifier, $password);

        if (! $user) {
            $lockedSeconds = $throttle->recordFailed($identifier, $ip, $user);

            return ['error' => ['message' => 'Invalid credentials.', 'status' => 401], 'lockedSeconds' => $lockedSeconds];
        }

        $accountError = $this->checkAccountState($user);

        if ($accountError) {
            return ['error' => $accountError, 'user' => $user];
        }

        $verificationError = $this->checkEmailVerification($user);

        if ($verificationError) {
            return ['error' => $verificationError];
        }

        return ['user' => $user];
    }

    /**
     * Check if the login is currently throttled/locked.
     *
     * @param  string        $identifier
     * @param  string        $ip
     * @param  LoginThrottle $throttle
     * @return array|null    Error array or null.
     */
    protected function checkThrottle(string $identifier, string $ip, LoginThrottle $throttle): ?array
    {
        if ($throttle->isLocked($identifier, $ip)) {
            $lockedSeconds = $throttle->lockedFor($identifier, $ip);
            $minutes = (int) ceil(max($lockedSeconds, 0) / 60);

            return ['message' => "Account is locked. Try again in {$minutes} minute(s).", 'status' => 403, 'lockedSeconds' => $lockedSeconds];
        }

        return null;
    }

    /**
     * Find a user by email or username and verify the password.
     *
     * @param  string  $identifier
     * @param  string  $password
     * @return User|null
     */
    protected function findUser(string $identifier, string $password): ?User
    {
        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Check account state (active, locked).
     *
     * @param  User   $user
     * @return array|null  Error array or null.
     */
    protected function checkAccountState(User $user): ?array
    {
        if (! $user->is_active) {
            return ['message' => 'Account is inactive.', 'status' => 403];
        }

        if ($user->is_locked) {
            return ['message' => 'Account is locked.', 'status' => 403];
        }

        return null;
    }

    /**
     * Check if email verification is required and pending.
     *
     * @param  User   $user
     * @return array|null  Error array or null.
     */
    protected function checkEmailVerification(User $user): ?array
    {
        if (SystemSetting::getString('auth_verification_mode', 'public') === 'disabled') {
            return null;
        }

        if (! $user->hasVerifiedEmail()) {
            return ['message' => 'Email not verified', 'status' => 403, 'error_code' => 'UNVERIFIED_EMAIL'];
        }

        return null;
    }
}
