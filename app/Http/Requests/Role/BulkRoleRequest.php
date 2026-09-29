<?php

namespace App\Http\Requests\Role;

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
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['delete', 'force_delete', 'restore'])],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Role::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected role (ID: {$value}) is invalid.");
                }
            }],
        ];
    }
}
