<?php

namespace App\Http\Requests\V1\User;

use App\Concerns\AuthorizesBulkAction;
use App\Models\User;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class BulkUserRequest extends BaseFormRequest
{
    use AuthorizesBulkAction;

    /** The users table paginates at 10; a bulk selection can never exceed it. */
    public const MAX_SELECTION = 10;

    protected function bulkEntityPrefix(): string
    {
        return 'users';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::bulkActions())],
            // Capped at the page size because the web table's select-all is
            // scoped to the rendered page (resources/js/helpers/bulk-actions.js),
            // so the UI can never submit more than this. The API shares this
            // request, and without the cap one authorised caller could post an
            // unbounded id list and hold a row lock per id in a single
            // transaction. Same ceiling on both channels, on purpose.
            'user_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'user_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! User::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected user (ID: {$value}) is invalid.");
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
            'user_ids.max' => 'You can act on at most :max users at a time.',
        ];
    }
}
