<?php

namespace App\Actions\V1\User;

use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unlock a user account and optionally reset login throttle.
 */
class UnlockUserAction
{
    /**
     * Unlock the user and reset throttle if provided.
     *
     * @param  User           $user
     * @param  string|null    $ip
     * @param  Request|null   $request
     * @param  LoginThrottle|null $throttle
     * @return array          ['user' => User]
     */
    public function run(
        User $user,
        ?string $ip = null,
        ?Request $request = null,
        ?LoginThrottle $throttle = null,
    ): array {
        DB::transaction(function () use ($user, $ip, $request, $throttle) {
            $user->update(['is_locked' => false]);
            if ($throttle && $ip) {
                $throttle->reset($user->email, $ip);
            }
        });

        return ['user' => $user];
    }
}
