<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorization policy for User model.
 *
 * Phase 3 focuses on the unlock action authorization boundary.
 * Other permissions (users.view, users.create, etc.) are established
 * in the RBAC phase (Phase 6).
 */
class UserPolicy
{
    /**
     * Determine whether the user can unlock the given user.
     *
     * The dedicated permission `users.unlock` is defined in
     * docs/base/features/user-management.md §User Operations.
     * RBAC permission-to-role assignment is implemented in Phase 6.
     */
    public function unlock(User $user, User $target): Response
    {
        // Only locked users can be unlocked.
        if (! $target->is_locked) {
            return Response::deny('This account is not locked.', 409);
        }

        return $user->can('users.unlock')
            ? Response::allow()
            : Response::deny('You do not have permission to unlock accounts.');
    }
}
