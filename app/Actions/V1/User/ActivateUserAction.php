<?php

namespace App\Actions\V1\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ActivateUserAction
{
    public function run(User $user): array
    {
        $this->validate($user);

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => true, 'is_locked' => false]);
        });

        return ['user' => $user];
    }

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
