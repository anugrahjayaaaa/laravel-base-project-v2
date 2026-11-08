<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switch a tier off (Phase 18 revision).
 *
 * This is the reversible retirement `MemberTierDeleteAction` refuses to do by
 * trashing: subscriptions already on this tier keep running, only new ones stop.
 */
class MemberTierDeactivateAction
{
    public function run(MemberTier $tier, ?User $causer = null): void
    {
        if (! $tier->is_active) {
            return;
        }

        DB::transaction(function () use ($tier, $causer): void {
            $tier->update(['is_active' => false]);

            $tier->audit('member_tier.deactivated', $causer, [
                'target_id' => $tier->id,
                'tier_name' => $tier->name,
            ]);
        });
    }
}
