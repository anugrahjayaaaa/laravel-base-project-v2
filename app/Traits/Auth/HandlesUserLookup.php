<?php

namespace App\Traits\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

trait HandlesUserLookup
{
    protected function lookupUser(string $email): ?User
    {
        return User::where('email', $email)->first();
    }
}