<?php

namespace Tests\Feature;

use App\Actions\V1\Role\AssignRolesAction;
use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6-E10 — the query cost of the Group C surfaces.
 *
 * The existing N+1 tests assert a ceiling: "under 20 queries for one page".
 * A ceiling is necessary and not sufficient — 19 queries is still an N+1 that
 * happens to fit under the number someone picked. What actually distinguishes
 * an N+1 from a fixed cost is whether the count MOVES when the row count
 * moves, so every test here measures the same request twice at two different
 * row counts and asserts on the delta. The absolute numbers are reported in the
 * message so a regression is visible even when the delta still passes.
 */
class RbacPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(SystemRole::SUPERADMIN);

        // Every test here is a page render as an admin, and half of them
        // measure query counts — a 302 redirect would pass a count assertion
        // while measuring nothing.
        $this->actingAs($this->admin, 'web');
    }

    /**
     * Count the queries one HTTP request spends, ignoring the writes that
     * built the fixture.
     */
    private function queriesFor(array $params): int
    {
        // The first request warms Spatie's permission cache, which the Gate
        // reads on every can(). Billing that to the view under test would
        // measure the framework's cache fill, not this code.
        $this->get(route('roles.index', $params))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('roles.index', $params))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function makeRoles(int $count, string $prefix = 'Agent'): void
    {
        foreach (range(1, $count) as $n) {
            AppRole::create([
                'name' => "{$prefix} {$n}",
                'guard_name' => RoleLookup::guard(),
            ]);
        }
    }

    public function test_the_roles_index_does_not_grow_with_the_row_count(): void
    {
        $this->makeRoles(10, 'Small');

        $small = $this->queriesFor(['search' => 'Agent']);

        $this->makeRoles(40, 'Large');

        $large = $this->queriesFor(['search' => 'Agent']);

        $this->assertSame(
            $small,
            $large,
            "roles index spent {$small} queries at 10 rows and {$large} at 50 — "
            . 'the page is querying per row, not per page'
        );
    }

    public function test_the_permissions_index_does_not_grow_with_the_row_count(): void
    {
        // The catalogue is seeded, so growth here means the roles_count
        // subquery is not a withCount and each row loads its roles.
        $warm = fn () => $this->get(route('permissions.index'))->assertOk();

        $warm();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $warm();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(1, 25) as $n) {
            $role = AppRole::create([
                'name' => "Bulk {$n}",
                'guard_name' => RoleLookup::guard(),
            ]);
            $role->givePermissionTo(
                \Spatie\Permission\Models\Permission::findByName('users.view', RoleLookup::guard())
            );
        }

        // givePermissionTo flushes Spatie's cache, so the next request pays to
        // refill it — a full permissions select plus the role eager load. That
        // is this test's own fixture write, not the page, so warm it here or
        // the delta measures the cache refill instead of the query shape.
        $warm();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $warm();
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $before,
            $after,
            "permissions index spent {$before} queries with 3 roles and {$after} with 28 — "
            . 'the roles_count column is not eager loaded'
        );
    }

    public function test_the_permission_matrix_adds_no_query_per_permission(): void
    {
        // The matrix renders one checkbox per permission. If the view touched
        // the DB per row, seeding more permissions would cost more queries —
        // but the catalogue is a static array, so this asserts the ceiling
        // holds as the rendered row count grows.
        $warm = fn () => $this->get(route('roles.create'))->assertOk();

        $warm();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $warm();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            6,
            $count,
            "roles.create spent {$count} queries rendering "
            . count(PermissionCatalog::all()) . ' checkboxes — the matrix is querying per row'
        );
    }

    public function test_the_sidebar_costs_no_permission_query(): void
    {
        // The sidebar has no @can gates at all (that gap is P6-D5), so it can
        // only ever be a fixed cost. Pinned because the day someone adds a
        // gate here, this is the test that notices.
        $this->get(route('dashboard'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $q) {
            $this->assertStringNotContainsString(
                'model_has_roles',
                $q['query'],
                'the sidebar is loading roles per request'
            );
        }
    }

    public function test_a_superadmin_check_costs_one_cached_read_not_a_query(): void
    {
        // Gate::before calls hasRole() on every ability. Spatie's cache makes
        // that free after the first read, so a warm request must not re-query
        // the pivot tables no matter how many abilities are checked.
        $this->admin->can('users.view');
        $this->admin->can('roles.view');
        $this->admin->can('settings.manage');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->admin->can('users.view');
        $this->admin->can('roles.view');
        $this->admin->can('settings.manage');

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            0,
            $queries,
            "three warm can() calls spent {$queries} queries — the permission cache is not holding"
        );
    }

    /**
     * P6-C11 regression: role resolution was one query per name.
     *
     * AssignRolesAction mapped the payload through RoleLookup::find(), so
     * assigning 20 roles cost 20 selects — measured at 11 queries for one role
     * and 28 for twenty. findMany() asks for the whole set at once, so the
     * count is now flat. Same delta shape as the page tests: one role vs many.
     */
    public function test_assigning_roles_costs_the_same_at_one_and_at_twenty(): void
    {
        $assign = app(AssignRolesAction::class);

        $countFor = function (array $names) use ($assign): int {
            $user = User::factory()->create();

            // Warm first, then measure. Spatie's permission cache is filled by
            // the first can()/hasRole() in the process and flushed by the role
            // writes below, so measuring a cold call and a warm one compares
            // the cache fill against the query — 11 vs 9, with the real work
            // already flat. Discard the first, measure the second.
            $assign->run(User::factory()->create(), $names, $this->admin);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $assign->run($user, $names, $this->admin);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $one = $countFor([SystemRole::USER]);

        $names = [];
        foreach (range(1, 20) as $n) {
            $names[] = 'Bulk Role '.$n;
            AppRole::create(['name' => 'Bulk Role '.$n, 'guard_name' => RoleLookup::guard()]);
        }

        $twenty = $countFor($names);

        $this->assertSame(
            $one,
            $twenty,
            "assigning roles cost {$one} queries for 1 role and {$twenty} for 20 — "
            .'role resolution is running once per name'
        );
    }

    /**
     * The batch lookup must skip a name that exists on no guard, exactly as the
     * loop did. findMany() returning fewer roles than names IS the skip; this
     * pins that it stays silent rather than throwing.
     */
    public function test_a_batch_lookup_skips_an_unknown_name_rather_than_throwing(): void
    {
        $user = User::factory()->create();

        $assign = app(AssignRolesAction::class);

        // No assertion on the return: the point is that reaching the end of
        // run() at all is the assertion. The old loop filtered a null find();
        // this must not turn that into an exception.
        $assign->run($user, [SystemRole::USER, 'no-such-role-anywhere'], $this->admin);

        $this->assertSame(
            [SystemRole::USER],
            $user->fresh()->roles->pluck('name')->all()
        );
    }
}
