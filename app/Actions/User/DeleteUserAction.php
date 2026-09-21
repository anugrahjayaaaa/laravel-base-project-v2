<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeleteUserAction
{
    public function run(User $user, User $causer): array
    {
        $this->validate($user, $causer);

        DB::transaction(function () use ($user) {
            $this->invalidateSessions($user);
            $user->delete();
        });

        return ['user' => $user];
    }

    private function invalidateSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('users')->where('id', $user->id)->update(['remember_token' => null]);
        $user->tokens()->delete();
    }

    protected function validate(User $user, User $causer): void
    {
        if ($user->id === $causer->id) {
            $validator = Validator::make([], []);
            $validator->errors()->add('email', __('You cannot delete your own account.'));
            throw new \Illuminate\Validation\ValidationException($validator);
        }
    }
}
