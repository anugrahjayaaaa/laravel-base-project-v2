<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LockUserAction
{
    public function run(User $user): array
    {
        $this->validate($user);

        DB::transaction(function () use ($user) {
            $user->update(['is_locked' => true]);
            $this->invalidateSessions($user);
        });

        return ['user' => $user];
    }

    private function invalidateSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

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
