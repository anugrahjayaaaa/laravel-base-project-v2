<?php

namespace App\Actions\V1\Auth;

use App\Models\User;

/**
 * Mark a user's email as verified.
 */
class VerifyEmailAction
{
    /**
     * Verify the user's email if not already verified.
     *
     * @param  User   $user
     * @return array  ['user' => User] or ['error' => array]
     */
    public function run(User $user): array
    {
        if ($user->hasVerifiedEmail()) {
            return ['error' => ['message' => 'Email already verified.', 'status' => 422]];
        }

        $user->markEmailAsVerified();

        return ['user' => $user];
    }
}
