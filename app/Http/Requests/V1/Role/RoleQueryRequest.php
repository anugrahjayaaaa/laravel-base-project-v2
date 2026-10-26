<?php

namespace App\Http\Requests\V1\Role;

use App\Actions\V1\Role\RoleIndexAction;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the roles index query string, on both channels.
 *
 * The route carries the `can('roles.view')` gate, so authorize() has nothing
 * left to decide; it is spelled out here rather than returning true silently so
 * the reason is readable next to it.
 *
 * `sort` reuses `RoleIndexAction::SORTABLE` as an `in:` rule, so an unknown
 * column is a 422 as `response-contract.md` promises and the web index refuses
 * it too. The list lives in the action, which still guards `orderBy` itself.
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
            'sort' => ['nullable', Rule::in(RoleIndexAction::SORTABLE)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'trashed' => ['nullable', 'boolean'],
        ];
    }
}
