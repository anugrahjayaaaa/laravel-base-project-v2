<?php

namespace Database\Seeders;

use App\Actions\V1\Feature\FeatureIndexAction;
use App\Support\FeatureCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * Put a row in the Pennant store for every declared flag.
 *
 * ## Why this seeder exists at all
 *
 * Declaring a flag in `config/pennant.php` does NOT activate it. With the
 * `database` store, `Feature::active()` resolves against a row in `features`
 * and a missing row reads as FALSE — fail-closed. So a flag added to config and
 * wired to a route produces a route that 404s for everyone, superadmin
 * included, until something writes that row. This is that something.
 *
 * ## Why it never overwrites an existing row
 *
 * `activateForEveryone()` over the whole catalogue would also undo an
 * operator's decision on every reseed — and `db:seed` is exactly what someone
 * runs when something already looks wrong. A flag an administrator
 * deliberately turned off has to survive that.
 *
 * So the store is the record of what the operator decided, and this seeder only
 * fills in blanks: activate a slug with no row, deactivate one config marks
 * `disabled => true` (that key is code, and code wins over a stale row), leave
 * every other row alone. That is what makes it idempotent and non-destructive
 * at the same time.
 */
class FeatureFlagSeeder extends Seeder
{
    /**
     * Cache key the management page reads its resolved snapshot from.
     *
     * Referenced from the reader rather than re-spelled. See the note on
     * FeatureToggleAction::SNAPSHOT for why a duplicated literal is not a
     * compile-time guard.
     */
    private const SNAPSHOT = FeatureIndexAction::SNAPSHOT_KEY;

    public function run(): void
    {
        // Ask once which flags already have a stored value, rather than one
        // query per flag inside the loop. It returns a flat list of NAMES.
        $stored = Feature::stored();

        foreach (FeatureCatalog::slugs() as $slug) {
            if (FeatureCatalog::isDisabledInConfig($slug)) {
                // Config is code; a row in the store is data. Code wins.
                Feature::deactivate($slug);

                continue;
            }

            if (! in_array($slug, $stored, true)) {
                // `activate()`, NOT `activateForEveryone()`.
                //
                // The database driver implements "for all scopes" as a plain
                // UPDATE (`setForAllScopes` -> `where(name)->update()`), so on
                // a flag that has no row yet it matches nothing and writes
                // nothing — silently, with no error. Which is every flag this
                // seeder exists to create. `activate()` resolves the scope
                // (`global`, via resolveScopeUsing) and inserts when the row is
                // absent, which is the one that actually creates it.
                Feature::activate($slug);
            }
        }

        // Pennant caches resolved values for the request, and so does the
        // management page's own snapshot. A seeder that leaves either warm
        // makes the page disagree with the database it just wrote.
        Feature::flushCache();
        Cache::forget(self::SNAPSHOT);
    }
}
