<?php

namespace App\Http\Requests\V1\User;

use App\Http\Requests\Concerns\AuthorizesBulkAction;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUserRequest extends FormRequest
{
    use AuthorizesBulkAction;

    protected function bulkEntityPrefix(): string
    {
        return 'users';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::bulkActions())],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! User::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected user (ID: {$value}) is invalid.");
                }
            }],
        ];
    }
}
