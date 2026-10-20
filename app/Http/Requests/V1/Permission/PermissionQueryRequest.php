<?php

namespace App\Http\Requests\V1\Permission;

use App\Actions\V1\Permission\PermissionIndexAction;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the permission catalogue query string, on both channels.
 *
 * The route carries the `can('permissions.view')` gate, so authorize() has
 * nothing left to decide.
 *
 * `sort` reuses `PermissionIndexAction::SORTABLE` as an `in:` rule: an unknown
 * column is a 422 for API clients and a refused form submission on the web,
 * and the whitelist the action applies is still the only list.
 */
class PermissionQueryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(PermissionIndexAction::SORTABLE)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
