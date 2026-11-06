<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The members index view model (Phase 18).
 *
 * Rows arrive pre-decorated: the view takes objects with `show_url`,
 * `status_label`, `tier_variant` and renders them, so Blade holds no
 * arithmetic and no queries (Group A contract). The tab URLs and the bulk
 * endpoint are built here for the same reason — a member Blade file may not
 * call `route()`.
 *
 * shortcut: counts are per request, no cache layer; add `Cache::remember`
 * of primitives when the index gets the traffic UserIndexAction was built for.
 */
class MemberIndexAction
{
    /** Badge variants cycled across tiers in sort order — tier colouring is presentation, not data. */
    private const TIER_VARIANTS = ['warning', 'success', 'primary', 'info', 'danger'];

    /** Columns the sortable headers may actually order by; the rest fall back. */
    private const SORTABLE = ['member_number', 'points_balance'];

    /** The three tabs, in the order the nav renders them. */
    private const TABS = ['active' => 'Active', 'inactive' => 'Inactive', 'trashed' => 'Trash'];

    /**
     * @return array<string, mixed>
     */
    public function run(?string $search, string $sort, string $direction, string $status): array
    {
        $members = $this->query($search, $sort, $direction, $status);

        $variants = $this->tierVariants();

        $members->setCollection($members->getCollection()->map(fn (Member $m) => $this->row($m, $variants)));

        $counts = $this->counts();

        return [
            'indexUrl' => route('members.index'),
            'createUrl' => route('members.create'),
            'bulkUrl' => route('members.bulk-action'),
            'bulkStates' => [
                'active' => ['delete'],
                'expired' => ['delete'],
                'cancelled' => ['delete'],
                'none' => ['delete'],
                'trashed' => ['restore', 'force_delete'],
            ],
            'bulkKeys' => ['delete' => 'delete', 'restore' => 'restore', 'force_delete' => 'force_delete'],
            'tabs' => $this->tabs($status, $search, $counts),
            'members' => $members,
            'summary' => [
                (object) ['label' => 'Total members', 'value' => Member::count()],
                (object) ['label' => 'Active subscriptions', 'value' => MemberSubscription::currentlyActive()->count()],
            ],
            'tierBadges' => $this->tierBadges(),
        ];
    }

    /**
     * The list the tabs switch between, as raw rows.
     *
     * Public because the API twin serializes exactly these rows: a listing that
     * filters differently per channel is the drift `ResponseParityTest` exists
     * to catch, so the query is shared and only the decoration differs.
     *
     * The tabs partition by the profile's own `is_active` flag — the same switch
     * the Activate / Deactivate buttons offer, so a row's tab and its state
     * button can never disagree (which is what made a lapsed-but-not-deactivated
     * profile show the deactivation modal in the Inactive tab). Subscription
     * state stays visible as the badge (ACTIVE / EXPIRED / CANCELLED / NONE),
     * which is presentation, not partitioning.
     *
     * The `withTrashed()` is dropped per branch: `onlyTrashed()` for the trash
     * tab, the default scope for the other two, so a trashed profile never
     * shows up under Inactive.
     *
     * @return LengthAwarePaginator<int, Member>
     */
    public function query(?string $search, string $sort, string $direction, string $status): LengthAwarePaginator
    {
        return Member::with(['user', 'subscriptions.tier'])
            ->when($search, fn ($q) => $q->where(
                fn ($s) => $s->where('member_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
            ))
            ->when(
                $status === 'trashed',
                fn ($q) => $q->onlyTrashed(),
                fn ($q) => $q->where('is_active', $status === 'active')
            )
            ->orderBy(in_array($sort, self::SORTABLE, true) ? $sort : 'member_number', $direction === 'desc' ? 'desc' : 'asc')
            ->paginate(10)
            ->withQueryString();
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, object>
     */
    private function tabs(string $current, ?string $search, array $counts): array
    {
        return collect(self::TABS)
            ->map(fn (string $label, string $key) => (object) [
                'label' => $label,
                'url' => route('members.index', array_filter(['status' => $key, 'search' => $search])),
                'count' => $counts[$key],
                'active' => $current === $key,
            ])
            ->values()
            ->all();
    }

    /**
     * Tab counts, computed once for the whole nav — the same `is_active`
     * partition `query()` uses, so a count and its list always agree.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $active = Member::where('is_active', true)->count();

        return [
            'active' => $active,
            'inactive' => Member::count() - $active,
            'trashed' => Member::onlyTrashed()->count(),
        ];
    }

    /**
     * @param  array<int, string>  $variants  tier id => badge variant
     */
    private function row(Member $member, array $variants): object
    {
        $subscription = $this->current($member);
        $isTrashed = $member->trashed();

        // A deactivated profile is INACTIVE whatever its subscription did: the
        // period is either paused (kept for the return) or forfeited, and neither
        // is a live membership. Checked first, so a paused row cannot show ACTIVE.
        [$label, $variant] = match (true) {
            ! $member->is_active => ['INACTIVE', 'neutral'],
            $subscription === null => ['NONE', 'neutral'],
            $subscription->status === MemberSubscription::STATUS_CANCELLED => ['CANCELLED', 'neutral'],
            $subscription->isLive() => ['ACTIVE', 'success'],
            default => ['EXPIRED', 'danger'],
        };
        // A trashed row keeps its last subscription state for the bulk picker —
        // the DELETED badge in the view is what marks it, and `trashed` overrides.
        $state = $isTrashed ? 'trashed' : match (true) {
            $subscription === null => 'none',
            $subscription->status === MemberSubscription::STATUS_CANCELLED => 'cancelled',
            $subscription->isLive() => 'active',
            default => 'expired',
        };

        return (object) [
            'id' => $member->id,
            'member_number' => $member->member_number,
            'user_name' => $member->user?->name,
            'tier_name' => $subscription?->tier?->name,
            'tier_variant' => $variants[$subscription?->member_tier_id] ?? 'neutral',
            'status_label' => $label,
            'status_variant' => $variant,
            'bulk_state' => $state,
            'points_balance' => $member->points_balance,
            'expires_at' => $subscription?->expires_at?->format('Y-m-d'),
            'show_url' => route('members.show', $member),
            // A trashed row has nothing to trash and a live row nothing to
            // restore or destroy in one click: null means "no button", which is
            // what stops the trash tab offering Move to Trash and the Active tab
            // offering Permanent Delete.
            'delete_url' => $isTrashed ? null : route('members.destroy', $member),
            // Exactly one of the pair is a button — a profile is either in the
            // programme or out of it — and the trash is neither: its way back is
            // Restore, not Activate.
            'activate_url' => ($isTrashed || $member->is_active) ? null : route('members.activate', $member->id),
            'deactivate_url' => ($isTrashed || ! $member->is_active) ? null : route('members.deactivate', $member->id),
            'restore_url' => $isTrashed ? route('members.restore', $member->id) : null,
            'force_delete_url' => $isTrashed ? route('members.force-delete', $member->id) : null,
            'is_trashed' => $isTrashed,
        ];
    }

    /**
     * The subscription that describes the member today: a live one, else the
     * most recent (the `subscriptions` relation orders by `starts_at` desc).
     */
    private function current(Member $member): ?MemberSubscription
    {
        return $member->subscriptions->first(fn (MemberSubscription $s) => $s->isLive())
            ?? $member->subscriptions->first();
    }

    /** @return array<int, string> tier id => badge variant */
    private function tierVariants(): array
    {
        return MemberTier::orderBy('sort_order')->pluck('id')
            ->mapWithKeys(fn (int $id, int $i) => [$id => self::TIER_VARIANTS[$i % count(self::TIER_VARIANTS)]])
            ->all();
    }

    /** @return array<int, object> */
    private function tierBadges(): array
    {
        $counts = MemberSubscription::currentlyActive()
            ->selectRaw('member_tier_id, COUNT(*) as aggregate')
            ->groupBy('member_tier_id')
            ->pluck('aggregate', 'member_tier_id');

        $variants = $this->tierVariants();

        return MemberTier::orderBy('sort_order')
            ->get()
            ->map(fn (MemberTier $tier) => (object) [
                'name' => $tier->name,
                'badge_variant' => $variants[$tier->id],
                'count' => (int) $counts->get($tier->id, 0),
            ])
            ->all();
    }
}
