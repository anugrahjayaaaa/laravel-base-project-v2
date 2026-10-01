<?php

namespace App\Actions\V1\Feature;

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
 * ## Why one store read per flag, not one per render
 *
 * `Feature::active()` caches per request, but the page asks about every flag,
 * so N flags is N resolutions. With the database driver that is N selects on
 * the first request of a process and zero on the next. Caching the resolved
 * snapshot for a short window keeps a page view from being the thing that
 * pays for the resolution — the numbers only move when someone toggles, which
 * is the only thing that flushes this key.
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
     * @return array{
     *     featureGroups: array<string, array<int, array<string, mixed>>>,
     *     totalFeatures: int,
     *     enabledCount: int,
     *     disabledCount: int
     * }
     */
    public function run(): array
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
        ];
    }

    /**
     * Resolve every declared flag once.
     *
     * The answer comes from `FeatureCatalog::isActive()`, not from
     * `Feature::active()` directly: the kill switch lives there, and re-deriving
     * it here is how the page and the middleware would drift apart.
     *
     * @return array{groups: array<string, array<int, array<string, mixed>>>, total: int, enabled: int, disabled: int}
     */
    private function resolve(): array
    {
        $groups = [];
        $enabled = 0;

        foreach (FeatureCatalog::grouped() as $group => $flags) {
            $rows = [];

            foreach ($flags as $flag) {
                $isEnabled = FeatureCatalog::isActive($flag['slug']);

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
