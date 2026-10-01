<?php

namespace App\Http\Requests\V1\Role;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the roles index query string.
 *
 * The route carries the `can('roles.view')` gate, so authorize() has nothing
 * left to decide; it is spelled out here rather than returning true silently so
 * the reason is readable next to it.
 *
 * `sort` is type-checked only. The column whitelist stays in
 * RoleIndexAction::SORTABLE, where it is applied as a fallback: an unknown
 * column is ignored and the default order is used. Repeating the list here as
 * an `in:` rule would reject the request with a 422 instead, which is a
 * different contract, and would leave the whitelist in two places able to drift
 * apart. The action still cannot pass an unchecked value to orderBy — that
 * whitelist is the guard, and it is covered by the injection tests.
 */
class RoleQueryRequest extends BaseFormRequest
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
            'trashed' => ['nullable', 'boolean'],
        ];
    }
}
