<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Move a membership tier to the trash (Phase 18, P18-B6).
 *
 * The audit row is written inside the transaction, by this layer, so every
 * caller produces exactly one `member_tier.deleted` record and a rollback takes
 * it with it.
 */
class MemberTierDeleteAction
{
    public function run(MemberTier $tier, ?User $causer = null): void
    {
        // Subscriptions point at this row by id and are not trashed with it, so
        // deleting a tier in use leaves members whose tier reads as nothing —
        // the detail page would show `NONE` for somebody paying for Gold.
        // Retiring a tier is `is_active = false`; this refuses the other one.
        if ($tier->subscriptions()->exists()) {
            $validator = Validator::make([], []);

            $validator->errors()->add(
                'name',
                "Tier {$tier->name} still has subscriptions on it. Deactivate it instead of deleting it."
            );

            throw new ValidationException($validator);
        }

        DB::transaction(function () use ($tier, $causer): void {
            // Captured before the delete for the same reason `member.deleted`
            // captures the member number: the name is how an operator scanning
            // the trail recognises what was removed.
            $identity = ['target_id' => $tier->id, 'tier_name' => $tier->name, 'slug' => $tier->slug];

            $tier->delete();

            $tier->audit('member_tier.deleted', $causer, $identity);
        });
    }
}
