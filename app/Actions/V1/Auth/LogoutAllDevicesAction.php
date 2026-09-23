<?php

namespace App\Actions\V1\Auth;

/**
 * Revoke all active tokens for a user (logout everywhere).
 */
class LogoutAllDevicesAction
{
    /**
     * Delete all tokens for the user.
     *
     * @param  User  $user
     * @return array  ['success' => true]
     */
    public function run($user): array
    {
        $user->tokens()->delete();

        return ['success' => true];
    }
}
