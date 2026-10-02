<?php

namespace App\Actions\V1\User;

use App\Models\User;
use App\Support\LastSuperadmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Soft-delete a user with session invalidation.
 */
class UserDeleteAction
{
    /**
     * Soft-delete the user.
     *
     * The audit row is written HERE, inside the transaction, not by the caller.
     * Every path that trashes a user — the row button, the API endpoint, and the
     * bulk bar, which loops this same action — therefore produces exactly one
     * `user.deleted` record, and a rollback takes the record with it.
     *
     * @param  User   $user
     * @param  User   $causer
     * @return array  ['user' => User]
     */
    public function run(User $user, User $causer): array
    {
        $this->validate($user, $causer);

        DB::transaction(function () use ($user, $causer) {
            $this->invalidateSessions($user);
            $user->delete();

            $user->audit('user.deleted', $causer);
        });

        return ['user' => $user];
    }

    private function invalidateSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();

        DB::table('users')->where('id', $user->id)->update(['remember_token' => null]);

        $user->tokens()->delete();
    }

    /**
     * Validate that the user can be deleted.
     *
     * @param  User  $user
     * @param  User  $causer
     */
    protected function validate(User $user, User $causer): void
    {
        if ($user->id === $causer->id) {
            $validator = Validator::make([], []);

            $validator->errors()->add('email', __('You cannot delete your own account.'));

            throw new ValidationException($validator);
        }

        // Trashing the last superadmin strips the role through Spatie's soft
        // delete scope, so nobody can administer the app afterwards. Same
        // invariant RoleAssignAction protects, on a path that never went near
        // it. Throws LastSuperadminException, which renders as a 409 / flash.
        LastSuperadmin::guard($user, __('deleted'));
    }
}
