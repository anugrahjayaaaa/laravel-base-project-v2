<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the tiers index query string. Authorization is on the route.
 *
 * No sort rule: the tier list is ordered by `sort_order` only, which the
 * operator changes on the form, not from a column header.
 */
class MemberTierQueryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('members.tiers.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            // active = offered to new subscriptions, inactive = switched off,
            // trashed = in the trash. A tier can be trashed and inactive at the
            // same time; `trashed` wins, so the two tabs never double-count it.
            'status' => ['nullable', 'in:active,inactive,trashed'],
        ];
    }
}
