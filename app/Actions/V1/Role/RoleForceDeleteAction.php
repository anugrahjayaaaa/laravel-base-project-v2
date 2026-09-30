<?php

namespace App\Actions\V1\Role;

use App\Models\Role;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Permanently delete a trashed role (hard delete).
 *
 * Only from the trash. A hard delete of a live role would drop its
 * `role_has_permissions` and `model_has_roles` rows through the FK cascades
 * without a `deleted_at` marker and without the revocation being an event anyone
 * can find in the audit trail — which is precisely the state the soft delete
 * exists to make visible. Requiring the role to be trashed first means the
 * permanent step is always the second half of a recorded two-step.
 */
class RoleForceDeleteAction
{
    /**
     * Permanently delete a trashed role.
     *
     * @param  Role       $role
     * @param  User|null  $causer Who to attribute the audit record to
     * @return Role        The deleted role, for the audit trail and the flash message
     *
     * @throws ValidationException When the role is a system role, or is not trashed
     */
    public function run(Role $role, ?User $causer = null): Role
    {
        $this->validate($role);

        DB::transaction(function () use ($role, $causer): void {
            $role->forceDelete();

            $role->audit('role.force_deleted', $causer);
        });

        return $role;
    }

    /**
     * Refuse a permanent delete of a live or system role.
     */
    private function validate(Role $role): void
    {
        $validator = Validator::make([], []);

        if (SystemRole::isSystem($role->name)) {
            $validator->errors()->add('name', __('System roles cannot be deleted.'));

            throw new ValidationException($validator);
        }

        if (! $role->trashed()) {
            $validator->errors()->add('name', __('Move the role to the trash before deleting it permanently.'));

            throw new ValidationException($validator);
        }
    }
}
