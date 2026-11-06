<?php

namespace App\Actions\V1\Member;

use App\Models\MemberPointHistory;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * The membership dashboard view model (Phase 18, read-only slice).
 *
 * Counters leave as primitives; the expiring widget is an empty list until
 * rows exist, never a Blade-made zero. `broadcastUrl` is null until B6 ships
 * the notify route, and the broadcast itself is a queued job (C2), not this
 * request.
 */
class MemberDashboardAction
{
    /**
     * Days ahead that count as "expiring soon", from `member_expiry_alert_window_days`.
     * The fallback is the value the seeder writes, so an install that never saved
     * the settings page renders the same window it always did.
     */
    private const DEFAULT_ALERT_WINDOW_DAYS = 14;

    /** @return array<string, mixed> */
    public function run(): array
    {
        $alertWindowDays = SystemSetting::getInt(
            'member_expiry_alert_window_days',
            self::DEFAULT_ALERT_WINDOW_DAYS
        );

        $windowEnd = Carbon::now()->addDays($alertWindowDays);

        $expiring = MemberSubscription::currentlyActive()
            ->with(['member.user', 'tier'])
            ->where('expires_at', '<=', $windowEnd)
            ->orderBy('expires_at')
            ->get()
            ->map(fn (MemberSubscription $s) => (object) [
                'member_number' => $s->member?->member_number,
                'user_name' => $s->member?->user?->name,
                'tier_name' => $s->tier?->name,
                'expires_at' => $s->expires_at->format('Y-m-d'),
            ])
            ->all();

        return [
            'indexUrl' => route('members.index'),
            'broadcastUrl' => null,
            'alertWindowDays' => $alertWindowDays,
            'stats' => [
                (object) ['label' => 'Active members', 'value' => MemberSubscription::currentlyActive()->distinct()->count('member_id')],
                (object) ['label' => 'Expiring soon', 'value' => count($expiring)],
                (object) ['label' => 'Points issued', 'value' => (int) MemberPointHistory::where('delta', '>', 0)->sum('delta')],
            ],
            'expiring' => $expiring,
            'membersByTier' => MemberTier::orderBy('sort_order')
                ->get()
                ->map(fn (MemberTier $tier) => (object) [
                    'label' => $tier->name,
                    'count' => $tier->subscriptions()->currentlyActive()->count(),
                ])
                ->all(),
        ];
    }
}
