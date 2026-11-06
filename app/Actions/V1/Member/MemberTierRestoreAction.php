<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pull a trashed membership tier back out of the trash (Phase 18).
 *
 * Nothing else is re-attached. A tier in use cannot be trashed at all
 * (`MemberTierDeleteAction` refuses while subscriptions point at it), so by the
 * time a row is here, no member was ever moved off it and there is no assignment
 * to restore — unlike a role, whose users were detached when it was trashed.
 *
 * There is no name-or-slug collision check, and none is needed: the unique
 * indexes cover soft-deleted rows too, so `MemberTierRequest` keeps rejecting a
 * second tier with that name or slug for as long as this one sits in the trash.
 * That is what makes a restore that would blow up on the index impossible rather
 * than merely unlikely.
 */
class MemberTierRestoreAction
{
    public function run(MemberTier $tier, ?User $causer = null): MemberTier
    {
        DB::transaction(function () use ($tier, $causer): void {
            $tier->restore();

            $tier->audit('member_tier.restored', $causer, [
                'target_id' => $tier->id,
                'tier_name' => $tier->name,
                'slug' => $tier->slug,
            ]);
        });

        return $tier;
    }
}
