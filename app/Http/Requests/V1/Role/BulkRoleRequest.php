<?php

namespace App\Http\Requests\V1\Role;

use App\Http\Requests\Concerns\AuthorizesBulkAction;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk role action submitted by the roles index bulk bar.
 *
 * Mirrors BulkUserRequest. withTrashed() because the trash tab submits ids for
 * rows that are already soft-deleted, and a live-only lookup would report every
 * restore and force-delete ID as invalid.
 */
class BulkRoleRequest extends FormRequest
{
    use AuthorizesBulkAction;

    protected function bulkEntityPrefix(): string
    {
        return 'roles';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::bulkActions())],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Role::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected role (ID: {$value}) is invalid.");
                }
            }],
        ];
    }
}
