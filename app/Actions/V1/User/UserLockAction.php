<?php

namespace App\Actions\V1\User;

use App\Actions\Concerns\AuditsUserState;
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
    use AuditsUserState;

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

            $this->auditState($user, 'user.locked', $causer);
        });

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
