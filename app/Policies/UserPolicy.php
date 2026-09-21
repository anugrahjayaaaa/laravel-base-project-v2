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

    /**
     * Determine whether the user can activate the given user.
     */
    public function activate(User $user, User $target): Response
    {
        if ($target->is_locked) {
            return Response::deny('Cannot activate a locked user. Please unlock first.', 409);
        }

        if (! $target->is_active) {
            return Response::allow();
        }

        return Response::deny('This account is already active.', 409);
    }

    /**
     * Determine whether the user can deactivate the given user.
     */
    public function deactivate(User $user, User $target): Response
    {
        if ($target->is_locked) {
            return Response::deny('Cannot deactivate a locked user. Please unlock first.', 409);
        }

        if (! $target->is_active) {
            return Response::deny('This account is already inactive.', 409);
        }

        return $user->can('users.deactivate')
            ? Response::allow()
            : Response::deny('You do not have permission to deactivate accounts.');
    }

    /**
     * Determine whether the user can lock the given user.
     */
    public function lock(User $user, User $target): Response
    {
        if (! $target->is_active) {
            return Response::deny('Cannot lock an inactive user. Please activate first.', 409);
        }

        if ($target->is_locked) {
            return Response::deny('This account is already locked.', 409);
        }

        return $user->can('users.lock')
            ? Response::allow()
            : Response::deny('You do not have permission to lock accounts.');
    }
}
