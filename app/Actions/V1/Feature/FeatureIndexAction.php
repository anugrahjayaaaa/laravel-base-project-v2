<?php

namespace App\Actions\V1\Feature;

use App\Models\User;
use App\Support\FeatureCatalog;
use Illuminate\Support\Facades\Cache;

/**
 * Read the flag catalogue, resolved, for the management page.
 *
 * Owns the resolution so the controller stays thin and the page gets plain
 * arrays — `ui-architecture.md` rule 1 is that a view reads variables and
 * nothing else. It also owns the counters, which are the same numbers the
 * action would otherwise recompute per row.
 *
 * ## Why one store read for the whole catalogue, not one per flag
 *
 * Asking `isActive()` per flag is N selects — measured at 8 queries for 8
 * flags, growing with the catalogue. `FeatureCatalog::activeMap()` reads them
 * all in one `WHERE name IN (...)`, so the count stops depending on how many
 * flags exist. That resolved map is then cached for a short window, keeping a
 * page view from being the thing that pays for the resolution at all — the
 * numbers only move when someone toggles, which is the only thing that flushes
 * this key.
 *
 * ponytail: the TTL is a staleness budget, not a tuning knob — the toggle
 * action calls forget() in the same breath as the write, so an operator never
 * waits it out. Revisit if a flag starts being changed somewhere else.
 */
class FeatureIndexAction
{
    /**
     * How long a resolved snapshot stays readable.
     */
    private const TTL = 30;

    /**
     * The flags grouped by module, with their current state and toggle URLs.
     *
     * `manageable` is resolved here rather than asked in the view. The view
     * reads variables and nothing else — the same rule that puts `toggle_url`
     * in this class — and this was the one binding still calling
     * `auth()->user()?->can()` inline, the only such call in the view layer.
     *
     * The viewer is a parameter, not a `request()` lookup inside the action:
     * every sibling action takes its actors explicitly, and an action that
     * reaches for the container cannot be told which user it is acting for.
     *
     * It stays OUT of the cached snapshot on purpose: the answer depends on the
     * viewer, and a cached one would show the first caller's rights to everyone.
     *
     * @return array{
     *     featureGroups: array<string, array<int, array<string, mixed>>>,
     *     totalFeatures: int,
     *     enabledCount: int,
     *     disabledCount: int,
     *     manageable: bool
     * }
     */
    public function run(?User $viewer = null): array
    {
        $snapshot = Cache::remember(
            'feature_flags.resolved',
            self::TTL,
            fn (): array => $this->resolve(),
        );

        // Built outside the cache: the toggle URL differs per flag and would
        // bake one request's route state into a snapshot every other request
        // reads. Cheap, and it cannot go stale.
        $groups = [];

        foreach ($snapshot['groups'] as $group => $flags) {
            $groups[$group] = array_map(
                fn (array $flag): array => [
                    ...$flag,
                    'toggle_url' => route('features.toggle', $flag['slug'])
                        .'?enabled='.($flag['enabled'] ? '0' : '1'),
                ],
                $flags
            );
        }

        return [
            'featureGroups' => $groups,
            'totalFeatures' => $snapshot['total'],
            'enabledCount' => $snapshot['enabled'],
            'disabledCount' => $snapshot['disabled'],
            'manageable' => $viewer?->can('features.manage') ?? false,
        ];
    }

    /**
     * Resolve every declared flag once.
     *
     * The answer comes from `FeatureCatalog::activeMap()`, not from
     * `isActive()` per slug: that is N store reads, and re-deriving the kill
     * switch here is how the page and the middleware would drift apart.
     *
     * @return array{groups: array<string, array<int, array<string, mixed>>>, total: int, enabled: int, disabled: int}
     */
    private function resolve(): array
    {
        $grouped = FeatureCatalog::grouped();
        $states = FeatureCatalog::activeMap(FeatureCatalog::slugs());

        $groups = [];
        $enabled = 0;

        foreach ($grouped as $group => $flags) {
            $rows = [];

            foreach ($flags as $flag) {
                $isEnabled = $states[$flag['slug']] ?? false;

                $enabled += (int) $isEnabled;

                $rows[] = [...$flag, 'enabled' => $isEnabled];
            }

            $groups[$group] = $rows;
        }

        $total = array_sum(array_map('count', $groups));

        return [
            'groups' => $groups,
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $total - $enabled,
        ];
    }
}
