<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Collection;

class ListUserSessionsAction
{
    public function run(User $user): Collection
    {
        return $user->tokens()->orderByDesc('last_used_at')->get();
    }
}