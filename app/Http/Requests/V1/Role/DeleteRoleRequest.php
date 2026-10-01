<?php

namespace App\Http\Requests\V1\Role;

use App\Http\Requests\BaseFormRequest;

/**
 * Authorizes role deletion.
 *
 * Delete carries no input to validate — it is a bare route parameter — but it
 * still needs the authorization half. Reading a plain `Request` here skipped the
 * permission check entirely: a user holding nothing reached RoleDeleteAction and
 * got a 302 with the action's own refusal message instead of a 403, which told
 * an unauthorized caller that the role exists and is a system role.
 */
class DeleteRoleRequest extends BaseFormRequest
{
    /**
     * Only a caller holding roles.delete may open the endpoint.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('roles.delete') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
