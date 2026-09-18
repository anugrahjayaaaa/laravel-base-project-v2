<?php

namespace App\Traits\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Auth\LoginThrottle;

trait AuthenticatesUsers
{
    /**
     * Check if the identifier + IP is locked out before login attempt.
     * Returns null if not locked, or error array if locked.
     */
    protected function checkThrottle(string $identifier, string $ip, LoginThrottle $throttle): ?array
    {
        if ($throttle->isLocked($identifier, $ip)) {
            $minutes = (int) ceil(max($throttle->lockedFor($identifier, $ip), 0) / 60);

            return ['message' => "Account is locked. Try again in {$minutes} minute(s).", 'status' => 403];
        }

        return null;
    }

    /**
     * Lookup user by email/username and verify password.
     *
     * Returns User or null. No account state checks — those are
     * controller-specific (API vs Web handle unverified email
     * differently). Caller handles throttle + response.
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
     * Check account state after credential verification.
     * Returns null if account is valid, or an error array:
     * ['message' => string, 'status' => int]
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
}