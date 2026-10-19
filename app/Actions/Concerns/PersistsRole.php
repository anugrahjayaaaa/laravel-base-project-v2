<?php

namespace App\Actions\Concerns;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * The shared write path for creating and updating a role.
 *
 * RoleCreateAction and RoleUpdateAction are separate classes because the User
 * side already is (UserCreateAction / UserUpdateAction), and a caller reading
 * the action list should see both verbs. What they share is everything that is
 * not the verb: the guard, the permission sync, the transaction boundary and
 * the audit write. Duplicating those across both classes is how one of them ends
 * up forgetting the audit, or casting permission ids differently.
 *
 * The verb itself — the system-role rename refusal — stays in RoleUpdateAction,
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
        $this->guardSystemPermissions($role, $data);

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
            $role->syncPermissions($this->assignablePermissionIds($role, $data['permissions'] ?? []));

            // Inside the transaction (DEP-003): an audit row that survives a
            // rollback records a save that never happened.
            //
            // The permission set is the part worth keeping. "A role was updated"
            // is not actionable during an incident; "this role gained report
            // export and lost user delete" is — and it is the only record of
            // what an admin's matrix submission actually granted, because the
            // role row itself only holds the current state.
            $role->audit($event, $causer, [
                'permission_count' => $role->permissions()->count(),
                'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
            ]);

            return $role;
        });
    }

    /**
     * The permission ids a save is allowed to leave on the role.
     *
     * Two rules about a permission whose module flag is off (`PermissionCatalog`
     * owns the mapping):
     *
     *  - A crafted POST must not grant one. The matrix is not the only way to
     *    reach this write, and "not offered" has to survive a request that
     *    names the id anyway.
     *  - A grant that already exists is KEPT. The matrix cannot render it, so
     *    dropping it here would mean an unrelated save silently rewrote a
     *    permission set the admin could not see — the data loss
     *    PermissionMatrixTest exists to prevent, one flag away from happening.
     *
     * So: assignable = the module is on, or the role already holds it.
     *
     * @param  array<int, mixed>  $submitted  Raw ids from the request, as strings
     * @return array<int, int>
     */
    private function assignablePermissionIds(Role $role, array $submitted): array
    {
        $visible = PermissionCatalog::visibleNames();
        $hidden = PermissionCatalog::namesForInactiveFeatures();

        // The role's own grants, read before the sync — after it they are the
        // new set and there is nothing left to preserve.
        $keep = $role->exists
            ? $role->permissions()->whereIn('name', $hidden)->pluck('id')
            : collect();

        $ids = array_map('intval', $submitted);

        // The guard is applied to the name the row owns, never to the id alone:
        // an id that is not in the table fails the same `whereIn`, so this also
        // keeps the old `exists:permissions,id` promise at the write.
        $granted = $ids === []
            ? collect()
            : Permission::query()
                ->whereIn('id', $ids)
                ->whereIn('name', $visible)
                ->pluck('id');

        return $granted->merge($keep)->unique()->values()->all();
    }

    /**
     * Refuse a permission payload aimed at a system role.
     *
     * `superadmin`, `admin` and `user` get their permissions from the seeder,
     * which is code. The role form's matrix is a POST away from emptying one of
     * them: submit no checkboxes and `syncPermissions([])` strips every
     * permission from the row, silently — no error, an audit row recording a
     * successful save. For `superadmin` that is the whole admin panel, gone by
     * one form post, and the next admin to notice has no way back.
     *
     * Checked against the STORED name (the row being written) rather than the
     * submitted one, matching the rename guard: the question is whether this
     * row is one the application depends on. The rename guard already stops a
     * system role being renamed INTO something editable, but an ordinary role
     * renamed TO `superadmin` must not become strippable either — and by the
     * time `persist()` runs, `$role->name` is still the old value, so the
     * incoming name is checked too.
     *
     * Refuses only when a `permissions` key is actually present. Every existing
     * caller sends one, but the key's absence must not become a new way to
     * fail: an update that legitimately carries no permissions key syncs
     * nothing, which is already a no-op on the pivot.
     */
    private function guardSystemPermissions(Role $role, array $data): void
    {
        if (! array_key_exists('permissions', $data)) {
            return;
        }

        $stored = (string) $role->name;
        $incoming = (string) ($data['name'] ?? $stored);

        if (! SystemRole::isSystem($stored) && ! SystemRole::isSystem($incoming)) {
            return;
        }

        $validator = Validator::make([], []);
        $validator->errors()->add(
            'permissions',
            __('System role permissions are defined in code and cannot be edited.')
        );

        throw new ValidationException($validator);
    }
}
