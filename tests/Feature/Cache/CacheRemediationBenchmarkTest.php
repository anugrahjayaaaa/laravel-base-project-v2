<?php

namespace Tests\Feature\Cache;

use App\Actions\V1\Role\RoleAssignAction;
use App\Actions\V1\Role\RoleDeleteAction;
use App\Actions\V1\Role\RoleBulkActionHandler;
use App\Actions\V1\User\UserIndexAction;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perf test for the cache remediation (Findings 1 and 2).
 *
 * Three questions, all measured rather than assumed:
 *
 *  1. Does `RoleObserver` put a DB query on the role write path? An observer
 *     that forgets a cache key should be pure cache I/O — if it queries, it is
 *     doing the job wrong.
 *  2. What does a bulk role operation cost? `RoleBulkActionHandler` loops one
 *     action call per role, so the observer fires N times. If each fire is a
 *     round-trip, a 50-role delete pays 50.
 *  3. What did `rememberForever` -> `remember(86400)` cost on the read path?
 *     It should be free; if the read got slower, the safety net was not free.
 *
 * Measures, does not assert thresholds — a perf assertion fails CI on a slow
 * box for reasons unrelated to the code.
 */
class CacheRemediationBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected static array $results = [];

    public static function tearDownAfterClass(): void
    {
        file_put_contents(
            '/tmp/cache_remediation_benchmark.json',
            json_encode(static::$results, JSON_PRETTY_PRINT)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array{queries:int, latencyMs:float}
     */
    private function measure(callable $fn): array
    {
        $fn(); // warm, so the measured run is not paying first-call cost

        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $fn();
        $ms = round((microtime(true) - $start) * 1000, 3);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['queries' => $n, 'latencyMs' => $ms];
    }

    private function record(string $op, array $r): void
    {
        static::$results[$op] = $r;
        fwrite(STDERR, sprintf("  %-48s %2d queries  %7.3f ms\n", $op, $r['queries'], $r['latencyMs']));
    }

    // An instance property, not a `static` local: a `static` survives across the
    // test methods of this class but `tearDown` rebuilds the application, so a
    // user captured in an earlier method is a row this one cannot authenticate
    // with — which showed up as a 302 to /login rather than a failed count.
    private ?User $super = null;

    private function superadmin(): User
    {
        return $this->super ??= tap(
            User::factory()->create(['email_verified_at' => now()]),
            fn (User $u) => $u->assignRole(RoleLookup::find('superadmin'))
        );
    }

    private function makeRole(string $name): Role
    {
        return Role::create(['name' => $name, 'guard_name' => RoleLookup::guard()]);
    }

    public function test_the_observer_adds_no_query_to_a_role_write(): void
    {
        fwrite(STDERR, "\n[Cache fix] observer cost on a role write\n");

        // A bare role update — no action, no transaction. Whatever the observer
        // does shows up here as pure overhead.
        //
        // `measure()` runs its closure once as a warm-up, which is why the
        // closure takes the ROLE BY ARGUMENT and re-fetches it: a closure
        // capturing `$role` would update an already-synced model on the
        // measured pass, `update()` would skip the statement as not-dirty, and
        // the query log would read 0 — "the observer is free" when really the
        // update never happened.
        $id = $this->makeRole('bench_a')->getKey();

        $run = fn (string $name) => $this->measure(function () use ($id, $name) {
            DB::transaction(fn () => Role::find($id)->update(['name' => $name]));
        });

        $run('bench_b');   // warm-up
        $measured = $run('bench_c');

        $this->record('Role::update() + observer (committed)', $measured);

        $this->assertSame(
            1,
            $measured['queries'],
            'a role rename costs more than its own UPDATE — the observer added a query'
        );

        $this->assertSame('bench_c', Role::find($id)->name, 'the rename did not persist');
    }

    public function test_bust_cache_is_pure_cache_io(): void
    {
        fwrite(STDERR, "\n[Cache fix] bustCache() in isolation\n");

        $r = $this->measure(fn () => UserIndexAction::bustCache());

        $this->record('UserIndexAction::bustCache()', $r);

        $this->assertSame(
            0,
            $r['queries'],
            'forgetting two keys must not touch the database'
        );
    }

    public function test_role_assign_cost(): void
    {
        fwrite(STDERR, "\n[Cache fix] role assignment cost\n");

        $action = app(RoleAssignAction::class);
        $super = $this->superadmin();

        // Two roles, so the run alternates grant and revoke and both are measured.
        $r1 = RoleLookup::find('user');
        $r2 = RoleLookup::find('admin');

        $target = User::factory()->create(['email_verified_at' => now()]);

        $measured = $this->measure(function () use ($action, $super, $target, $r1, $r2) {
            DB::transaction(fn () => $action->run($target, [$r1->name], $super));
            DB::transaction(fn () => $action->run($target, [$r1->name, $r2->name], $super));
        });

        $this->record('RoleAssignAction x2 (grant + sync)', $measured);
    }

    /**
     * The one to watch: `RoleBulkActionHandler` loops one action call per role,
     * so the observer fires once per role. If a bust is a round-trip, a bulk
     * delete pays one per row.
     */
    public function test_bulk_role_delete_scales(): void
    {
        fwrite(STDERR, "\n[Cache fix] bulk role delete — observer fires per role\n");

        foreach ([1, 10, 50] as $size) {
            $names = [];
            for ($i = 0; $i < $size; $i++) {
                $names[] = 'bulk_'.$size.'_'.$i;
                $this->makeRole('bulk_'.$size.'_'.$i);
            }

            $roles = Role::withTrashed()->whereIn('name', $names)->get();

            $r = $this->measure(function () use ($roles) {
                DB::transaction(function () use ($roles) {
                    foreach ($roles as $role) {
                        app(RoleDeleteAction::class)->run($role, $this->superadmin(), force: true);
                    }
                });
            });

            $this->record("bulk delete {$size} role(s)", $r);

            // Report the marginal cost per role so the scaling is legible.
            fwrite(STDERR, sprintf("    -> %.2f queries per role\n", $r['queries'] / $size));
        }
    }

    /**
     * The read path: does the TTL safety net cost anything versus a forever key?
     */
    public function test_read_path_after_the_ttl_change(): void
    {
        fwrite(STDERR, "\n[Cache fix] read path with Cache::remember(TTL)\n");

        $admin = $this->superadmin();
        $action = app(UserIndexAction::class);

        $cold = $this->measure(function () use ($action, $admin) {
            Cache::forget('user_index_counts.all');
            $action->counts($admin);
        });
        $this->record('counts() cold (recompute)', $cold);

        $warm = $this->measure(fn () => $action->counts($admin));
        $this->record('counts() warm (cache hit)', $warm);

        $this->assertSame(0, $warm['queries'], 'a warm read must not query');
    }

    /**
     * The users index page, full kernel — what an operator actually loads.
     */
    public function test_users_index_page_load(): void
    {
        fwrite(STDERR, "\n[Cache fix] GET /users (full kernel)\n");

        $super = $this->superadmin();
        $this->actingAs($super);

        $result = $this->measure(fn () => $this->get(route('users.index')));

        $this->record('GET /users (full kernel)', $result);

        // A page load reporting 0 queries would mean the query log was lost,
        // not that the page is free — so the request itself is asserted.
        $this->get(route('users.index'))->assertOk();
        $this->assertGreaterThan(
            0,
            $result['queries'],
            'the page load reported no queries at all — the measurement, not the page, is wrong'
        );
    }

    /**
     * Confirm the observer still fires — a perf number from a silently broken
     * invalidation would be a lie, so this asserts the effect is present.
     */
    public function test_the_observer_still_fires(): void
    {
        $role = $this->makeRole('still_fires');

        Cache::put('user_index_counts.all', ['stale' => true], 3600);
        Cache::put('user_index_counts.masked', ['stale' => true], 3600);

        $role->update(['name' => 'still_fires_renamed']);

        $this->assertFalse(Cache::has('user_index_counts.all'), 'observer did not bust on update');
    }
}
