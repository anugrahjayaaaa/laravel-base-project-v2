<?php

namespace App\Actions\V1\Auth;

class LogoutAllDevicesAction
{
    public function run($user): array
    {
        $user->tokens()->delete();

        return ['success' => true];
    }
}