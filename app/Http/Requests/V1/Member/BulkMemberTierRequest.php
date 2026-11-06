<?php

namespace App\Http\Requests\V1\Member;

use App\Concerns\AuthorizesBulkAction;
use App\Http\Requests\BaseFormRequest;
use App\Models\MemberTier;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk tier action submitted by the tiers index bulk bar.
 *
 * The prefix is `members.tiers`: tier configuration authorizes separately from
 * member profiles, so `member_tiers.delete` covers the tier trash and
 * `members.delete` does not reach this endpoint.
 */
class BulkMemberTierRequest extends BaseFormRequest
{
    use AuthorizesBulkAction;

    /**
     * A bound on what one transaction touches. The list paginates at 10 but
     * selections can span pages, so this stays above a page size.
     */
    public const MAX_SELECTION = 10;

    protected function bulkEntityPrefix(): string
    {
        return 'member_tiers';
    }

    /**
     * @return array<string, array<int, string|callable>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::bulkActions())],
            'tier_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'tier_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! MemberTier::withTrashed()->whereKey($value)->exists()) {
                    $fail("The selected tier (ID: {$value}) is invalid.");
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
            'tier_ids.max' => 'You can act on at most :max tiers at a time.',
        ];
    }
}
