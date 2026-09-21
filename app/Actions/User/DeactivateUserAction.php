<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateUserAction
{
    public function run(User $user, User $causer): array
    {
        $this->validate($user, $causer);

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => false]);
            $this->invalidateSessions($user);
        });

        return ['user' => $user];
    }

    private function invalidateSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

    protected function validate(User $user, User $causer): void
    {
        if ($user->id === $causer->id) {
            $validator = \Illuminate\Support\Facades\Validator::make([], []);
            $validator->errors()->add('email', __('You cannot deactivate your own account.'));
            throw new ValidationException($validator);
        }

        if ($user->is_locked) {
            $validator = \Illuminate\Support\Facades\Validator::make([], []);
            $validator->errors()->add('status', __('Cannot deactivate a locked user. Please unlock the user first.'));
            throw new ValidationException($validator);
        }
    }
}