<?php

namespace Tests\Feature\Role;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 6 perf guard: the /permissions stat cards are cached, and the cache
 * must not become a way to show a wrong number.
 *
 * The perf change wrapped four count queries in Cache::remember(30). That
 * trades a query for a 30-second staleness window, so the thing worth
 * asserting is not the speed — it is that the window is bounded, and that the
 * cards still agree with the table they sit above.
 *
 * Query COUNT is asserted and latency is not: on :memory: sqlite a timing
 * threshold measures the machine, and a test that goes red on a slow CI box
 * teaches people to ignore it.
 */
class PermissionStatsCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ceiling for a warm read, with headroom for the auth + list work.
     *
     * Measured, not guessed: the uncached warm read is 8 queries — 1 auth,
     * 1 pagination count, 1 page, 1 eager load, and the 4 stat cards. The
     * cache removes the last 4, so a warm read must not exceed 5. Setting this
     * to 8 (the broken value) let the cache be deleted without the suite
     * noticing, which is the point of measuring it.
     */
    private const MAX_QUERIES = 5;

    protected function setUp(): void
    {
        parent::setUp();
        // Cold: a warm key from another test would make the write assertions
        // below pass without the cache existing at all.
        Cache::flush();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function loginAsSuperadmin(): self
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(SystemRole::SUPERADMIN);

        return $this->actingAs($user);
    }

    private function roleCount(): int
    {
        return Role::where('guard_name', RoleLookup::guard())->count();
    }

        public function test_the_cards_agree_with_the_catalogue_on_a_cold_cache (): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->loginAsSuperadmin();

        $data = $this->get(route('permissions.index'))->viewData();

        $this->assertSame(
            Permission::where('guard_name', RoleLookup::guard())->count(),
            $data['totalPermissions'],
            'the Permissions card disagrees with the table'
        );
        $this->assertSame(
            $data['permissions']->total(),
            $data['totalPermissions'],
            'the card counts the filtered view instead of the whole catalogue'
        );
        $this->assertSame(
            $this->roleCount(),
            $data['totalRoles'],
            'the Roles card disagrees with the roles table'
        );
        $this->assertSame(
            Permission::where('guard_name', RoleLookup::guard())
                ->whereDoesntHave('roles')->count(),
            $data['unusedPermissions'],
            'the Unused card disagrees with the catalogue'
        );
    }

        public function test_the_search_box_does_not_change_the_cards (): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->loginAsSuperadmin();

        $unfiltered = $this->get(route('permissions.index'))->viewData();
        $filtered = $this->get(route('permissions.index', ['search' => 'users']))->viewData();

        $this->assertLessThan($unfiltered['totalPermissions'], count($filtered['permissions']));
        foreach (['totalPermissions', 'totalResources', 'totalRoles', 'unusedPermissions'] as $key) {
            $this->assertSame(
                $unfiltered[$key],
                $filtered[$key],
                "{$key} renumbered on a search; the summary must describe the catalogue"
            );
        }
    }

        public function test_the_second_request_spends_no_query_on_the_cards (): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->loginAsSuperadmin();

        // First read pays for the cache fill.
        $this->get(route('permissions.index'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('permissions.index'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The cards are 4 of the queries on a cold read. A warm read must not
        // re-run them, so the ceiling is the remaining list+pagination work.
        $this->assertLessThanOrEqual(
            self::MAX_QUERIES,
            $queries,
            'a warm /permissions still re-queries the stat cards'
        );
    }

    /**
     * The staleness window is the price of the cache, so it is pinned: the
     * cards may lag, but not past the TTL that was chosen for them.
     */
        public function test_a_card_refreshes_once_the_window_expires (): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->loginAsSuperadmin();

        $before = $this->get(route('permissions.index'))->viewData('totalPermissions');

        Permission::create(['name' => 'probe.extra', 'guard_name' => RoleLookup::guard()]);

        // Inside the window the card is allowed to be stale. Asserting it is
        // NOT stale here would be asserting the cache does not work.
        $this->assertSame(
            $before,
            $this->get(route('permissions.index'))->viewData('totalPermissions'),
            'the card refreshed inside its own window; the cache is not holding'
        );

        // Past the window it must be correct again. 31s of real sleep is the
        // honest way to prove the TTL is what released it — asserting on a
        // manually expired key would pass even if the TTL were 10 years.
        sleep(31);

        $this->assertSame(
            $before + 1,
            $this->get(route('permissions.index'))->viewData('totalPermissions'),
            'the card did not refresh after its window expired'
        );
    }
}
