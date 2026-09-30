<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\NormalizesRolePayload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates new user creation data.
 */
class CreateUserRequest extends FormRequest
{
    use NormalizesRolePayload;

    /**
     * Creating a user is an admin action.
     *
     * Public self-registration does not come through here — it has its own
     * `RegisterRequest`, so returning false here closes the admin form without
     * touching the public signup path.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('users.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'roles' => ['nullable', 'array'],
            // P6-E5. Boolean, and deliberately NOT a `required` rule: the
            // confirmation is demanded by AssignRolesAction only when the sync
            // actually adds or removes superadmin, so an ordinary role edit
            // does not have to carry a field it has no use for.
            'confirm_superadmin' => ['nullable', 'boolean'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
