<?php

namespace App\Actions\Auth;

use App\Models\User;

class VerifyEmailAction
{
    public function run(User $user): array
    {
        if ($user->hasVerifiedEmail()) {
            return ['error' => ['message' => 'Email already verified.', 'status' => 422]];
        }

        $user->markEmailAsVerified();

        return ['user' => $user];
    }
}