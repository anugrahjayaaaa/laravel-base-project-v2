<?php

namespace App\Http\Requests\V1\Role;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes restoring a trashed role.
 *
 * `roles.restore` is separate from `roles.update` on purpose. Restoring brings
 * back a full permission set, so a holder of the edit permission who cannot create
 * roles would otherwise be able to re-introduce one from the trash — a privilege
 * grant dressed as a data recovery.
 */
class RestoreRoleRequest extends FormRequest
{
    /**
     * Only a caller holding roles.restore may open the endpoint.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('roles.restore') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
