<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Verify and apply a pending email change using the token.
 */
class UserVerifyEmailChangeAction
{
    /**
     * Verify the email change token and apply the new email.
     *
     * @param  User       $user
     * @param  string     $token
     * @param  User|null  $causer  Who to attribute the audit record to
     * @return bool   True if verified and applied.
     */
    public function run(User $user, string $token, ?User $causer = null): bool
    {
        if ($user->email_change_token !== $token) {
            return false;
        }

        if ($user->email_change_token_expires_at && $user->email_change_token_expires_at->isPast()) {
            return false;
        }

        DB::transaction(function () use ($user, $causer) {
            $newEmail = $user->pending_email;

            $user->update([
                'email' => $newEmail,
                'email_changed_at' => now(),
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_token_expires_at' => null,
            ]);

            // Unconditional, unlike the other user actions: this one is reached
            // through a signed link, so there is often no signed-in actor at all.
            // Falling back to the user keeps the record attributed to somebody
            // real instead of dropping the audit on the main path.
            $user->audit('user.email_changed', $causer ?? $user, ['new_email' => $newEmail]);
        });

        // Revoke active Sanctum tokens, user must re-auth with new email.
        $user->tokens()->delete();

        return true;
    }
}
