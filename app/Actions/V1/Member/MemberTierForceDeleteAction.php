<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Permanently delete a trashed membership tier (Phase 18).
 *
 * Two-step like the member profile: the lookup reaching this Action is
 * `MemberTier::onlyTrashed()`, so a live tier is not erased without first being
 * moved to the trash.
 *
 * The refusal below is not a repeat of `MemberTierDeleteAction`'s. Trash is where
 * a tier with nothing on it goes, so a trashed row normally has no subscriptions
 * left — but `member_subscriptions.member_tier_id` is `restrictOnDelete`, and a
 * row that predates that rule would turn the DELETE into an integrity exception
 * the operator reads as a 500. Checking first keeps the answer a form error and
 * keeps the tier where it is.
 */
class MemberTierForceDeleteAction
{
    public function run(MemberTier $tier, ?User $causer = null): void
    {
        if ($tier->subscriptions()->exists()) {
            $validator = Validator::make([], []);

            $validator->errors()->add(
                'name',
                "Tier {$tier->name} still has subscriptions on it and cannot be permanently deleted."
            );

            throw new ValidationException($validator);
        }

        DB::transaction(function () use ($tier, $causer): void {
            $identity = ['tier_name' => $tier->name, 'slug' => $tier->slug];

            $tier->forceDelete();

            $tier->audit('member_tier.force_deleted', $causer, $identity);
        });
    }
}
