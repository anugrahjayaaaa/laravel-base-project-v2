<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnlockUserAction
{
    public function run(
        User $user,
        string $ip,
        Request $request,
        LoginThrottle $throttle,
    ): array {
        DB::transaction(function () use ($user, $throttle, $request) {
            $user->update(['is_locked' => false]);
            $throttle->reset($user->email, $request->ip());
        });

        return ['user' => $user];
    }
}