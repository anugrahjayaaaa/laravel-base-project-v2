<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class VerifyEmailChangeAction
{
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

        return true;
    }
}
