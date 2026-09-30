<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Role\AssignRolesAction;
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
    public function __construct(
        private readonly AssignRolesAction $assignRolesAction,
    ) {
    }

    /**
     * Update user fields. Handles email change via verification flow if email changes.
     *
     * @param  User       $user
     * @param  array      $data     Keys: name, status, username, email, roles (all optional except name/status)
     * @param  User|null  $causer   Who to attribute the audit record to
     * @return User
     */
    public function run(User $user, array $data, ?User $causer = null): User
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

        // Only touch roles when the key is actually present. An API caller that
        // omits `roles` must not have the user's permissions silently revoked,
        // and an empty array is a deliberate "remove them all" — array_key_exists
        // tells those two apart where isset() cannot.
        if (array_key_exists('roles', $data)) {
            $user = $this->assignRolesAction->run(
                $user,
                $data['roles'] ?? [],
                $causer,
                // P6-E5: only a payload that explicitly confirmed it may add or
                // remove superadmin. The cast matters — an unchecked HTML
                // checkbox is absent, and a hidden "0" arrives as the string
                // "0", which is falsy but NOT false.
                filter_var($data['confirm_superadmin'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
        }

        return $user->fresh();
    }
}
