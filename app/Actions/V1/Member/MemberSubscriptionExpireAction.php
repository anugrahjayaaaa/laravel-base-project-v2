<?php

namespace App\Actions\V1\Member;

use App\Actions\V1\Member\Concerns\RevokesSubscriptionRole;
use App\Models\MemberSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Close a membership period whose end date has passed (Phase 18, P18-C1).
 *
 * The sweep's per-row half, and the Action-first rule's reason for existing:
 * the scheduled job and a manual expire must not each invent their own revoke
 * semantics. Same invariant as the cancel path — revoke only the role THIS row
 * applied, only when no other live period needs it — with `member.expired` as
 * the event and no human causer, because a cron tick did this.
 */
class MemberSubscriptionExpireAction
{
    use RevokesSubscriptionRole;

    public function run(MemberSubscription $subscription): void
    {
        if ($subscription->status !== MemberSubscription::STATUS_ACTIVE) {
            return;
        }

        DB::transaction(function () use ($subscription): void {
            $member = $subscription->member;

            if ($member === null) {
                return;
            }

            $roleName = $subscription->role_name;
            $user = $member->user;

            $subscription->update(['status' => MemberSubscription::STATUS_EXPIRED]);

            $roleRevoked = false;

            if ($roleName !== null && $user !== null && $this->roleStillLive($member, $roleName) === false) {
                $this->removeRole($user, $roleName);
                $roleRevoked = true;
            }

            $member->audit('member.expired', null, [
                'causer' => 'SYSTEM',
                'source' => 'system',
                'target_id' => $member->id,
                'member_number' => $member->member_number,
                'subscription_id' => $subscription->id,
                'tier_name' => $subscription->tier?->name,
                'role_name' => $roleName,
                'role_revoked' => $roleRevoked,
            ]);
        });
    }
}
