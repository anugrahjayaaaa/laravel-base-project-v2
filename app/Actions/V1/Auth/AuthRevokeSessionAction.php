<?php

namespace App\Actions\V1\Auth;

use App\Models\User;

/**
 * Revoke one of the caller's own sessions (one Sanctum token).
 */
class AuthRevokeSessionAction
{
    /**
     * Delete a single token belonging to the user.
     *
     * The query is scoped to `$user->tokens()`, so an id on another account is
     * a 404 rather than a 403: "you may not" would confirm that the id exists,
     * and the caller has no business knowing that.
     *
     * @param  User    $user
     * @param  string  $tokenId
     * @return void
     */
    public function run(User $user, string $tokenId): void
    {
        $user->tokens()->findOrFail($tokenId)->delete();
    }
}
