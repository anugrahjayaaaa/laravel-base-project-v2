<?php

namespace App\Actions\V1\Feature;

use App\Actions\V1\Notification\NotificationAdminEventAction;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Support\FeatureCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Switch one feature flag on or off, and record who did it.
 *
 * The flag's own row in the `features` table is the audit subject, so the row
 * names the thing that changed — see FeatureFlag for why the state needed a
 * model at all. The event properties carry what actually happened: the state it
 * was and the state it became. Without `from` the row would record that
 * something changed and not what it changed from, which is the half an auditor
 * actually needs.
 */
class FeatureToggleAction
{
    public function __construct(
        private readonly NotificationAdminEventAction $notifyAction,
    ) {
    }

    /**
     * The audit event name. Stable string — the audit viewer filters on it.
     */
    public const EVENT = 'feature.toggled';

    /**
     * Cache key the index action reads its resolved snapshot from.
     *
     * Referenced from the reader rather than re-spelled here. A local copy of
     * the string was the earlier design, defended as "a rename has to break a
     * compile" — which is false: two private constants holding the same literal
     * are two unrelated strings, and renaming the reader left this flushing a
     * key nothing read. The coupling is now real.
     */
    private const SNAPSHOT = FeatureIndexAction::SNAPSHOT_KEY;

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

        // The stored row, for the audit subject. Null before the write for a
        // flag nobody has ever read — Pennant inserts it lazily, and the toggle
        // below is what brings it into existence.
        $stored = FeatureFlag::forName($slug);

        // Read the OLD value first. Read after the write it is always the new
        // one, and the audit row's `from` becomes a lie that looks correct.
        //
        // `isActive()`, not `Feature::active()`: `from` is what the flag was
        // doing for a user, which is the effective state including the config
        // kill switch. The store's own value would say `true` for a flag that
        // was being served as off.
        $from = FeatureCatalog::isActive($slug);

        DB::transaction(function () use ($slug, $enabled, $causer, $from, $stored): void {
            $enabled
                ? Feature::activate($slug)
                : Feature::deactivate($slug);

            // Inside the transaction (DEP-003): an audit row that survives a
            // rollback records a change that never happened.
            //
            // Re-read rather than using the instance from before the write: it
            // was loaded when the flag had no row, or held the old value, so its
            // identity is stale either way. The write above created or updated
            // the row, so it exists now.
            $subject = $stored?->refresh() ?? FeatureFlag::forName($slug);

            // The flag's own row is the subject, so the audit can name what
            // changed. The earlier hand-rolled `activity()` call wrote a row
            // with a causer and properties but no subject, which the viewer
            // renders as "#" and nobody can inspect afterwards.
            $subject?->audit(self::EVENT, $causer ?? auth()->user(), [
                'feature' => $slug,
                'from' => $from,
                'to' => $enabled,
            ]);
        });

        // Pennant's own resolved values, then our snapshot of them. Missing
        // either leaves the page showing the state the operator just replaced.
        Feature::flushCache();
        Cache::forget(self::SNAPSHOT);


        // Every `features.manage` holder, after the commit. A flag flipped and
        // nobody told is two operators disagreeing about whether it is on.
        $this->notifyAction->configurationChanged('feature.changed', $slug, $causer);

        return [
            'slug' => $slug,
            'label' => $flag['label'],
            'from' => $from,
            'to' => $enabled,
        ];
    }
}
