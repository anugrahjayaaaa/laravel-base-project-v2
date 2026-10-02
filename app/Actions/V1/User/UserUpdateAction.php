<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Role\RoleAssignAction;
use App\Enums\UserStatusEnum;
use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Update a user's profile fields with email change flow.
 */
class UserUpdateAction
{
    public function __construct(
        private readonly RoleAssignAction $assignRolesAction,
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
        // The whole update is one transaction, not just the audit write. Three
        // separate `update()` calls used to run unguarded, so a failure in the
        // second or third left the first applied — and once the audit moved in
        // here, a row claiming a profile save that did not fully happen.
        return DB::transaction(function () use ($user, $data, $causer): User {
            // Captured before any write, and as plain values: after the
            // updates below these are the new state, and a properties bag
            // reading "old => new" is worse than no properties at all.
            $before = [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ];

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

            $emailChanged = isset($data['email']) && $data['email'] !== $user->email;

            if ($emailChanged) {
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

            // Two callers, two events, and the causer is the only thing that
            // tells them apart — an admin has one, a self-service save cannot.
            if ($causer !== null) {
                $user->audit('user.updated', $causer, $this->changedFields($before, $user));
            } else {
                // The profile endpoint's own row. It used to be written by the
                // controller after this returned, which meant it survived a
                // rollback here and could describe a save that never committed.
                $user->audit('user.profile_updated', null, $this->changedFields($before, $user));

                // Separate event, and the one an admin actually needs: knowing
                // that a profile was saved says nothing about who is trying to
                // take the account over. `pending_email` is the destination,
                // which is the whole reason this row exists.
                if ($emailChanged) {
                    $user->audit('user.email_change_requested', null, [
                        'pending_email' => $user->fresh()->pending_email ?? $data['email'],
                    ]);
                }
            }

            return $user->fresh();
        });
    }

    /**
     * Which of the profile fields this call actually moved.
     *
     * Not the model's fields: "a profile was updated" is the fact, and it is
     * already the event name. What an incident review needs is the direction —
     * a name is not sensitive, but a changed username or a deactivated account
     * is a takeover signal, and reading that off a `user.updated` row with no
     * properties means diffing the user table by hand.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changedFields(array $before, User $user): array
    {
        $changed = [];

        foreach ($before as $field => $from) {
            $to = $user->{$field};

            if ((string) $from !== (string) $to) {
                $changed[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changed;
    }
}
