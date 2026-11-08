<?php

namespace App\Actions\V1\Member;

use App\Actions\V1\Member\Concerns\RevokesSubscriptionRole;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * End a membership period before or at its term (Phase 18, P18-B3).
 *
 * Only the role THIS row applied is revoked, and only when no other live period
 * still needs it — the copy made at activation is what is read back, not the
 * tier's current role, so a tier renamed after the sale cannot strand a role on
 * the account.
 */
class MemberSubscriptionCancelAction
{
    use RevokesSubscriptionRole;

    public function run(MemberSubscription $subscription, ?User $causer = null): void
    {
        if ($subscription->status === MemberSubscription::STATUS_CANCELLED) {
            return;
        }

        DB::transaction(function () use ($subscription, $causer): void {
            // `belongsTo` reads the default scope, which hides a trashed profile,
            // so the fallback is what makes the trash visible here at all. The FK
            // cascades on member force-delete, so a null after both reads means
            // there is nothing left to cancel.
            $member = $subscription->member
                ?? Member::withTrashed()->find($subscription->member_id);

            if ($member === null) {
                throw new ModelNotFoundException('This subscription has no member profile.');
            }

            // A trashed profile is read-only on every other write this module
            // has — points, subscribe, activate and deactivate all answer 404
            // for it — so cancelling one of its periods here would be the one
            // write that slipped past. Same refusal, both channels.
            if ($member->trashed()) {
                throw new ModelNotFoundException('This member is in the trash.');
            }

            $roleName = $subscription->role_name;
            $user = $member->user;

            $subscription->update(['status' => MemberSubscription::STATUS_CANCELLED]);

            if ($roleName !== null && $user !== null && $this->roleStillLive($member, $roleName) === false) {
                $this->removeRole($user, $roleName);

                $member->audit('member.role_synced', $causer, [
                    'target_id' => $member->id,
                    'member_number' => $member->member_number,
                    'role_name' => $roleName,
                    'change' => 'revoked',
                ]);
            }

            $member->audit('member.subscription_cancelled', $causer, [
                'target_id' => $member->id,
                'member_number' => $member->member_number,
                'subscription_id' => $subscription->id,
                'tier_name' => $subscription->tier?->name,
            ]);
        });
    }
}
