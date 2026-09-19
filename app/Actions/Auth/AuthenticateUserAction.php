<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Support\Facades\Hash;

class AuthenticateUserAction
{
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
            $lockedSeconds = $throttle->recordFailed($identifier, $ip);

            return ['error' => ['message' => 'Invalid credentials.', 'status' => 401], 'lockedSeconds' => $lockedSeconds];
        }

        $accountError = $this->checkAccountState($user);

        if ($accountError) {
            return ['error' => $accountError];
        }

        return ['user' => $user];
    }

    protected function checkThrottle(string $identifier, string $ip, LoginThrottle $throttle): ?array
    {
        if ($throttle->isLocked($identifier, $ip)) {
            $minutes = (int) ceil(max($throttle->lockedFor($identifier, $ip), 0) / 60);

            return ['message' => "Account is locked. Try again in {$minutes} minute(s).", 'status' => 403];
        }

        return null;
    }

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
