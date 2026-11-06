<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Take a loyalty profile out of the programme (Phase 18 revision).
 *
 * The member's own switch, independent of the subscription's: `MemberIndexAction`
 * reads `is_active` for the Inactive tab, the same way the users screen reads the
 * account flag.
 *
 * What happens to the period they already paid for turns on one question — is
 * this a sanction?
 *
 * - violation: the subscription is cancelled, forfeit by the decision taken here.
 * - not a violation: `paused_at` freezes the clock. The row keeps its status and
 *   its `expires_at`, so `MemberActivateAction` can hand back exactly the days
 *   that were bought and not used.
 *
 * Either way the audit row carries what the operator needs to rebuild the state
 * later — last tier, points balance and the days still outstanding — because
 * re-activation is a fresh decision, not an undo. The `reason` the operator typed
 * lives here too, never in a column.
 */
class MemberDeactivateAction
{
    public function run(Member $member, bool $violation, ?string $reason, ?User $causer = null): void
    {
        if (! $member->is_active) {
            return;
        }

        DB::transaction(function () use ($member, $violation, $reason, $causer): void {
            $subscription = $member->subscriptions()->currentlyActive()->first();

            $member->update(['is_active' => false]);

            if ($subscription !== null) {
                $subscription->update($violation
                    ? ['status' => MemberSubscription::STATUS_CANCELLED]
                    : ['paused_at' => now()]);
            }

            $member->audit('member.deactivated', $causer, [
                'target_id' => $member->id,
                'member_number' => $member->member_number,
                'last_tier' => $subscription?->tier?->name,
                'points_balance' => $member->points_balance,
                // Rounded up: a member who loses part of a day still lost a day
                // of service, and the audit row is what they are made whole from.
                'remaining_days' => $subscription === null
                    ? null
                    : (int) ceil(now()->diffInDays($subscription->expires_at, false)),
                'violation' => $violation,
                'reason' => $reason,
            ]);
        });
    }
}
