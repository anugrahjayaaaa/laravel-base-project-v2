<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * List all active sessions/tokens for a user.
 */
class ListUserSessionsAction
{
    /**
     * Get the user's tokens sorted by last used.
     *
     * @param  User        $user
     * @return Collection
     */
    public function run(User $user): Collection
    {
        return $user->tokens()->orderByDesc('last_used_at')->get();
    }
}
