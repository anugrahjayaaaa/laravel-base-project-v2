<?php

namespace App\Actions\V1\Feature;

use App\Models\User;
use App\Support\FeatureCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Switch several feature flags in one request, and record it as one event.
 *
 * ## Why this does not loop `FeatureToggleAction`
 *
 * Calling it per slug would be less code and wrong twice over: it writes one
 * `feature.toggled` row per flag, so a five-flag change leaves five audit rows
 * that no reader can tell belonged together, and it flushes Pennant's cache
 * and the index snapshot five times — five round trips to the store for one
 * user action.
 *
 * So the writes are batched here: one transaction, one
 * `feature.bulk_toggled` row carrying the full slug list and each slug's own
 * from/to. The single-flag path keeps its own event name — a one-flag bulk
 * request and a toggle from the row switch are different things to an auditor.
 *
 * ## Why every `from` is read before the first write
 *
 * `from` is what the flag was doing for a user — the effective state including
 * the `disabled => true` config override, so `FeatureCatalog::isActive()` and
 * not the store's own value. Read after a write it is always the new one, and
 * the audit row records a change from a state that never existed, which is
 * worse than recording nothing.
 *
 * The validation loop runs first for the same reason: a slug outside the
 * catalogue must abort before any flag is touched, so a crafted POST naming
 * one real slug and one invented one changes neither.
 */
class FeatureBulkToggleAction
{
    /**
     * The audit event name. Distinct from FeatureToggleAction::EVENT so the
     * audit viewer can filter a batch apart from a row switch.
     */
    public const EVENT = 'feature.bulk_toggled';

    /**
     * Cache key the index action reads its resolved snapshot from.
     *
     * Referenced from the reader rather than re-spelled. See the note on
     * FeatureToggleAction::SNAPSHOT for why a duplicated literal is not a
     * compile-time guard.
     */
    private const SNAPSHOT = FeatureIndexAction::SNAPSHOT_KEY;

    /**
     * Set every named flag to the same state, as one audited change.
     *
     * @param  array<int, string>  $slugs  Declared flags. Anything else aborts.
     * @param  bool  $enabled  The state being asked for.
     * @param  User|null  $causer  Who to attribute the audit record to
     * @return array{enabled: bool, changed: array<int, array{slug: string, label: string, from: bool, to: bool}>, unchanged: array<int, string>}
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException When any slug is not declared
     */
    public function run(array $slugs, bool $enabled, ?User $causer = null): array
    {
        // Pennant stores any string, so an undeclared slug would become a row
        // that exists in the database and in no gate. Validate the WHOLE batch
        // before touching any flag, so one invented slug cannot ride along with
        // real ones.
        //
        // De-duplicated HERE, not only in BulkFeatureRequest: a queued job or a
        // console command never runs the form request, and a repeated slug would
        // be written once per repeat while the audit row claimed that many
        // changes. `$before` is keyed by slug so the recorded state stays
        // correct, but `changed` would still over-count.
        $slugs = array_values(array_unique($slugs));

        foreach ($slugs as $slug) {
            abort_unless(FeatureCatalog::has($slug), 404);
        }

        // Every `from` first — see the class docblock.
        $before = [];

        foreach ($slugs as $slug) {
            $before[$slug] = FeatureCatalog::isActive($slug);
        }

        $changed = [];
        $unchanged = [];

        DB::transaction(function () use ($slugs, $enabled, $causer, $before, &$changed, &$unchanged): void {
            foreach ($slugs as $slug) {
                // Compare against the STORE, not the effective state.
                //
                // `$before` is `FeatureCatalog::isActive()`, which honours the
                // `disabled => true` kill switch, so a kill-switched flag reads
                // false whatever its row says. Short-circuiting on that made
                // bulk-disable a kill-switched flag a silent no-op: the row was
                // left as the seeder wrote it, and lifting the kill switch
                // brought the module straight back. FeatureToggleAction has no
                // short-circuit at all and always writes, so the two paths
                // disagreed about the same flag.
                //
                // What we write is the row, so the row is what decides whether
                // there is anything to do.
                if (Feature::active($slug) === $enabled) {
                    $unchanged[] = $slug;

                    continue;
                }

                $enabled
                    ? Feature::activate($slug)
                    : Feature::deactivate($slug);

                $changed[] = [
                    'slug' => $slug,
                    'label' => FeatureCatalog::find($slug)['label'],
                    'from' => $before[$slug],
                    'to' => $enabled,
                ];
            }

            // Inside the transaction (DEP-003): an audit row that survives a
            // rollback records a change that never happened.
            activity()
                ->event(self::EVENT)
                ->causedBy($causer ?? auth()->user())
                ->withProperties([
                    'source' => request()->is('api/*') ? 'api' : 'web',
                    'to' => $enabled,
                    'count' => count($changed),
                    // The full request, not just the rows that moved: "you asked
                    // for 5, 2 were already there" is part of what happened.
                    'requested' => array_values($slugs),
                    'changed' => array_map(fn (array $c): array => [
                        'feature' => $c['slug'],
                        'from' => $c['from'],
                        'to' => $c['to'],
                    ], $changed),
                    'unchanged' => $unchanged,
                ])
                ->log(self::EVENT);
        });

        // Once, after the batch — see "Why this does not loop".
        Feature::flushCache();
        Cache::forget(self::SNAPSHOT);

        return [
            'enabled' => $enabled,
            'changed' => $changed,
            'unchanged' => $unchanged,
        ];
    }
}
