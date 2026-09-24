<?php

namespace App\Actions\V1\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Update a user's profile fields with email change flow.
 */
class UpdateUserAction
{
    /**
     * Update user fields. Handles email change via verification flow if email changes.
     *
     * @param  User   $user
     * @param  array  $data  Keys: name, status, username, email (optional)
     * @return User
     */
    public function run(User $user, array $data): User
    {
        $user->update([
            'name' => strip_tags($data['name'] ?? $user->name),
            'is_active' => isset($data['status'])
                ? $data['status'] === UserStatusEnum::ACTIVE->value
                : $user->is_active,
        ]);

        if (isset($data['username']) && $data['username'] !== $user->username) {
            $user->update([
                'username' => $data['username'],
                'username_changed_at' => now(),
            ]);
        }

        if (isset($data['email']) && $data['email'] !== $user->email) {
            if (class_exists(ChangeEmailVerificationNotification::class)) {
                $token = Str::random(64);

                $user->update([
                    'pending_email' => $data['email'],
                    'email_change_token' => $token,
                    'email_change_token_expires_at' => now()->addHours(24),
                ]);

                Notification::send($user->fresh(), new ChangeEmailVerificationNotification($data['email'], $token));
            } else {
                $user->update(['email' => $data['email']]);
            }
        }

        return $user->fresh();
    }
}
