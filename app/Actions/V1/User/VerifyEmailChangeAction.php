<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Verify and apply a pending email change using the token.
 */
class VerifyEmailChangeAction
{
    /**
     * Verify the email change token and apply the new email.
     *
     * @param  User   $user
     * @param  string $token
     * @return bool   True if verified and applied.
     */
    public function run(User $user, string $token): bool
    {
        if ($user->email_change_token !== $token) {
            return false;
        }

        if ($user->email_change_token_expires_at && $user->email_change_token_expires_at->isPast()) {
            return false;
        }

        DB::transaction(function () use ($user) {
            $user->update([
                'email' => $user->pending_email,
                'email_changed_at' => now(),
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_token_expires_at' => null,
            ]);
        });

        // Revoke active Sanctum tokens — user must re-auth with new email.
        $user->tokens()->delete();

        return true;
    }
}
