<?php

namespace App\Http\Requests\V1\Role;

use App\Concerns\AuthorizesBulkAction;
use App\Models\Role;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk role action submitted by the roles index bulk bar.
 *
 * Mirrors BulkUserRequest. withTrashed() because the trash tab submits ids for
 * rows that are already soft-deleted, and a live-only lookup would report every
 * restore and force-delete ID as invalid.
 */
class BulkRoleRequest extends BaseFormRequest
{
    use AuthorizesBulkAction;

    /** The roles table paginates at 10; a bulk selection can never exceed it. */
    public const MAX_SELECTION = 10;

    protected function bulkEntityPrefix(): string
    {
        return 'roles';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::bulkActions())],
            // Capped at the page size for the same reason as BulkUserRequest:
            // the select-all is scoped to the rendered table, so the UI cannot
            // reach past it, and the API sharing this request should not be able
            // to hand the handler an unbounded id list.
            'role_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'role_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Role::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected role (ID: {$value}) is invalid.");
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role_ids.max' => 'You can act on at most :max roles at a time.',
        ];
    }
}
