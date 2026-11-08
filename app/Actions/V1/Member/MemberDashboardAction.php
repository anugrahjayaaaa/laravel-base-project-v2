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
 * rows exist, never a Blade-made zero. `broadcastUrl` is the C2 notify route,
 * and the broadcast itself is a queued job, not this request.
 */
class MemberDashboardAction
{
    /** @return array<string, mixed> */
    public function run(): array
    {
        // The fallback is the value the seeder writes — same shape as every
        // other SystemSetting reader in the app; the stored setting wins.
        $alertWindowDays = SystemSetting::getInt('member_expiry_alert_window_days', 14);

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
            'broadcastUrl' => route('membership.broadcast-expiry'),
            'alertWindowDays' => $alertWindowDays,
            'stats' => [
                (object) [
                    'label' => 'Active Members',
                    'value' => MemberSubscription::currentlyActive()->distinct()->count('member_id'),
                    'hint' => 'Members with a live subscription right now. Paused and expired periods are not counted.',
                ],
                // The expiring count is not a card: it is the row count of the
                // table under it, and Group F prints one number per question.
                (object) [
                    'label' => 'Points Issued',
                    'value' => (int) MemberPointHistory::where('delta', '>', 0)->sum('delta'),
                    'hint' => 'Every point ever credited to a member. Redeeming points does not lower this number.',
                ],
            ],
            'expiring' => $expiring,
            // One grouped read for every tier, then the list is walked in memory.
            // Counting per tier was one query per row of the price list.
            'membersByTier' => $this->membersByTier(),
        ];
    }

    /**
     * Live periods per tier, keyed by the tier name the operator sees. A tier
     * with nothing live appears with a zero, so the chart never loses a bar.
     *
     * @return array<int, object>
     */
    private function membersByTier(): array
    {
        $counts = MemberSubscription::currentlyActive()
            ->selectRaw('member_tier_id, COUNT(*) as aggregate')
            ->groupBy('member_tier_id')
            ->pluck('aggregate', 'member_tier_id');

        return MemberTier::orderBy('sort_order')
            ->get()
            ->map(fn (MemberTier $tier) => (object) [
                'label' => $tier->name,
                'count' => (int) $counts->get($tier->id, 0),
            ])
            ->all();
    }
}
