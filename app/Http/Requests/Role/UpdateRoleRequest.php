<?php

namespace App\Http\Requests\Role;

use App\Models\RoleLookup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates role updates.
 *
 * `ignore($this->role)` keeps the unique rule from rejecting the role against
 * its own current row, which is what makes saving a system role — whose name
 * field is readonly in the form and therefore resubmitted unchanged — fail
 * with "name has already been taken" instead of saving.
 */
class UpdateRoleRequest extends FormRequest
{
    /**
     * Only a caller holding roles.update may open the endpoint.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('roles.update') ?? false;
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
