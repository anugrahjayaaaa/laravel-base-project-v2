<?php

namespace App\Http\Requests\V1\Permission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the permission catalogue query string.
 *
 * The route carries the `can('permissions.view')` gate, so authorize() has
 * nothing left to decide.
 *
 * `sort` is type-checked only, for the reason given in RoleQueryRequest: the
 * whitelist belongs to PermissionIndexAction::SORTABLE, which applies it as a
 * fallback rather than rejecting the request.
 */
class PermissionQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
