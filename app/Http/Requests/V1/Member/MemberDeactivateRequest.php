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
     * The reason is free text that goes straight into the audit row, and the
     * audit trail is exported as plain text on the audits screen. A CRLF in it
     * would forge a line there, so the whitespace is collapsed once at the
     * boundary instead of at each writer.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
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
