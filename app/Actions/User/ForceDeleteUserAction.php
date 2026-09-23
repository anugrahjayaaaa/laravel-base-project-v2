<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ForceDeleteUserAction
{
    public function run(User $user, User $causer): array
    {
        $this->validate($user, $causer);

        DB::transaction(function () use ($user) {
            $user->forceDelete();
        });

        return ['user' => $user];
    }

    protected function validate(User $user, User $causer): void
    {
        if ($user->id === $causer->id) {
            $validator = Validator::make([], []);

            $validator->errors()->add('email', __('You cannot delete your own account.'));

            throw new ValidationException($validator);
        }

        $remaining = User::withTrashed()->count();
        if ($remaining <= 1) {
            $validator = Validator::make([], []);

            $validator->errors()->add('email', __('Cannot delete the last active user.'));

            throw new ValidationException($validator);
        }
    }
}
