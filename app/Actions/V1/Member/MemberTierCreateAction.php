<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a membership tier (Phase 18, P18-B6).
 *
 * The audit row is written inside the transaction, by this layer, so every
 * caller produces exactly one `member_tier.created` record and a rollback takes
 * it with it.
 */
class MemberTierCreateAction
{
    /**
     * @param  array<string, mixed>  $data  name, slug, role_name, price, duration_days, point_multiplier, is_active, sort_order
     */
    public function run(array $data, ?User $causer = null): MemberTier
    {
        return DB::transaction(function () use ($data, $causer): MemberTier {
            $tier = MemberTier::create($data);

            $tier->audit('member_tier.created', $causer, [
                'target_id' => $tier->id,
                'tier_name' => $tier->name,
                'slug' => $tier->slug,
            ]);

            return $tier;
        });
    }
}
