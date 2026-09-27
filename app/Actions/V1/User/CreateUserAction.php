<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Auth\RecordPasswordHistoryAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\RegisterNotification;
use App\Notifications\UserCreatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

/**
 * Create a new user and send them a verification link.
 *
 * Two callers, one flow. An admin supplies no password and gets a generated
 * temporary one; a self-registering user supplies their own. Everything else
 * about the account is identical, so it stays here rather than being forked.
 */
class CreateUserAction
{
    public function __construct(
        private readonly RecordPasswordHistoryAction $recordHistoryAction,
    ) {
    }

    /**
     * Create a user and notify them to verify their email address.
     *
     * @param  array       $data      Keys: name, email, username, roles (admin only)
     * @param  string|null $password  The user's own password. Null means generate a
     *                               temporary one and force a change on first login.
     * @return User
     */
    public function run(array $data, ?string $password = null): User
    {
        $isTemporary = $password === null;

        if ($isTemporary) {
            $password = $this->generateTempPassword();
        }

        return DB::transaction(function () use ($data, $password, $isTemporary) {
            $user = User::create([
                'name' => strip_tags($data['name']),
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => Hash::make($password),
                'is_active' => true,
                'is_locked' => false,
                'must_change_password' => $isTemporary,
                'password_expires_at' => null,
                'last_activity_at' => null,
            ]);

            $roles = $data['roles'] ?? $this->defaultRolesForSelfRegistration();

            foreach ($roles as $roleName) {
                $role = Role::where('name', $roleName)->first();

                if ($role) {
                    $user->assignRole($role);
                }
            }

            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(SystemSetting::getInt('email_verification_expire_minutes', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
            );

            Notification::send($user, $isTemporary
                ? new UserCreatedNotification($password, $user->username, $verificationUrl)
                : new RegisterNotification($user->username, $verificationUrl));

            // Only a password the user chose belongs in the reuse history. A
            // generated one is never typed by them, so recording it would only
            // block that exact string from being chosen later for no reason.
            if (! $isTemporary) {
                $this->recordHistoryAction->run($user, $user->password);
            }

            return $user;
        });
    }

    /**
     * The roles a self-registering user receives.
     *
     * Falls back to the system default so a new account is not locked out of
     * everything until an admin gets to it. An admin-created user never comes
     * through here — its roles come from the form.
     *
     * @return array<int, string>
     */
    private function defaultRolesForSelfRegistration(): array
    {
        $default = SystemSetting::getString('registration_default_role', 'user');

        return $default === '' ? [] : [$default];
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
