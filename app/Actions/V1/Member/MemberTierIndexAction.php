<?php

namespace App\Actions\V1\Member;

use App\Models\MemberTier;
use App\Support\Money;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The tier list view model (Phase 18, P18-B6).
 *
 * Read-only rows: the create/edit/delete screens own the writes, so the picker
 * options a form needs are built for those pages and not here.
 *
 * The tabs own the trashed rows. Before them this query read the default scope,
 * which is why a tier moved to the trash disappeared with no way back —
 * `onlyTrashed()` on the Trash tab is what makes both the row and its Restore /
 * Permanent Delete buttons reachable.
 *
 * The list carries the same search box, bulk-bar placement and 10-per-page
 * pagination as the members and users lists — the operator moves between the
 * three screens without relearning the layout.
 */
class MemberTierIndexAction
{
    /** The three tabs, in the order the nav renders them. */
    private const TABS = ['active' => 'Active', 'inactive' => 'Inactive', 'trashed' => 'Trash'];

    /** @return array<string, mixed> */
    public function run(?string $search = null, string $status = 'active'): array
    {
        $counts = $this->counts();
        $tiers = $this->query($search, $status);

        // The decoration stays on this side of the split: the API twin reads the
        // same rows and serializes them itself, so `route()` lives only in the
        // screen-building path.
        $tiers->setCollection($tiers->getCollection()->map(fn (MemberTier $tier) => $this->row($tier)));

        return [
            'indexUrl' => route('members.index'),
            'tiersUrl' => route('members.tiers'),
            'createUrl' => route('members.tiers.create'),
            'bulkUrl' => route('members.tiers.bulk-action'),
            'bulkStates' => [
                'active' => ['delete'],
                'inactive' => ['delete'],
                'trashed' => ['restore', 'force_delete'],
            ],
            'bulkKeys' => ['delete' => 'delete', 'restore' => 'restore', 'force_delete' => 'force_delete'],
            'tabs' => $this->tabs($status, $search, $counts),
            'filters' => ['search' => $search, 'status' => $status],
            'status' => $status,
            'counts' => $counts,
            'tiers' => $tiers,
        ];
    }

    /**
     * The rows behind the tabs, undecorated — the API twin serializes these
     * same rows, so the filter lives once.
     *
     * @return LengthAwarePaginator<int, MemberTier>
     */
    public function query(?string $search, string $status): LengthAwarePaginator
    {
        // `%` and `_` are LIKE operators, not text: unescaped, a search for `%`
        // matched every tier and `_` every name of that length. `!` is the escape
        // character because both engines treat it as an ordinary character in the
        // pattern itself (MySQL's default `\` is a literal in SQLite).
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $search).'%';

        return MemberTier::query()
            ->when($search, fn ($q) => $q->where(
                fn ($s) => $s->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$like])
            ))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($status === 'trashed', fn ($q) => $q->onlyTrashed())
            ->orderBy('sort_order')
            ->paginate(10)
            ->withQueryString();
    }

    private function row(MemberTier $tier): object
    {
        $isTrashed = $tier->trashed();

        return (object) [
            'id' => $tier->id,
            'name' => $tier->name,
            'slug' => $tier->slug,
            // The amount as a reader writes it — symbol and separators
            // come from the configured currency, never from this row.
            'price_display' => Money::format($tier->price),
            'duration_days' => $tier->duration_days,
            'point_multiplier' => $tier->point_multiplier,
            'role_name' => $tier->role_name,
            'is_active' => $tier->is_active,
            'is_trashed' => $isTrashed,
            // A trashed tier has nothing to trash, a live tier nothing to
            // restore: null means "no button" in the view.
            'edit_url' => $isTrashed ? null : route('members.tiers.edit', $tier),
            // The state pair mirrors the `is_active` switch on the form: a row is
            // either on the Active tab or the Inactive one, so exactly one of
            // these is a button and the other is null.
            'activate_url' => ($isTrashed || $tier->is_active) ? null : route('members.tiers.activate', $tier),
            'deactivate_url' => ($isTrashed || ! $tier->is_active) ? null : route('members.tiers.deactivate', $tier),
            'delete_url' => $isTrashed ? null : route('members.tiers.destroy', $tier),
            'restore_url' => $isTrashed ? route('members.tiers.restore', $tier->id) : null,
            'force_delete_url' => $isTrashed ? route('members.tiers.force-delete', $tier->id) : null,
        ];
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
                'url' => route('members.tiers', array_filter(['status' => $key, 'search' => $search])),
                'count' => $counts[$key],
                'active' => $current === $key,
            ])
            ->values()
            ->all();
    }

    /**
     * Tab counts. `trashed` is its own tab, so the two live tabs count rows that
     * are not in the trash — otherwise a tier switched off and then trashed
     * would be counted twice.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'active' => MemberTier::where('is_active', true)->count(),
            'inactive' => MemberTier::where('is_active', false)->count(),
            'trashed' => MemberTier::onlyTrashed()->count(),
        ];
    }
}
