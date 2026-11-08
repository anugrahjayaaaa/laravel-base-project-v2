<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Put a loyalty profile back in the programme (Phase 18 revision).
 *
 * The switch itself: no subscription is issued here, because re-activation is
 * not a renewal — the member buys the next period themselves or through the
 * cashier, and the tier they last held is on the `member.deactivated` audit row
 * for whoever helps them.
 *
 * The one thing that is restored is time. A profile paused without a violation
 * kept its `expires_at`, so the span it spent paused is added back and the days
 * that were bought but never used are handed over intact.
 */
class MemberActivateAction
{
    public function run(Member $member, ?User $causer = null): void
    {
        if ($member->is_active) {
            return;
        }

        DB::transaction(function () use ($member, $causer): void {
            $member->update(['is_active' => true]);

            // Only a period still in force comes back. `paused_at` outlives a
            // cancellation — `MemberSubscriptionCancelAction` ends the period and
            // leaves the freeze stamp — so without this filter the days of a
            // cancelled row would be handed to an audit row claiming a resume
            // that no subscription will ever serve. Newest first, because two
            // paused rows would otherwise resume whichever MySQL happens to
            // return.
            $paused = $member->subscriptions()
                ->where('status', MemberSubscription::STATUS_ACTIVE)
                ->whereNotNull('paused_at')
                ->latest('starts_at')
                ->first();
            $resumedAt = null;

            if ($paused !== null) {
                $pausedFor = abs(now()->diffInSeconds($paused->paused_at, false));
                $resumedAt = $paused->expires_at->copy()->addSeconds($pausedFor);

                $paused->update(['expires_at' => $resumedAt, 'paused_at' => null]);
            }

            $member->audit('member.activated', $causer, [
                'target_id' => $member->id,
                'member_number' => $member->member_number,
                'resumed_tier' => $paused?->tier?->name,
                'resumed_expires_at' => $resumedAt?->toDateString(),
            ]);
        });
    }
}
