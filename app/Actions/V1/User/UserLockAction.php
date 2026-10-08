<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Notification\NotificationAccountStateAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lock a user account with optional session invalidation.
 */
class UserLockAction
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
     * Lock the user.
     *
     * @param  User       $user
     * @param  User|null  $causer  Who to attribute the audit record to
     * @param  bool       $invalidateSessions
     * @return array  ['user' => User]
     */
    public function run(User $user, ?User $causer = null, bool $invalidateSessions = true): array
    {
        $this->validate($user);

        DB::transaction(function () use ($user, $causer, $invalidateSessions) {
            $user->update(['is_locked' => true]);
            if ($invalidateSessions) {
                $this->invalidateSessions($user);
            }

            // target_id/target_email are redundant with the subject, and
            // deliberately so: the activity list filters on properties, and a
            // state row that only carried a subject id could not be searched by
            // the address it affected.
            $user->audit('user.locked', $causer, [
                'target_id' => $user->id,
                'target_email' => $user->email,
            ]);
        });

        // Both audiences, after the transaction: the account is already in its
        // new state, so a notification failure cannot roll back a lock the admin
        // asked for. See NotificationAccountStateAction for why this is a
        // separate step rather than an event listener on the update above.
        $this->notifyAction->run($user, 'user.locked', $causer);

        return ['user' => $user];
    }

    private function invalidateSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

    /**
     * Validate that the user can be locked.
     *
     * @param  User  $user
     */
    protected function validate(User $user): void
    {
        if (! $user->is_active) {
            Log::warning('Lock inactive user forbidden', ['user_id' => $user->id]);

            $validator = Validator::make([], []);

            $validator->errors()->add('status', __('Cannot lock an inactive user. Please activate the user first.'));

            throw new ValidationException($validator);
        }
    }
}
