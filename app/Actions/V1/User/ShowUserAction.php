<?php

namespace App\Actions\V1\User;

use App\Models\User;

/**
 * Return the user model (read-only accessor).
 */
class ShowUserAction
{
    /**
     * Get the user.
     *
     * @param  User  $user
     * @return User
     */
    public function run(User $user): User
    {
        return $user;
    }
}
