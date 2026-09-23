<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreUserAction
{
    public function run(User $user, bool $setActive = true): array
    {
        DB::transaction(function () use ($user, $setActive) {
            $user->restore();
            if ($setActive) {
                $user->update(['is_active' => true, 'is_locked' => false]);
            }
        });

        return ['user' => $user];
    }
}