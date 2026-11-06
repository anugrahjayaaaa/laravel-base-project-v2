<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\RoleLookup;
use App\Models\User;
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
    public function run(MemberSubscription $subscription, ?User $causer = null): void
    {
        if ($subscription->status === MemberSubscription::STATUS_CANCELLED) {
            return;
        }

        DB::transaction(function () use ($subscription, $causer): void {
            // The FK cascades on member delete, so a live row always has its
            // profile; the guard is the integrity precondition, not a branch.
            $member = $subscription->member;

            if ($member === null) {
                return;
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

    /**
     * The status write already ran, so `currentlyActive` no longer sees this row;
     * a hit here is a different live period carrying the same role.
     */
    private function roleStillLive(Member $member, string $roleName): bool
    {
        return $member->subscriptions()->currentlyActive()->where('role_name', $roleName)->exists();
    }

    private function removeRole(User $user, string $roleName): void
    {
        $target = array_values(
            array_filter($user->roles->pluck('name')->all(), fn (string $name): bool => $name !== $roleName)
        );

        $user->syncRoles(RoleLookup::findMany($target)->values()->all());
    }
}
