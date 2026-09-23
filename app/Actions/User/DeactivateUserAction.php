<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeactivateUserAction
{
    public function run(User $user, User $causer, bool $invalidateSessions = true): array
    {
        $this->validate($user, $causer);

        DB::transaction(function () use ($user, $invalidateSessions) {
            $user->update(['is_active' => false]);
            if ($invalidateSessions) {
                $this->invalidateSessions($user);
            }
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
    }
}
