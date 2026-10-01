<?php

namespace App\Http\Requests\V1\Role;

use App\Models\RoleLookup;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates role creation.
 *
 * The unique rule is scoped to the guard Spatie actually resolves for a User
 * (RoleLookup::guard()). Spatie keys a role by (name, guard_name), so an
 * unscoped `unique:roles,name` would report a name taken on an `api` row that
 * this app never assigns and never checks — the same guard collision
 * RoleGuardTest pins for the seeder.
 */
class StoreRoleRequest extends BaseFormRequest
{
    /**
     * roles.create makes the role; roles.assign_permissions decides what it
     * grants. Creating a role that grants nothing is harmless, handing one
     * `superadmin` is not — so the two are separate checks, for the same reason
     * UpdateRoleRequest splits them: an admin who may name a role but not
     * distribute permissions can still create an empty one.
     */
    public function authorize(): bool
    {
        if (! ($this->user()?->can('roles.create') ?? false)) {
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
                Rule::unique('roles', 'name')->where('guard_name', RoleLookup::guard()),
            ],
            'permissions' => ['nullable', 'array'],
            // Ids, not names: the matrix partial posts permission ids and Spatie
            // resolves a string as a NAME, which throws when it is not one.
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
