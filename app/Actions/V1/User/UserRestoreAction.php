<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Restore a trashed user with optional re-activation.
 */
class UserRestoreAction
{
    /**
     * Restore the user from trash.
     *
     * @param  User       $user
     * @param  bool       $setActive  Whether to set the user as active after restore.
     * @param  User|null  $causer     Who to attribute the audit record to
     * @return array  ['user' => User]
     */
    public function run(User $user, bool $setActive = true, ?User $causer = null): array
    {
        DB::transaction(function () use ($user, $setActive, $causer) {
            $user->restore();
            if ($setActive) {
                $user->update(['is_active' => true, 'is_locked' => false]);
            }

            if ($causer !== null) {
                $user->audit('user.restored', $causer);
            }
        });

        return ['user' => $user];
    }
}
