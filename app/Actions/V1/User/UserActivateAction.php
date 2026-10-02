<?php

namespace App\Actions\V1\User;

use App\Actions\Concerns\AuditsUserState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Activate a user account (requires not locked).
 */
class UserActivateAction
{
    use AuditsUserState;

    /**
     * Activate the user.
     *
     * @param  User       $user
     * @param  User|null  $causer  Who to attribute the audit record to
     * @return array  ['user' => User]
     */
    public function run(User $user, ?User $causer = null): array
    {
        $this->validate($user);

        DB::transaction(function () use ($user, $causer) {
            $user->update(['is_active' => true, 'is_locked' => false]);

            $this->auditState($user, 'user.activated', $causer);
        });

        return ['user' => $user];
    }

    /**
     * Validate that the user can be activated.
     *
     * @param  User  $user
     */
    protected function validate(User $user): void
    {
        if ($user->is_locked) {
            Log::warning('Activate locked user forbidden', ['user_id' => $user->id]);

            $validator = Validator::make([], []);

            $validator->errors()->add('status', __('Cannot activate a locked user. Please unlock first.'));

            throw new ValidationException($validator);
        }
    }
}
