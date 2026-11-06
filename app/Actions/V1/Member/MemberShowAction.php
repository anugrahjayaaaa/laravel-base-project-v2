<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\MemberSubscription;
use App\Models\SystemSetting;
use App\Support\Money;

/**
 * The member detail view model (Phase 18, read-only slice).
 *
 * The ledger and the subscription history arrive as plain objects — the view
 * renders what it is handed and computes nothing. `adjustUrl` and `delete_url`
 * are live; `subscribeUrl` and `cancel_url` stay null until B6 ships their
 * routes.
 */
class MemberShowAction
{
    /** @return array<string, mixed> */
    public function run(Member $member): array
    {
        $member->load(['user', 'subscriptions.tier', 'pointHistories']);

        return [
            'indexUrl' => route('members.index'),
            'adjustUrl' => route('members.points', $member),
            'earnRule' => Money::format(
                SystemSetting::getInt('member_points_earn_amount', 50000)
            ).' earns '.number_format(
                SystemSetting::getInt('member_points_earn_value', 1000)
            ).' points',
            'subscribeUrl' => null,
            'tierOptions' => [],
            'member' => $this->row($member),
            'subscriptions' => $member->subscriptions
                ->map(fn (MemberSubscription $s) => (object) [
                    'tier_name' => $s->tier?->name,
                    'status_label' => strtoupper($s->status),
                    'status_variant' => match ($s->status) {
                        MemberSubscription::STATUS_ACTIVE => $s->isLive() ? 'success' : 'danger',
                        MemberSubscription::STATUS_EXPIRED => 'danger',
                        default => 'neutral',
                    },
                    'role_name' => $s->role_name,
                    'starts_at' => $s->starts_at->format('Y-m-d'),
                    'expires_at' => $s->expires_at->format('Y-m-d'),
                    'cancel_url' => null,
                ])
                ->all(),
            'points' => $member->pointHistories
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

    private function row(Member $member): object
    {
        $current = $member->subscriptions->first(fn (MemberSubscription $s) => $s->isLive())
            ?? $member->subscriptions->first();

        [$label, $variant] = match (true) {
            // Same reading as the list badge, so the two never disagree about
            // whether this profile is in the programme.
            ! $member->is_active => ['INACTIVE', 'neutral'],
            $current === null => ['NONE', 'neutral'],
            $current->status === MemberSubscription::STATUS_CANCELLED => ['CANCELLED', 'neutral'],
            $current->isLive() => ['ACTIVE', 'success'],
            default => ['EXPIRED', 'danger'],
        };

        return (object) [
            'id' => $member->id,
            'member_number' => $member->member_number,
            'user_name' => $member->user?->name,
            'user_email' => $member->user?->email,
            'tier_name' => $current?->tier?->name,
            'tier_variant' => 'warning',
            'status_label' => $label,
            'status_variant' => $variant,
            'bulk_state' => $member->trashed() ? 'trashed' : ($current === null ? 'none' : $current->status),
            'points_balance' => $member->points_balance,
            'expires_at' => $current?->expires_at?->format('Y-m-d'),
            'joined_at' => $member->joined_at->format('Y-m-d'),
            'notes' => $member->notes,
            'show_url' => route('members.show', $member),
            // null for a profile already in the trash: the button would repeat a
            // delete that already happened.
            'delete_url' => $member->trashed() ? null : route('members.destroy', $member),
            // The account behind the profile. `route('users.show', null)` would
            // throw, so a member with no user row (nothing creates one today)
            // simply gets no link.
            'user_url' => $member->user ? route('users.show', $member->user) : null,
            'is_trashed' => $member->trashed(),
        ];
    }
}
