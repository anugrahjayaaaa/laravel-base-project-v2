<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;
use App\Models\MemberTier;
use Illuminate\Validation\Rule;

/**
 * Validates the tier chosen for a new membership period (Phase 18, P18-B2).
 *
 * The tier is the only input: the period length and the role come from the tier
 * row itself, and `MemberSubscriptionActivateAction` owns the refusals a lookup
 * cannot express (an inactive tier, a second live period).
 */
class MemberSubscribeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('members.update') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'member_tier_id' => [
                'required',
                'integer',
                // `exists` reads the table, not the model, so it would otherwise
                // accept a tier sitting in the trash.
                Rule::exists('member_tiers', 'id')->where(fn ($q) => $q->whereNull('deleted_at')),
            ],
        ];
    }

    /**
     * The tier the Action is handed, resolved once here so the controller does
     * not query.
     */
    public function tier(): MemberTier
    {
        return MemberTier::query()->findOrFail((int) $this->validated('member_tier_id'));
    }
}
