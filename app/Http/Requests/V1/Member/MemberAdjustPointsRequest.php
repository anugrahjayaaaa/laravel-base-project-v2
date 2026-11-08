<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a points adjustment on one member (Phase 18, P18-B4).
 *
 * Two ways to say the same thing, and the form offers both: type the points
 * (`delta`, signed — a deduction is a negative number), or type what the
 * customer spent (`purchase_amount`) and let the configured earning rule do the
 * arithmetic. Exactly one of them must arrive, which is the part only a
 * cross-field rule can check.
 */
class MemberAdjustPointsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('members.update') ?? false;
    }

    /**
     * Same reason as `MemberDeactivateRequest`: this text is written to the
     * ledger row and the audit row, and neither should carry a line break an
     * operator did not intend.
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
            'delta' => ['nullable', 'integer', Rule::notIn([0]), 'min:-1000000000', 'max:1000000000'],
            'purchase_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            // Required in both modes: the ledger row is the record of WHY the
            // balance moved, and an operator correcting a mistake is the case
            // where that note matters most.
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasDelta = $this->input('delta') !== null && $this->input('delta') !== '';
                $hasAmount = $this->input('purchase_amount') !== null && $this->input('purchase_amount') !== '';

                if ($hasDelta === $hasAmount) {
                    $validator->errors()->add(
                        'delta',
                        $hasDelta
                            ? 'Enter points or a purchase amount, not both.'
                            : 'Enter points, or a purchase amount to earn points from.'
                    );
                }
            },
        ];
    }
}
