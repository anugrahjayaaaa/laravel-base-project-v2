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
            // Only what `MemberIndexAction::SORTABLE` actually orders by. A column
            // accepted here but dropped there answers 200 with a unchanged order,
            // so the header, the rule and the Action have to agree on one list.
            'sort' => ['nullable', 'in:member_number,points_balance'],
            'direction' => ['nullable', 'in:asc,desc'],
            // The three tabs. Anything else is a 422 rather than a silent
            // unfiltered list, so a typo in a bookmarked URL is visible.
            'status' => ['nullable', 'in:active,inactive,trashed'],
        ];
    }
}
