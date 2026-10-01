<?php

namespace App\Http\Requests\V1\Role;

use App\Models\RoleLookup;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates role updates.
 *
 * `ignore($this->role)` keeps the unique rule from rejecting the role against
 * its own current row, which is what makes saving a system role — whose name
 * field is readonly in the form and therefore resubmitted unchanged — fail
 * with "name has already been taken" instead of saving.
 */
class UpdateRoleRequest extends BaseFormRequest
{
    /**
     * roles.update renames a role. roles.assign_permissions rewrites what that
     * role grants — a different power, and the only one here that can escalate
     * the caller (grant a role they do not hold themselves, or edit superadmin).
     *
     * They were one permission in practice, because nothing checked the second:
     * the matrix rendered unconditionally and `permissions` was accepted from
     * anyone who could open the form. So `roles.assign_permissions` existed in
     * the catalogue, appeared in the permissions UI, and gated nothing at all.
     *
     * Renaming without the permission stays allowed. Only a posted permission
     * set is refused, so the form still works for a rename-only admin.
     */
    public function authorize(): bool
    {
        if (! ($this->user()?->can('roles.update') ?? false)) {
            return false;
        }

        return ! $this->has('permissions')
            || ($this->user()?->can('roles.assign_permissions') ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('roles', 'name')
                    ->ignore($this->role)
                    ->where('guard_name', RoleLookup::guard()),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
