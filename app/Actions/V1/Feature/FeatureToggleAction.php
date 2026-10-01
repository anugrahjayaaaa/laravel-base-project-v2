<?php

namespace App\Actions\V1\Feature;

use App\Models\User;
use App\Support\FeatureCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Switch one feature flag on or off, and record who did it.
 *
 * ## Why the audit row has no subject
 *
 * Spatie's audit rows hang off a model via `->on($model)`. A Pennant flag has
 * no model — it is a row in a store the package owns — so this writes an event
 * with a causer and properties but no subject. The alternative, inventing a
 * throwaway model to hang it on, would put a model in the database whose only
 * job is to be an audit target.
 *
 * `properties` carries what the event is actually about: the slug, the state it
 * was, and the state it became. Without `from` the row would record that
 * something changed and not what it changed from, which is the half an auditor
 * actually needs.
 */
class FeatureToggleAction
{
    /**
     * The audit event name. Stable string — the audit viewer filters on it.
     */
    public const EVENT = 'feature.toggled';

    /**
     * Cache key the index action reads its resolved snapshot from.
     *
     * Duplicated as a constant rather than imported from the reader so the
     * reader cannot rename its own key and silently leave every writer flushing
     * a key nothing reads. Both sides name it; a rename has to break a
     * compile.
     */
    private const SNAPSHOT = 'feature_flags.resolved';

    /**
     * Set a flag's state.
     *
     * @param  string  $slug    A declared flag. Anything else is refused.
     * @param  bool    $enabled The state being asked for.
     * @param  User|null $causer Who to attribute the audit record to
     * @return array{slug: string, label: string, from: bool, to: bool}
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException When the slug is not declared
     */
    public function run(string $slug, bool $enabled, ?User $causer = null): array
    {
        // Pennant will store any string, so without this a crafted POST writes
        // a flag that exists in the database and in no gate — worse than not
        // existing, because it looks real.
        //
        // 404, not an exception: the slug names a resource that is not there,
        // and the same `abort_unless(..., 404)` the registration gate uses
        // (AuthController) keeps a crafted POST from surfacing as a 500.
        abort_unless(FeatureCatalog::has($slug), 404);

        $flag = FeatureCatalog::find($slug);

        // Read the OLD value first. Read after the write it is always the new
        // one, and the audit row's `from` becomes a lie that looks correct.
        //
        // `isActive()`, not `Feature::active()`: `from` is what the flag was
        // doing for a user, which is the effective state including the config
        // kill switch. The store's own value would say `true` for a flag that
        // was being served as off.
        $from = FeatureCatalog::isActive($slug);

        DB::transaction(function () use ($slug, $enabled, $causer, $from): void {
            $enabled
                ? Feature::activate($slug)
                : Feature::deactivate($slug);

            // Inside the transaction (DEP-003): an audit row that survives a
            // rollback records a change that never happened.
            activity()
                ->event(self::EVENT)
                ->causedBy($causer ?? auth()->user())
                ->withProperties([
                    'source' => request()->is('api/*') ? 'api' : 'web',
                    'feature' => $slug,
                    'from' => $from,
                    'to' => $enabled,
                ])
                ->log(self::EVENT);
        });

        // Pennant's own resolved values, then our snapshot of them. Missing
        // either leaves the page showing the state the operator just replaced.
        Feature::flushCache();
        Cache::forget(self::SNAPSHOT);

        return [
            'slug' => $slug,
            'label' => $flag['label'],
            'from' => $from,
            'to' => $enabled,
        ];
    }
}
