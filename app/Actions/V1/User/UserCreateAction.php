<?php

namespace App\Actions\V1\User;

use App\Actions\V1\Auth\AuthRecordPasswordHistoryAction;
use App\Actions\V1\Role\RoleAssignAction;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\RegisterNotification;
use App\Notifications\UserCreatedNotification;
use App\Support\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Create a new user and send them a verification link.
 *
 * Two callers, one flow. An admin supplies no password and gets a generated
 * temporary one; a self-registering user supplies their own. Everything else
 * about the account is identical, so it stays here rather than being forked.
 */
class UserCreateAction
{
    public function __construct(
        private readonly AuthRecordPasswordHistoryAction $recordHistoryAction,
        private readonly RoleAssignAction $assignRolesAction,
    ) {
    }

    /**
     * Create a user and notify them to verify their email address.
     *
     * @param  array       $data      Keys: name, email, username, roles (admin only)
     * @param  string|null $password  The user's own password. Null means generate a
     *                               temporary one and force a change on first login.
     * @param  User|null   $causer    Who is creating the account, for the role audit
     * @return User
     */
    public function run(array $data, ?string $password = null, ?User $causer = null): User
    {
        $isTemporary = $password === null;

        if ($isTemporary) {
            $password = $this->generateTempPassword();
        }

        return DB::transaction(function () use ($data, $password, $isTemporary, $causer) {
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

            // Two sources of roles, and only one of them is a privilege an admin
            // handed out. A self-registering user gets the server-side default;
            // an admin-supplied `roles` key is a grant, so it is checked and
            // synced through RoleAssignAction rather than assigned inline.
            if (array_key_exists('roles', $data)) {
                // No permission check here: RoleAssignAction holds it, so C9 and
                // C10 cannot drift apart.
                $user = $this->assignRolesAction->run(
                    $user,
                    $data['roles'],
                    $causer,
                    filter_var($data['confirm_superadmin'] ?? false, FILTER_VALIDATE_BOOLEAN)
                );
            } else {
                // findMany(), not find() in a loop: the default set is short
                // today but it is data, and a second default role would make
                // this an N+1 for nothing.
                foreach (RoleLookup::findMany($this->defaultRolesForSelfRegistration()) as $role) {
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

            // The causer is what tells the two caller paths apart: an
            // administrator creating an account passes one, a self-registering
            // user cannot. Each path writes the event its caller cares about —
            // without the gate the action would write `user.created` on top of
            // the `user.registered` row and one signup would produce two
            // records for one account.
            if ($causer !== null) {
                $user->audit('user.created', $causer);
            } else {
                $user->audit('user.registered');
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
     * - A configured default of `superadmin` is refused, same as an empty one.
     *   Registering is unauthenticated, so granting it here hands the account to
     *   whoever reached the form. RoleDeleteAction::reassignDefaultTo() refuses
     *   the same value for the same reason; on this path it is never a promotion.
     * - A default naming a role that does not exist is a typo or a role deleted
     *   after it was chosen, and `findMany()` reports that as an empty list
     *   rather than a failure. Left alone the account would land on zero roles
     *   with no way to tell that apart from the deliberate case above: every
     *   gated screen 403s and nothing in the UI says why. Fall back to the
     *   seeded user role, so a broken setting is a degraded default rather than
     *   a locked-out account.
     *
     * @return array<int, string>
     */
    private function defaultRolesForSelfRegistration(): array
    {
        $default = SystemSetting::getString('registration_default_role', SystemRole::USER);

        // An empty setting is a deliberate choice, not a broken one: it is how
        // an admin says "registering grants nothing, every account waits for a
        // human". Honouring it is the whole point of the setting being
        // clearable (RegisterTest::test_no_role_is_assigned_when_the_default_is_cleared).
        if ($default === '' || $default === SystemRole::SUPERADMIN) {
            return [];
        }

        // A name that resolves to no row is not that choice — it is a typo, or
        // a role deleted after it was chosen. `findMany()` reports that as an
        // empty list rather than a failure, so the account would land on zero
        // roles with no way to tell that apart from the case above: every gated
        // screen 403s and nothing in the UI says why. Fall back to the seeded
        // user role, which is what a new account gets when no default is set.
        return RoleLookup::find($default) === null
            ? [SystemRole::USER]
            : [$default];
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
