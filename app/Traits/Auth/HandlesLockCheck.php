<?php

namespace App\Traits\Auth;

use App\Models\User;
use App\Auth\LoginThrottle;

trait HandlesLockCheck
{
    protected function checkUserLock(?User $user, string $ip, LoginThrottle $throttle): ?array
    {
        if ($user && $throttle->isLocked($user->email, $ip)) {
            return ['message' => 'Account is locked.', 'status' => 403];
        }

        return null;
    }
}