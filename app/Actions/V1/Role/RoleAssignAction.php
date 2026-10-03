<?php

namespace App\Actions\V1\Role;

use App\Actions\V1\User\UserIndexAction;
use App\Exceptions\LastSuperadminException;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sync a user's roles, refusing to leave the app with no superadmin.
 *
 * Both user write paths call this, so the superadmin guard cannot be forgotten
 * on one of them.
 */
class RoleAssignAction
{
    /**
     * Replace a user's roles and record what changed.
     *
     * @param  array<int, string>  $roleNames  Names to sync; unknown names are skipped
     * @param  User|null           $causer     Who to attribute the audit record to
     * @param  bool                $confirmed  The payload carried `confirm_superadmin`
     * @return User
     *
     * @throws LastSuperadminException When the sync would leave zero superadmins
     * @throws AuthorizationException When superadmin is added or removed without confirmation
     */
    public function run(User $user, array $roleNames, ?User $causer = null, bool $confirmed = false): User
    {
        return DB::transaction(function () use ($user, $roleNames, $causer, $confirmed): User {
            $before = $user->roles->pluck('name')->all();

            // A name with no role on this guard is skipped rather than thrown on:
            // the request rules already reject it, so reaching here means a
            // programmatic caller, and refusing the whole sync over one bad name
            // would be the more surprising outcome. findMany() returning fewer
            // roles than names is exactly that skip, in one query instead of one
            // per name.
            $this->authorizeRoleAssignment($causer);

            $found = RoleLookup::findMany($roleNames);
            $roles = collect($roleNames)
                ->map(fn (string $name) => $found->get($name))
                ->filter()
                ->values()
                ->all();

            $this->guardSuperadminChange($causer, $before, $roles, $confirmed);
            $this->guardSuperadminGrant($causer, $roles);
            $this->guardLastSuperadmin($user, $before, $roles);

            $user->syncRoles($roles);

            $after = $user->fresh()->roles->pluck('name')->all();

            // Inside the transaction (DEP-003): an audit row that survives a
            // rollback would record a role change that never happened.
            $user->audit('user.roles_assigned', $causer, [
                'before' => $before,
                'after' => $after,
            ]);

            // `syncRoles()` writes only `model_has_roles`, so neither the users
            // row nor the roles row changes and NO model event fires —
            // `UserObserver` and `RoleObserver` both stay silent. The cached
            // count masks superadmin accounts, so a grant or revoke here makes
            // every non-superadmin admin's tab totals wrong, with nothing to
            // notice. This is the one role path that has to invalidate itself.
            UserIndexAction::bustCache();

            return $user;
        });
    }

    /**
     * Handing out roles is its own permission, separate from `users.update`.
     *
     * Both write paths land here (C9 and C10), so the check lives here rather
     * than in each caller: before this, `UserUpdateAction` synced whatever roles
     * the payload carried, and a caller holding only `users.update` could make
     * anybody superadmin.
     *
     * @throws AuthorizationException
     */
    private function authorizeRoleAssignment(?User $causer): void
    {
        if ($causer?->can('users.assign_roles') === true) {
            return;
        }

        throw new AuthorizationException(__('You do not have permission to assign roles.'));
    }

    /**
     * Require an explicit confirmation to add OR remove the superadmin role.
     *
     * P6-E5. The existing checks answer WHO may do it (superadmin only, per
     * `guardSuperadminGrant`) and whether it would strand the app (per
     * `guardLastSuperadmin`). Neither answers whether the caller INTENDED it,
     * and intent is the part a mis-clicked checkbox or a stray `roles` key in
     * an unrelated form post gets wrong. Adding superadmin is the most
     * consequential grant in the application; it should cost one deliberate
     * extra field, not ride along with a role list someone was editing.
     *
     * Applied to REMOVAL as well as addition, because the dangerous direction is
     * not symmetric: a demotion is how an account quietly loses the ability to
     * undo whatever demoted it. Both directions change whether a superadmin
     * exists, so both need the same deliberate signal.
     *
     * Only fires on an actual CHANGE. A payload that submits `superadmin` for a
     * user who already holds it, or omits it for a user who does not, alters
     * nothing and is not the confirm flow's business — otherwise every ordinary
     * save of a superadmin's own form would need the extra field.
     *
     * @param  array<int, string>  $before  Current role names
     * @param  array<int, \App\Models\Role>  $roles  Roles about to be applied
     *
     * @throws AuthorizationException
     */
    private function guardSuperadminChange(?User $causer, array $before, array $roles, bool $confirmed): void
    {
        $hadIt = in_array(SystemRole::SUPERADMIN, $before, true);

        $wantsIt = collect($roles)
            ->contains(fn ($role): bool => $role->name === SystemRole::SUPERADMIN);

        if ($hadIt === $wantsIt || $confirmed) {
            return;
        }

        throw new AuthorizationException(
            $wantsIt
                ? __('Granting the superadmin role requires an explicit confirmation.')
                : __('Removing the superadmin role requires an explicit confirmation.')
        );
    }

    /**
     * Only a superadmin may hand out the superadmin role.
     *
     * `users.assign_roles` is broad enough to edit any ordinary role, so on its
     * own it lets a delegated admin mint a second superadmin. The superadmin
     * role is the one grant that stays inside the superadmin circle.
     *
     * @param  array<int, \App\Models\Role>  $roles  Roles about to be applied
     *
     * @throws AuthorizationException
     */
    private function guardSuperadminGrant(?User $causer, array $roles): void
    {
        $grantingSuperadmin = collect($roles)
            ->contains(fn ($role): bool => $role->name === SystemRole::SUPERADMIN);

        if (! $grantingSuperadmin || $causer?->hasRole(SystemRole::SUPERADMIN) === true) {
            return;
        }

        throw new AuthorizationException(__('Only a superadmin can assign the superadmin role.'));
    }

    /**
     * Refuse a sync that would leave nobody able to administer the app.
     *
     * Checks the *result*, not the intent: a superadmin demoted to `user` is
     * still a demotion, and a superadmin who was never assigned one cannot be
     * demoted by this call at all.
     *
     * @param  array<int, string>  $before  Current role names
     * @param  array<int, \App\Models\Role>  $roles  Roles about to be applied
     *
     * @throws LastSuperadminException
     */
    private function guardLastSuperadmin(User $user, array $before, array $roles): void
    {
        $wasSuperadmin = in_array(SystemRole::SUPERADMIN, $before, true);

        if (! $wasSuperadmin) {
            return;
        }

        $stillSuperadmin = collect($roles)
            ->contains(fn ($role): bool => $role->name === SystemRole::SUPERADMIN);

        if ($stillSuperadmin) {
            return;
        }

        $others = User::role(SystemRole::SUPERADMIN)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($others === 0) {
            throw new LastSuperadminException();
        }
    }
}
