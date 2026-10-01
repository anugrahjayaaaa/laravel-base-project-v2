<?php

namespace App\Http\Requests\V1\Role;

use App\Http\Requests\BaseFormRequest;

/**
 * Authorizes permanently deleting a trashed role.
 *
 * Separate from `roles.delete` for the same reason the user's side splits
 * `users.delete` from `users.force_delete`: trashing is reversible, and the row
 * that goes away for good is the one carrying the audit subject. Whoever can
 * un-do a delete should not automatically be able to destroy the record of it.
 */
class ForceDeleteRoleRequest extends BaseFormRequest
{
    /**
     * Only a caller holding roles.force_delete may open the endpoint.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('roles.force_delete') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
