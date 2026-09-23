<?php

namespace App\Actions\User;

use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class RequestEmailChangeAction
{
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
