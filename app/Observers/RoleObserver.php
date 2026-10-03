<?php

namespace App\Observers;

use App\Actions\V1\User\UserIndexAction;
use App\Models\Role;

/**
 * Clears the user index count cache when a ROLE changes.
 *
 * ## Why this exists alongside `UserObserver`
 *
 * `UserIndexAction::counts()` masks out superadmin accounts for anyone who is
 * not a superadmin, and that mask is built from a join over
 * `model_has_roles` + `roles`. So the cached number depends on role data, not
 * only on the `users` table — and `UserObserver` cannot see role changes.
 *
 * `RoleAssignAction` grants and revokes roles through `syncRoles()`, which
 * writes only the pivot. Measured: the `users` row is byte-identical
 * afterwards, `updated_at` unchanged, so no `saved` event fires and
 * `UserObserver` never runs. Granting superadmin therefore left
 * `user_index_counts.masked` holding the pre-grant totals indefinitely — a
 * `rememberForever` with no invalidation path, so it self-healed only if an
 * unrelated user save happened to bust the key by luck.
 *
 * `RoleAssignAction` calls `UserIndexAction::bustCache()` itself for that
 * pivot write, because a pivot change fires no event on either model. This
 * observer covers the rest, where the `roles` table itself changes:
 *
 * - `updated` — a RENAME. The mask joins on `roles.name = 'superadmin'`, so
 *   renaming a role changes who is excluded without any assignment changing.
 * - `deleted` — `RoleDeleteAction` detaches every holder first. That detach is
 *   a pivot write and fires nothing here either; the `deleted` event that
 *   follows is what catches it.
 * - `forceDeleted` — the pivot rows go with the role's FK cascade.
 * - `restored` — brings a trashed role's definition back.
 */
class RoleObserver
{
    /**
     * Clear the count cache after a role save — a rename changes the mask.
     */
    public function updated(Role $role): void
    {
        UserIndexAction::bustCache();
    }

    /**
     * Clear the count cache after a role is trashed.
     */
    public function deleted(Role $role): void
    {
        UserIndexAction::bustCache();
    }

    /**
     * Clear the count cache after a role is permanently deleted.
     */
    public function forceDeleted(Role $role): void
    {
        UserIndexAction::bustCache();
    }

    /**
     * Clear the count cache after a trashed role is restored.
     */
    public function restored(Role $role): void
    {
        UserIndexAction::bustCache();
    }
}
