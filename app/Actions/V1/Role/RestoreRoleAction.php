<?php

namespace App\Actions\V1\Role;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Restore a trashed role.
 *
 * The role comes back with its `role_has_permissions` rows intact, so its
 * permission set is exactly what it was. The ASSIGNMENT does not: the users were
 * detached when it was trashed, and nothing here puts them back. That asymmetry
 * is the point — a restore that silently re-granted report access to twelve
 * accounts would be indistinguishable, to an auditor, from the compromise it is
 * meant to undo.
 *
 * There is no name-collision check here and there does not need to be one. The
 * unique index is on (name, guard_name) and a soft-deleted row still occupies it,
 * so `Rule::unique('roles', 'name')` keeps rejecting a second role of that name
 * for as long as this one sits in the trash. The trade is deliberate: a trashed
 * role's name stays reserved until it is restored or permanently deleted, which
 * is what makes an accidental name reuse — and the restore that would then blow up
 * on the index — impossible rather than merely unlikely.
 */
class RestoreRoleAction
{
    /**
     * Restore a role from the trash.
     *
     * @param  Role       $role
     * @param  User|null  $causer Who to attribute the audit record to
     * @return Role        The restored role
     */
    public function run(Role $role, ?User $causer = null): Role
    {
        DB::transaction(function () use ($role, $causer): void {
            $role->restore();

            // Inside the transaction (DEP-003). `reassigned_users` is 0 by
            // construction — it is recorded so the audit trail states the
            // guarantee explicitly instead of leaving a reader to infer it.
            $role->audit('role.restored', $causer, [
                'reassigned_users' => 0,
                'permissions' => $role->permissions()->count(),
            ]);
        });

        return $role;
    }
}
