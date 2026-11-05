<?php

namespace App\Http\Requests\V1\Member;

use App\Concerns\AuthorizesBulkAction;
use App\Http\Requests\BaseFormRequest;
use App\Models\Member;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk member action submitted by the members index bulk bar.
 *
 * Mirrors `BulkUserRequest`, with `withTrashed()` on the existence check because
 * the trash tab submits ids for profiles that are already soft-deleted.
 */
class BulkMemberRequest extends BaseFormRequest
{
    use AuthorizesBulkAction;

    /** The members table paginates at 10; a bulk selection can never exceed it. */
    public const MAX_SELECTION = 10;

    protected function bulkEntityPrefix(): string
    {
        return 'members';
    }

    /**
     * @return array<string, array<int, string|callable>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::bulkActions())],
            'member_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'member_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Member::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected member (ID: {$value}) is invalid.");
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
            'member_ids.max' => 'You can act on at most :max members at a time.',
        ];
    }
}
