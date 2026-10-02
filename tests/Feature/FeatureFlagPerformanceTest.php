<?php

namespace Tests\Feature;

use App\Actions\V1\Feature\FeatureIndexAction;
use App\Support\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group E: the management page's store cost, and that a toggle is visible.
 *
 * ## Why the perf assertion is a DELTA between two flag counts
 *
 * "Under 20 queries" would pass for an N+1 that happens to fit under a number
 * someone picked, and would keep passing as the catalogue grows. Measuring the
 * same operation at two catalogue sizes is the only shape that catches it: an
 * N+1 grows with N, a single read does not.
 *
 * The bug this was written for was real, not hypothetical — `resolve()` called
 * `isActive()` per slug, which measured 2 queries for 2 flags, 4 for 4 and 8
 * for 8. `FeatureCatalog::activeMap()` reads them in one `WHERE name IN (...)`.
 */
class FeatureFlagPerformanceTest extends TestCase
{
    use RefreshDatabase;

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

    #[Test]
    public function the_index_cost_does_not_grow_with_the_catalogue(): void
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

    #[Test]
    public function the_second_page_view_reads_nothing(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['email_verified_at' => now()]));

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
     * Group E2: a toggle has to be visible to the next reader.
     *
     * The other half of the round trip — the store row, the audit `from`/`to`
     * and the cache flush — is covered in FeatureFlagRouteTest. What is asserted
     * here is the part that only a second resolution can prove: the page
     * reflects the new state. Without it, a writer that forgot to flush would
     * still pass every other assertion in the file.
     */
    #[Test]
    public function a_toggled_flag_is_reflected_by_the_next_page_view(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $user = \App\Models\User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(\App\Models\RoleLookup::find(\App\Support\SystemRole::ADMIN));
        $this->actingAs($user);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

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
