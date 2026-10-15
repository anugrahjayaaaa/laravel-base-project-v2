<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Permanently delete a trashed user (hard delete).
 */
class UserForceDeleteAction
{
    /**
     * Force-delete the user.
     *
     * @param  User   $user
     * @param  User   $causer
     * @return array  ['user' => User]
     */
    public function run(User $user, User $causer): array
    {
        $this->validate($user, $causer);

        DB::transaction(function () use ($user, $causer) {
            // Captured before the row stops existing. After a force delete there
            // is NO record of the account anywhere — not in `users`, not with the
            // soft-delete column. Whatever this row omits is gone for good, which
            // makes the address and the username the whole point of writing it.
            $identity = [
                'target_id' => $user->id,
                'target_email' => $user->email,
                'target_username' => $user->username,
            ];

            $user->forceDelete();

            $user->audit('user.force_deleted', $causer, $identity);
        });

        return ['user' => $user];
    }

    /**
     * Validate that the user can be force-deleted.
     *
     * @param  User  $user
     * @param  User  $causer
     */
    protected function validate(User $user, User $causer): void
    {
        // No last-superadmin guard here, deliberately. This action only accepts
        // an ALREADY-trashed user, and UserDeleteAction refused to trash the
        // last active superadmin — a trashed row is outside both
        // `LastSuperadmin::activeSuperadminCount()` and Spatie's role scope, so
        // by the time a user reaches this method they cannot be the last one
        // standing. Adding a guard would be a second place to keep in sync with
        // no case it could ever refuse. The "last active user" check below is
        // a different invariant: it stops emptying the users table entirely,
        // which this action can still do.
        if ($user->id === $causer->id) {
            $validator = Validator::make([], []);

            $validator->errors()->add('email', __('You cannot delete your own account.'));

            throw new ValidationException($validator);
        }

        $remaining = User::withTrashed()->count();
        if ($remaining <= 1) {
            $validator = Validator::make([], []);

            $validator->errors()->add('email', __('Cannot delete the last active user.'));

            throw new ValidationException($validator);
        }
    }
}
