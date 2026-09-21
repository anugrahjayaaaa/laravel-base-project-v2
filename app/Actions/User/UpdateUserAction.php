<?php

namespace App\Actions\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UpdateUserAction
{
    public function run(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'is_active' => isset($data['status'])
                ? $data['status'] === UserStatusEnum::ACTIVE->value
                : $user->is_active,
        ]);

        if (isset($data['password']) && $data['password']) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        return $user->fresh();
    }
}