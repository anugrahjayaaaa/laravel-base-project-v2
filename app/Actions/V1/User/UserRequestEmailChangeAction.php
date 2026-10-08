<?php

namespace App\Actions\V1\User;

use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Request an email change by setting a pending email with verification token.
 */
class UserRequestEmailChangeAction
{
    /**
     * Set pending email and send verification notification.
     *
     * @param  User       $user
     * @param  string     $newEmail
     * @param  User|null  $causer  Who to attribute the audit record to
     */
    public function run(User $user, string $newEmail, ?User $causer = null): void
    {
        $token = Str::random(64);

        DB::transaction(function () use ($user, $newEmail, $causer, $token) {
            $user->update([
                'pending_email' => $newEmail,
                'email_change_token' => $token,
                'email_change_token_expires_at' => now()->addHours(24),
            ]);

            if ($causer !== null) {
                $user->audit('user.email_change_requested', $causer, ['pending_email' => $newEmail]);
            }
        });

        // Sent AFTER the commit. The notification implements ShouldQueue and
        // the queue connections run with `after_commit => false`, so dispatching
        // inside the transaction let a worker send before the token row
        // committed — a rollback would still have delivered a verification link
        // whose token the database never stored, so the link could never work.
        Notification::send($user->fresh(), new ChangeEmailVerificationNotification($newEmail, $token));
    }
}
