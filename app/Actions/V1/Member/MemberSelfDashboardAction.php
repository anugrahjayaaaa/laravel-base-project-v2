<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\MemberSubscription;

/**
 * The signed-in member's own dashboard view model.
 *
 * Same contract as the other member read Actions: every label, date and counter
 * leaves as a primitive, so the Blade renders what it is handed and computes
 * nothing. No URLs at all — a member account holds no permission, so there is
 * no member screen to link to.
 */
class MemberSelfDashboardAction
{
    /** The ledger is append-only and grows forever; the dashboard is a glance, not a statement. */
    private const RECENT_LEDGER_ROWS = 10;

    /** @return array<string, mixed> */
    public function run(Member $member): array
    {
        $member->load(['subscriptions.tier', 'pointHistories']);

        // Same reading as MemberShowAction::row(): the live period if there is
        // one, otherwise the most recent, so a lapsed member sees the tier they
        // had rather than an empty card.
        $current = $member->subscriptions->first(fn (MemberSubscription $s) => $s->isLive())
            ?? $member->subscriptions->first();

        [$statusLabel, $statusVariant] = match (true) {
            ! $member->is_active => ['INACTIVE', 'neutral'],
            $current === null => ['NONE', 'neutral'],
            $current->status === MemberSubscription::STATUS_CANCELLED => ['CANCELLED', 'neutral'],
            $current->isLive() => ['ACTIVE', 'success'],
            default => ['EXPIRED', 'danger'],
        };

        return [
            'memberNumber' => $member->member_number,
            'joinedAt' => $member->joined_at->format('Y-m-d'),
            'statusLabel' => $statusLabel,
            'statusVariant' => $statusVariant,
            'tierName' => $current?->tier?->name,
            'startsAt' => $current?->starts_at->format('Y-m-d'),
            'expiresAt' => $current?->expires_at->format('Y-m-d'),
            'daysRemaining' => $current?->isLive()
                ? max(0, (int) now()->diffInDays($current->expires_at, false))
                : null,
            'pointsBalance' => $member->points_balance,
            'points' => $member->pointHistories
                ->take(self::RECENT_LEDGER_ROWS)
                ->map(fn (MemberPointHistory $p) => (object) [
                    'created_at' => $p->created_at?->format('Y-m-d'),
                    'reason' => $p->reason,
                    'delta_display' => sprintf('%+d', $p->delta),
                    'delta_variant' => $p->delta < 0 ? 'danger' : 'success',
                    'balance_after' => $p->balance_after,
                ])
                ->all(),
        ];
    }
}
