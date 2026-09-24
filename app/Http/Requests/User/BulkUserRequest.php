<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['delete', 'force_delete', 'restore', 'lock', 'unlock', 'activate', 'deactivate'])],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! User::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected user (ID: {$value}) is invalid.");
                }
            }],
        ];
    }
}