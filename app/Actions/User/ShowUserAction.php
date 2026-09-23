<?php

namespace App\Actions\User;

use App\Models\User;

class ShowUserAction
{
    public function run(User $user): User
    {
        return $user;
    }
}