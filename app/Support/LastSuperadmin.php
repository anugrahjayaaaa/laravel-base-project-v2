<?php

namespace App\Support;

use App\Exceptions\LastSuperadminException;
use App\Models\User;

/**
 * The last-superadmin invariant, counted once for every caller.
 *
 * `RoleAssignAction` already refuses to leave nobody able to administer the
 * app, but it only covers ONE of the four ways a superadmin can disappear: the
 * role is stripped. The other three — deactivate, soft delete, force delete —
 * each took a plain `User` and wrote a column, and none of them asked. Verified
 * 2026-09-30: a caller holding `users.deactivate` + `users.delete` +
 * `users.force_delete` and NOT being a superadmin could deactivate the only
 * superadmin, then delete them, then force-delete them, ending at zero
 * superadmins and an application nobody can administer. Soft-deleting does not
 * even hide it: Spatie's global scope drops the role, so `hasRole()` goes false
 * immediately.
 *
 * So the count lives here rather than in any one action. Three sibling actions
 * with the same question is exactly the shape where a guard added to the path
 * the ticket named leaves the other two open.
 *
 * The count is deliberately about ACTIVE superadmins — the people who can
 * actually administer right now. Counting soft-deleted rows instead would let a
 * trashed superadmin "count" toward the total and lock everyone out.
 */
class LastSuperadmin
{
    /**
     * Refuse an action that would leave no active superadmin behind.
     *
     * No-op unless the target IS an active superadmin AND is the last one.
     * A caller removing the second of three changes nothing about whether the
     * app can still be administered, so it is allowed.
     *
     * @throws \App\Exceptions\LastSuperadminException When the target is the last one
     */
    public static function guard(User $target, string $verb = 'removed'): void
    {
        if (! static::isLastActiveSuperadmin($target)) {
            return;
        }

        throw new LastSuperadminException(
            __("The last superadmin cannot be :verb.", ['verb' => $verb])
        );
    }

    /**
     * Whether this user is an active superadmin and the only one left.
     *
     * Checks the target's own state first, so a non-superadmin costs one cheap
     * boolean rather than a COUNT.
     */
    public static function isLastActiveSuperadmin(User $target): bool
    {
        if ($target->trashed() || ! $target->is_active) {
            return false;
        }

        if (! $target->hasRole(SystemRole::SUPERADMIN)) {
            return false;
        }

        return static::activeSuperadminCount() <= 1;
    }

    /**
     * How many accounts can administer the app right now.
     *
     * Scoped to active, non-trashed rows. `User::role()` already resolves
     * through the soft-delete global scope, so `where('is_active', true)` is the
     * only extra condition needed.
     */
    public static function activeSuperadminCount(): int
    {
        return User::role(SystemRole::SUPERADMIN)
            ->where('is_active', true)
            ->count();
    }
}
