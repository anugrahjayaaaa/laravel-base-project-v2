<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelEmailChangeAction
{
    public function run(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->update([
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_token_expires_at' => null,
            ]);
        });
    }
}
