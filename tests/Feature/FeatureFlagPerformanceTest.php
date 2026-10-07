<?php

namespace Tests\Feature;

use App\Actions\V1\Feature\FeatureBulkToggleAction;
use App\Actions\V1\Feature\FeatureIndexAction;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group E: what the feature-flag system costs, and that a toggle is visible.
 *
 * ## Why these assertions are DELTAS, not ceilings
 *
 * "Under 20 queries" would pass for an N+1 that happens to fit under a number
 * someone picked, and would keep passing as the catalogue grows. Measuring the
 * same operation at two catalogue sizes is the only shape that catches it: an
 * N+1 grows with N, a single read does not.
 *
 * Two real N+1s were found this way, both invisible to a ceiling:
 *
 *  - `resolve()` called `isActive()` per slug: 2 / 4 / 8 queries for 2 / 4 / 8
 *    flags. Fixed by `FeatureCatalog::activeMap()` — one `WHERE name IN (...)`.
 *  - the sidebar composer called `isActive()` per menu item on every page, and
 *    read the Sessions flag a second time for the header dropdown. Fixed the
 *    same way.
 *  - the bulk action looped `Feature::activate()` per slug when Pennant takes
 *    the whole array in one upsert.
 */
class FeatureFlagPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A seeded admin, signed in, with CSRF off for the POST paths.
     *
     * The bulk action takes its causer as an argument and never calls auth(), so
     * the performance half does not strictly need a session — but the round-trip
     * test does, and one helper beats two half-setup arrangements.
     */
    private function login(): User
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));
        $this->actingAs($user);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * Cold-cache queries for the index resolution over a catalogue of $count
     * flags. Both caches are dropped first: the 30s snapshot and Pennant's
     * in-memory feature state, or the second measurement measures the first.
     */
    private function queriesForIndex(int $count): int
    {
        $full = config('pennant.features');
        config(['pennant.features' => array_slice($full, 0, $count, true)]);

        Cache::forget('feature_flags.resolved');
        Feature::flushCache();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(FeatureIndexAction::class)->run();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        config(['pennant.features' => $full]);
        Cache::forget('feature_flags.resolved');
        Feature::flushCache();

        return $queries;
    }

        public function test_the_index_cost_does_not_grow_with_the_catalogue (): void
    {
        $small = $this->queriesForIndex(2);
        $large = $this->queriesForIndex(count(FeatureCatalog::slugs()));

        $this->assertGreaterThan(
            $small,
            count(FeatureCatalog::slugs()),
            'precondition: the larger catalogue really is larger, so the delta means something'
        );

        $this->assertSame(
            $small,
            $large,
            "the index read {$large} store rows for {$small} at a small catalogue — one read per flag, not one for the page"
        );
    }

    /**
     * The menu composer runs on EVERY authenticated page, and so does the header
     * partial's own one-flag closure.
     *
     * The bug this was written for was real, not hypothetical: `visible()` called
     * `isActive()` per slug, which measured 2 queries for 2 flags, 4 for 4 and 8
     * for 8. `FeatureCatalog::activeMap()` reads them in one
     * `WHERE name IN (...)`.
     *
     * ## Why the cache is FLUSHED, not warmed
     *
     * An earlier version warmed Pennant and then measured. That made the guard
     * vacuous: with the header partial registering the full composer a second
     * time, the test still passed. Pennant's decorator caches for the life of
     * the process, so the sidebar's `activeMap()` had already paid for every
     * flag and the second consumer's identical reads were served from memory.
     * A guard watching a warm cache reports green on cold work.
     *
     * ## Why the ceiling is 2 and not 1
     *
     * `layouts/app.blade.php` includes the header (line 34) BEFORE the sidebar
     * (line 35), so the order decides the count:
     *
     *   header first   → its one-flag read is a cold read, then the sidebar's
     *                    `activeMap()` reads the catalogue = 2 reads
     *   sidebar first  → the catalogue is resolved, and the header's flag is
     *                    already in Pennant's cache = 1 read
     *
     * Measured 2, 2, 2 on three consecutive flushed runs. The ceiling is set to
     * the number that actually ships, not the one that would look best: a
     * ceiling below reality is a test that fails for a reason nobody can act on.
     */
        public function test_the_menu_resolves_the_catalogue_once_not_once_per_flag (): void
    {
        $this->login();

        $reads = (function (): int {
            // Flushed, NOT warmed. See the docblock: warming is what let a
            // doubled composer pass this test.
            Feature::flushCache();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('dashboard'))->assertOk();
            $log = collect(DB::getQueryLog());
            DB::disableQueryLog();

            return $log->filter(fn (array $q): bool => str_contains($q['query'], 'features'))->count();
        })();

        // 2 = the header partial's single-flag read, then the sidebar's one
        // `WHERE name IN (...)` for the whole catalogue. The pre-fix page cost 5
        // here, so this ceiling sits BELOW the regression it guards rather than
        // above it.
        $this->assertLessThanOrEqual(
            2,
            $reads,
            "a cold page view read the feature store {$reads} times; the menu should resolve the "
            .'catalogue once and the header partial once. A count that grows with the number of '
            .'flags means something is calling isActive() per flag again.'
        );
    }

        public function test_the_second_page_view_reads_nothing (): void
    {
        $this->login();

        // Warm the snapshot, or this proves nothing.
        app(FeatureIndexAction::class)->run();
        $this->assertTrue(Cache::has('feature_flags.resolved'), 'precondition: the snapshot is warm');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(FeatureIndexAction::class)->run();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'a warm page view still went to the store');
    }

    /**
     * A bulk toggle writes the whole selection in ONE statement.
     *
     * `Feature::activate()`/`deactivate()` take an array and upsert it, but the
     * action looped over slugs and called them per slug — 23 queries to change 8
     * flags, 60 to change 25, growing with the catalogue. Pennant's API was
     * already batch-capable; nothing was asking it to be.
     *
     * Counted as a flat total across two catalogue sizes rather than a ceiling,
     * because a ceiling like "under 30" passes for an N+1 that happens to fit.
     */
        public function test_a_bulk_toggle_does_not_cost_more_queries_for_a_bigger_selection (): void
    {
        $user = $this->login();

        $counts = [];

        foreach ([2, count(FeatureCatalog::slugs())] as $n) {
            $full = config('pennant.features');
            config(['pennant.features' => array_slice($full, 0, $n, true)]);

            Feature::flushCache();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(FeatureBulkToggleAction::class)->run(FeatureCatalog::slugs(), false, $user);
            $counts[$n] = count(DB::getQueryLog());
            DB::disableQueryLog();

            config(['pennant.features' => $full]);
            Feature::flushCache();
        }

        $this->assertSame(
            $counts[2],
            $counts[count(FeatureCatalog::slugs())],
            'a bigger selection costs more store writes: one per flag, not one per batch'
        );
    }

        public function test_a_bulk_toggle_still_writes_every_selected_flag (): void
    {
        $user = $this->login();
        $slugs = FeatureCatalog::slugs();

        $result = app(FeatureBulkToggleAction::class)->run($slugs, false, $user);

        $this->assertCount(count($slugs), $result['changed'], 'not every flag was reported as changed');

        Feature::flushCache();

        foreach ($slugs as $slug) {
            $this->assertFalse(Feature::active($slug), "[{$slug}] was not actually written by the batch upsert");
        }
    }

    /**
     * Group E2: a toggle has to be visible to the next reader.
     *
     * The other half of the round trip — the store row, the audit `from`/`to`
     * and the cache flush — is covered in FeatureFlagRouteTest. What is asserted
     * here is the part that only a second resolution can prove: the page
     * reflects the new state. Without it, a writer that forgot to flush would
     * still pass every other assertion in the file.
     */
        public function test_a_toggled_flag_is_reflected_by_the_next_page_view (): void
    {
        $this->login();

        $before = app(FeatureIndexAction::class)->run();
        $this->assertTrue($before['featureGroups']['Users'][0]['enabled'], 'precondition: the flag starts on');

        $this->post(route('features.toggle', 'users'), ['enabled' => 0])->assertRedirect();

        $after = app(FeatureIndexAction::class)->run();

        $users = collect($after['featureGroups'])->flatMap(fn ($flags) => $flags)
            ->firstWhere('slug', 'users');

        $this->assertFalse($users['enabled'], 'the page still shows the old state after a toggle');
        $this->assertSame(
            $before['enabledCount'] - 1,
            $after['enabledCount'],
            'the enabled counter did not follow the flag'
        );
    }
}
