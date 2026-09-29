<?php

namespace App\Http\Requests\User;

use App\Models\RoleLookup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a standalone role assignment payload.
 */
class AssignRolesRequest extends FormRequest
{
    /**
     * Assigning roles is its own permission, separate from `users.update` —
     * someone who can edit a name and email has no business handing out
     * superadmin.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('users.assign_roles') ?? false;
    }

    public function rules(): array
    {
        return [
            'roles' => ['array'],
            // Scoped to the guard the app can actually assign: the table can hold
            // the same name twice, and a name that only exists on another guard
            // would validate here and then assign nothing.
            'roles.*' => [
                'string',
                Rule::exists('roles', 'name')->where('guard_name', RoleLookup::guard()),
            ],
        ];
    }
}
