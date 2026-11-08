<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edit a membership tier (Phase 18, P18-B6).
 *
 * The change is forward-only: a subscription copies `role_name` and its expiry
 * when it is created, so editing price, duration or role here does not touch
 * anybody who is already subscribed. `shortcut:` that stays true until the
 * subscribe path (P18-B6's sibling) exists — if a tier edit ever has to re-issue
 * live subscriptions, that belongs in this Action, not in the model.
 */
class MemberTierUpdateAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function run(MemberTier $tier, array $data, ?User $causer = null): MemberTier
    {
        return DB::transaction(function () use ($tier, $data, $causer): MemberTier {
            // Captured before the write, as plain values: after it these are the
            // new state, and a properties bag reading "old => new" is worse than
            // no properties at all.
            $before = $tier->only([
                'name', 'slug', 'role_name', 'price',
                'duration_days', 'point_multiplier', 'is_active', 'sort_order',
            ]);

            $tier->update($data);

            $tier->audit('member_tier.updated', $causer, [
                'target_id' => $tier->id,
                'tier_name' => $tier->name,
            ] + $this->changedFields($before, $tier));

            return $tier;
        });
    }

    /**
     * Only the fields that actually moved, as `field => [from, to]` — the same
     * shape `user.updated` writes, so the audit list renders one format.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changedFields(array $before, MemberTier $tier): array
    {
        $changed = [];

        foreach ($before as $field => $from) {
            $to = $tier->{$field};

            if ((string) $from !== (string) $to) {
                $changed[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changed;
    }
}
