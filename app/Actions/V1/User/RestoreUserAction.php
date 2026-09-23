<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Restore a trashed user with optional re-activation.
 */
class RestoreUserAction
{
    /**
     * Restore the user from trash.
     *
     * @param  User   $user
     * @param  bool   $setActive  Whether to set the user as active after restore.
     * @return array  ['user' => User]
     */
    public function run(User $user, bool $setActive = true): array
    {
        DB::transaction(function () use ($user, $setActive) {
            $user->restore();
            if ($setActive) {
                $user->update(['is_active' => true, 'is_locked' => false]);
            }
        });

        return ['user' => $user];
    }
}
