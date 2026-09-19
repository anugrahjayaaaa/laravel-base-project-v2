<?php

namespace App\Actions\Auth;

class LogoutAllDevicesAction
{
    public function run($user): array
    {
        $user->tokens()->delete();

        return ['success' => true];
    }
}