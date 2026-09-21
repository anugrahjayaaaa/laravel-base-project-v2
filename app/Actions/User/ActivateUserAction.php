<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivateUserAction
{
    public function run(User $user): array
    {
        DB::transaction(function () use ($user) {
            $user->update(['is_active' => true]);
        });

        return ['user' => $user];
    }
}
