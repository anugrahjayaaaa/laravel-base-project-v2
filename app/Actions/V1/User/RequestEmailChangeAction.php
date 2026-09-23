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
class RequestEmailChangeAction
{
    /**
     * Set pending email and send verification notification.
     *
     * @param  User   $user
     * @param  string $newEmail
     */
    public function run(User $user, string $newEmail): void
    {
        DB::transaction(function () use ($user, $newEmail) {
            $token = Str::random(64);

            $user->update([
                'pending_email' => $newEmail,
                'email_change_token' => $token,
                'email_change_token_expires_at' => now()->addHours(24),
            ]);

            Notification::send($user->fresh(), new ChangeEmailVerificationNotification($newEmail, $token));
        });
    }
}
