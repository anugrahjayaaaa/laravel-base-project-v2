<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates a deactivation of one member (Phase 18 revision).
 *
 * The sanction question decides what happens to the period they already paid
 * for, so the reason is mandatory exactly when it is a sanction: "violation"
 * without a stated reason is an accusation nobody recorded. A plain
 * administrative pause needs no reason — it takes nothing away.
 */
class MemberDeactivateRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('members.deactivate') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'violation' => ['required', 'boolean'],
            'reason' => ['required_if:violation,1', 'nullable', 'string', 'max:500'],
        ];
    }
}
