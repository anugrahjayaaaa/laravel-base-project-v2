<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Auth\RecordPasswordHistoryAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\UserCreatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Create a new user with temp password and send verification notification.
 */
class CreateUserAction
{
    public function __construct(
        private readonly RecordPasswordHistoryAction $recordHistoryAction,
    ) {}

    /**
     * Create a user and notify them with a temporary password.
     *
     * @param  array  $data  Keys: name, email, username, roles (optional)
     * @return User
     */
    public function run(array $data): User
    {
        $tempPassword = $this->generateTempPassword();

        return DB::transaction(function () use ($data, $tempPassword) {
            $user = User::create([
                'name' => strip_tags($data['name']),
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => Hash::make($tempPassword),
                'is_active' => true,
                'is_locked' => false,
                'must_change_password' => true,
                'password_expires_at' => null,
                'last_activity_at' => null,
            ]);

            if (! empty($data['roles'])) {
                foreach ($data['roles'] as $roleName) {
                    $role = \Spatie\Permission\Models\Role::where('name', $roleName)->first();
                    if ($role) {
                        $user->assignRole($role);
                    }
                }
            }

            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(SystemSetting::getInt('email_verification_expire_minutes', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
            );

            Notification::send($user, new UserCreatedNotification($tempPassword, $user->username, $verificationUrl));

            // Record initial temp password in history.
            $this->recordHistoryAction->run($user, $user->password);

            return $user;
        });
    }

    private function generateTempPassword(): string
    {
        $minLength = SystemSetting::getInt('password_min_length', 12);

        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $digits = '0123456789';
        $symbols = '!@#$%^&*';

        $password = [
            $upper[random_int(0, 25)],
            $lower[random_int(0, 25)],
            $digits[random_int(0, 9)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        $all = $upper . $lower . $digits . $symbols;
        for ($i = 4; $i < $minLength; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($password);

        return implode('', $password);
    }
}
