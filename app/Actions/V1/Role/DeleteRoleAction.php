<?php

namespace App\Actions\V1\Role;

use App\Models\Role;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Trash a role and revoke it from everyone holding it.
 *
 * Soft delete alone would NOT revoke anything an administrator can see. Spatie's
 * own `deleting` hook deliberately skips `detach()` when the model is not force
 * deleting, so the `model_has_roles` pivot rows survive and a later `restore()`
 * silently hands the role — and every permission it carries — back to whoever
 * held it. For a role that was retired on purpose, that is the wrong default: the
 * un-do has to be explicit, and the revocation has to be an event in the audit
 * trail rather than a side effect of a row update.
 *
 * So the policy is: trashing a role DEASSIGNS it. `$role->users()->detach()` runs
 * first, inside the transaction, and the audit row records how many people were
 * affected. Permissions stay on the role (`role_has_permissions` is untouched), so
 * `restore()` brings the definition back — but the assignment has to be redone by
 * hand, which is what makes the restore deliberate.
 *
 * The global SoftDeletes scope is what makes the revocation take effect on the
 * read side: Spatie resolves roles through App\Models\Role, so a trashed role is
 * absent from `$user->roles`, `hasRole()`, and every `can()` without a single
 * change at the call sites.
 *
 * ponytail: no "reassign to a default role" mode. A user who loses a role keeps
 * whatever else they hold; inventing a fallback role here would be a second
 * writer for `registration_default_role` (SystemSetting) and would silently grant
 * access nobody asked for. Add it when an app actually needs the behaviour.
 */
class DeleteRoleAction
{
    /**
     * Trash a role, revoking it from every user holding it.
     *
     * @param  Role       $role
     * @param  User|null  $causer Who to attribute the audit record to
     * @param  bool       $force   Trash even while users still hold the role
     * @return Role        The trashed role, for the audit trail and the flash message
     *
     * @throws ValidationException When the role is a system role, or still has users
     */
    public function run(Role $role, ?User $causer = null, bool $force = false): Role
    {
        $this->validate($role, $force);

        DB::transaction(function () use ($role, $causer): void {
            $affected = $role->users()->count();

            // First, so the pivot rows are gone even if the soft delete below is
            // the thing that fails. Spatie skips this on a non-force delete.
            $role->users()->detach();

            $role->delete();

            // Inside the transaction (DEP-003). `revoked_users` is the whole point
            // of the audit row: "a role was trashed" is not actionable during an
            // incident, "these 12 accounts lost report access" is.
            $role->audit('role.deleted', $causer, [
                'revoked_users' => $affected,
                'revoked_permissions' => $role->permissions()->count(),
            ]);
        });

        return $role;
    }

    /**
     * Refuse deletions that would break something.
     *
     * A role with users is refused by default because trashing it deassigns every
     * one of them — the deletion succeeds and each of those accounts silently
     * loses the access the role was carrying, which is the outcome an admin
     * clicking "Delete" least expects. `$force` is the deliberate override for a
     * role being retired; the action then records the count in the audit row.
     */
    private function validate(Role $role, bool $force): void
    {
        $validator = Validator::make([], []);

        if (SystemRole::isSystem($role->name)) {
            $validator->errors()->add('name', __('System roles cannot be deleted.'));

            throw new ValidationException($validator);
        }

        if ($force || $role->trashed()) {
            return;
        }

        $count = $role->users()->count();

        if ($count > 0) {
            $validator->errors()->add(
                'name',
                __('This role is still assigned to :count user(s). Trashing it removes the role from all of them.', ['count' => $count])
            );

            throw new ValidationException($validator);
        }
    }
}
