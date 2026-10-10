<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a pending email change by clearing the token fields.
 */
class UserCancelEmailChangeAction
{
    /**
     * Clear pending email change data.
     *
     * @param  User       $user
     * @param  User|null  $causer  Who to attribute the audit record to
     */
    public function run(User $user, ?User $causer = null): void
    {
        DB::transaction(function () use ($user, $causer) {
            // Read BEFORE the update nulls it. Cancelling is only interesting
            // because a takeover attempt was pending, and the address it was aimed
            // at is the fact an incident review needs — it is gone one line later.
            $pendingEmail = $user->pending_email;

            $user->update([
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_token_expires_at' => null,
            ]);

            if ($causer !== null) {
                $user->audit('user.email_change_cancelled', $causer, [
                    'cancelled_pending_email' => $pendingEmail,
                ]);
            }
        });
    }
}
