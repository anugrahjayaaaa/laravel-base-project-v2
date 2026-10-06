<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Notification\NotificationAccountStateAction;
use App\Models\User;
use App\Support\LastSuperadmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Deactivate a user account with optional session invalidation.
 */
class UserDeactivateAction
{
    /**
     * @param  NotificationAccountStateAction  $notifyAction  Notifies both
     *         audiences after the state change commits. Injected rather than
     *         resolved so the action stays testable and the dispatch has one
     *         seam, not two.
     */
    public function __construct(
        private readonly NotificationAccountStateAction $notifyAction,
    ) {
    }

    /**
     * Deactivate the user.
     *
     * @param  User   $user
     * @param  User   $causer
     * @param  bool   $invalidateSessions
     * @return array  ['user' => User]
     */
    public function run(User $user, User $causer, bool $invalidateSessions = true): array
    {
        $this->validate($user, $causer);

        DB::transaction(function () use ($user, $causer, $invalidateSessions) {
            $user->update(['is_active' => false]);
            if ($invalidateSessions) {
                $this->invalidateSessions($user);
            }

            // target_id/target_email are redundant with the subject, and
            // deliberately so: the activity list filters on properties, and a
            // state row that only carried a subject id could not be searched by
            // the address it affected.
            $user->audit('user.deactivated', $causer, [
                'target_id' => $user->id,
                'target_email' => $user->email,
            ]);
        });

        // Both audiences, after the transaction: the account is already in its
        // new state, so a notification failure cannot roll back a lock the admin
        // asked for. See NotificationAccountStateAction for why this is a
        // separate step rather than an event listener on the update above.
        $this->notifyAction->run($user, 'user.deactivated', $causer);

        return ['user' => $user];
    }

    private function invalidateSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

    /**
     * Validate that the user can be deactivated.
     *
     * @param  User  $user
     * @param  User  $causer
     */
    protected function validate(User $user, User $causer): void
    {
        if ($user->id === $causer->id) {
            Log::warning('Deactivate self forbidden', ['user_id' => $user->id, 'causer_id' => $causer->id]);

            $validator = Validator::make([], []);

            $validator->errors()->add('email', __('You cannot deactivate your own account.'));

            throw new ValidationException($validator);
        }

        if ($user->is_locked) {
            Log::warning('Deactivate locked user forbidden', ['user_id' => $user->id, 'causer_id' => $causer->id]);

            $validator = Validator::make([], []);

            $validator->errors()->add('status', __('Cannot deactivate a locked user. Please unlock the user first.'));

            throw new ValidationException($validator);
        }

        // An inactive superadmin cannot log in, so deactivating the last one
        // leaves nobody able to administer the app — the same lockout the role
        // guard in RoleAssignAction prevents, reached by a different column.
        LastSuperadmin::guard($user, __('deactivated'));
    }
}
