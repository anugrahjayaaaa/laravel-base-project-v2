<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Notification\NotificationAdminEventAction;
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
    public function __construct(
        private readonly NotificationAdminEventAction $notifyAction,
    ) {
    }

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

            // Captured BEFORE the delete. A soft-deleted row is only readable from
            // the trashed scope, so an operator scanning the audit list would find
            // a subject that renders as a bare `#12` — and the address is the one
            // thing they would search by.
            $identity = ['target_id' => $user->id, 'target_email' => $user->email];

            $user->delete();

            // `target_id`/`target_email` are redundant with the subject, and
            // deliberately so — same reasoning as `UserLockAction`: the list
            // filters on properties, so a state row that carried only an id could
            // not be found by the address it affected.
            $user->audit('user.deleted', $causer, $identity);
        });

        // The account no longer exists, so it cannot be the recipient — only the
        // administrators who could have deleted it.
        $this->notifyAction->configurationChanged('user.deleted', $user->username, $causer);

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
