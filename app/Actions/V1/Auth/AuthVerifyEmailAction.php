<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mark a user's email as verified.
 */
class AuthVerifyEmailAction
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

        // Inside the transaction, not beside it. `markEmailAsVerified()` writes the
        // column, so a failure after it would leave an `auth.email_verified` row
        // claiming a verification that rolled back — and the row is the only place
        // that fact is recorded, since nothing else changes.
        DB::transaction(function () use ($user): void {
            $user->markEmailAsVerified();

            $user->audit('auth.email_verified', $user);
        });

        return ['user' => $user];
    }
}
