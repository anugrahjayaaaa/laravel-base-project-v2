<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates member list query parameters. Authorization is on the route.
 */
class MemberQueryRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('members.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:member_number,points_balance,user_name,tier_name,status,expires_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            // The three tabs. Anything else is a 422 rather than a silent
            // unfiltered list, so a typo in a bookmarked URL is visible.
            'status' => ['nullable', 'in:active,inactive,trashed'],
        ];
    }
}
