<?php

namespace App\Actions\Concerns;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The shared write path for creating and updating a role.
 *
 * CreateRoleAction and UpdateRoleAction are separate classes because the User
 * side already is (CreateUserAction / UpdateUserAction), and a caller reading
 * the action list should see both verbs. What they share is everything that is
 * not the verb: the guard, the permission sync, the transaction boundary and
 * the audit write. Duplicating those across both classes is how one of them ends
 * up forgetting the audit, or casting permission ids differently.
 *
 * The verb itself — the system-role rename refusal — stays in UpdateRoleAction,
 * because "a row that already exists" is the only thing it depends on.
 */
trait PersistsRole
{
    /**
     * Persist a role and its permissions inside one transaction, audited.
     *
     * @param  array      $data   Keys: name, permissions (ids)
     * @param  Role       $role   Unsaved on create, persisted on update
     * @param  User|null  $causer Who to attribute the audit record to
     * @param  string     $event  role.created or role.updated
     * @return Role
     */
    protected function persist(Role $role, array $data, ?User $causer, string $event): Role
    {
        return DB::transaction(function () use ($role, $data, $causer, $event): Role {
            $role->forceFill([
                'name' => $data['name'],
                'guard_name' => RoleLookup::guard(),
            ])->save();

            // intval is mandatory, not defensive tidying. The matrix partial posts
            // checkbox values, which arrive as strings ('19'). Spatie's
            // syncPermissions() resolves each value through findByName() when it is
            // not an int key, so '19' is looked up as a permission literally NAMED
            // "19" and throws PermissionDoesNotExist.
            $role->syncPermissions(array_map('intval', $data['permissions'] ?? []));

            // Inside the transaction (DEP-003): an audit row that survives a
            // rollback records a save that never happened.
            $role->audit($event, $causer);

            return $role;
        });
    }
}
