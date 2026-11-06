<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switch a tier on (Phase 18 revision).
 *
 * The list-button counterpart of the `is_active` switch on the tier form: same
 * column, same meaning — a tier that is on can be subscribed to.
 */
class MemberTierActivateAction
{
    public function run(MemberTier $tier, ?User $causer = null): void
    {
        // Re-activating an active tier would write an audit row for an event
        // that did not happen; the button is hidden for it anyway.
        if ($tier->is_active) {
            return;
        }

        DB::transaction(function () use ($tier, $causer): void {
            $tier->update(['is_active' => true]);

            $tier->audit('member_tier.activated', $causer, [
                'target_id' => $tier->id,
                'tier_name' => $tier->name,
            ]);
        });
    }
}
