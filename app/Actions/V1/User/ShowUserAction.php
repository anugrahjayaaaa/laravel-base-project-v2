<?php

namespace App\Actions\V1\User;

use App\Models\User;

class ShowUserAction
{
    public function run(User $user): User
    {
        return $user;
    }
}