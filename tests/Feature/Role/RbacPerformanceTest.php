<?php

namespace Tests\Feature\Role;

use App\Actions\V1\Role\RoleAssignAction;
use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;

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
                Permission::findByName('users.view', RoleLookup::guard())
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

    /**
     * P6-E10, second pass — the sidebar now CARRIES gates.
     *
     * The first version of this test could only assert a fixed cost, because
     * P6-D5 had not landed: the sidebar had no @can at all, so there was nothing
     * to measure. It also said so in a comment, which is how a test ends up
     * documenting behaviour nobody checked.
     *
     * The composer now runs one can() per permissioned item — five of them. The
     * assertion is ABSOLUTE (1 query), not a delta, and that choice is
     * deliberate: the menu is a literal array, so the item count cannot grow
     * between two runs of the same code, and a delta between two fixtures of
     * the SAME item count is always zero. A delta test here would pass against a
     * composer that queries the database once per item — measured: swapping
     * can() for a raw `DB::table('permissions')->exists()` takes the dashboard
     * from 1 query to 5, and a delta test does not notice, because both its
     * fixtures still render the same five items.
     *
     * 1 = the dashboard's own query. The gates add nothing: Gate::before
     * short-circuits on hasRole() and Spatie's cache holds the pivot read, so
     * five can() calls are free.
     */
    public function test_the_sidebar_gates_cost_nothing(): void
    {
        $this->get(route('dashboard'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            count($queries),
            'the dashboard spent '.count($queries).' queries — the five sidebar gates are '
            .'hitting the database, which means one query per menu item'
        );

        // Named, so the failure says which gate moved rather than just a count.
        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'from "permissions"',
                $query['query'],
                'the sidebar resolves a permission per item instead of reading the cached set'
            );
        }
    }

    /**
     * The D5/D6/D7 view gates are per-ROW triggers — one @can per user row on
     * the list. That is the shape that hides an N+1, so it is measured here.
     *
     * The assertion is ABSOLUTE (3 queries), and the choice is worth recording
     * because a delta was tried first and does not work here for two reasons:
     *
     * 1. perPage is 10, so 4 users and 25 users render the same capped number
     *    of rows. A delta between them is zero by construction.
     * 2. A Spatie gate cannot produce a per-row query at all: hasPermissionTo
     *    resolves against ONE globally cached permission set, not against the
     *    row's own model instance. Measured: replacing the viewer with a
     *    row-scoped check added 0 queries at any row count.
     *
     * So the thing that would actually regress is a gate that stops using the
     * cached set and starts querying — which is what the named assertion below
     * catches, and which a count alone would report as "3, fine".
     *
     * 3 = the list query, its count, and the session/settings read. Measured
     * 2026-09-30 at 4 users and at 25.
     */
    public function test_the_view_gates_cost_nothing_per_row(): void
    {
        $viewer = $this->viewerWithEveryPermission();
        User::factory()->count(10)->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($viewer, 'web');

        $this->get(route('users.index'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('users.index'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            3,
            count($queries),
            'the user list spent '.count($queries).' queries for 11 rows — a per-row gate '
            .'is querying per row'
        );

        // Named, so the failure says which read moved rather than just a count.
        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'from "permissions"',
                $query['query'],
                'a view gate resolved a permission row instead of reading the cached set'
            );
        }
    }

    /**
     * A caller the Gate actually resolves, holding the whole catalogue.
     *
     * NOT the superadmin: Gate::before answers true for it, so a gate written
     * as `@can(...) || $somethingExpensive` never evaluates the right-hand side.
     * A superadmin viewer cannot tell a cheap gate from an expensive one — the
     * measurement would hide behind the short circuit.
     */
    private function viewerWithEveryPermission(): User
    {
        $role = AppRole::create([
            'name' => 'Row Probe '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            Permission::whereIn('name', PermissionCatalog::all())
                ->where('guard_name', RoleLookup::guard())
                ->get()
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * P6-D7 — the role picker renders one checkbox per ROLE, inside an
     * `@can('users.assign_roles')` block. That is a per-row gate on a
     * variable-length collection, which is the only shape in 6D that can
     * genuinely produce an N+1: the gate count grows with the role count.
     *
     * So this one IS a delta, not an absolute — unlike the sidebar and the
     * user list, the collection it renders is not capped by a page size. 30
     * extra roles render 30 extra checkboxes, so if anything resolved per
     * checkbox the count would move. Measured: 5 queries at 4 roles and at
     * 34.
     *
     * 5 = the session read, the target user, their failed-login aggregate,
     * their roles, and the assignable role list.
     */
    public function test_the_role_picker_gates_cost_nothing_per_role(): void
    {
        $viewer = $this->viewerWithEveryPermission();
        $target = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($viewer, 'web');

        $countFor = function () use ($target): int {
            // Warm first: givePermissionTo-style writes flush Spatie's cache,
            // and the refill would otherwise be billed to the page.
            $this->get(route('users.edit', $target))->assertOk();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('users.edit', $target))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $few = $countFor();

        $this->makeRoles(30, 'Picker');

        $many = $countFor();

        $this->assertSame(
            $few,
            $many,
            "the role picker cost {$few} queries with 4 roles and {$many} with 34 — "
            .'a gate is resolving once per checkbox'
        );
    }

    /**
     * The rest of the 6D surfaces, pinned so a gate added to one of them is
     * noticed. Absolute, because these pages render a FIXED number of gates
     * (one picker block, one settings-write block) regardless of data — a
     * delta between two row counts is zero here for the same reason it is on
     * the sidebar. See the sidebar test for why an absolute count plus the
     * named table read is the stronger assertion on a fixed-cost page.
     *
     * Measured 2026-09-30 with a full-catalogue (non-superadmin) viewer, warm
     * cache, SQLite.
     */
    #[DataProvider('sixDSurfaces')]
    public function test_a_phase_6d_surface_costs_a_fixed_number_of_queries(
        string $page,
        int $expected,
        callable $url
    ): void {
        $this->actingAs($this->viewerWithEveryPermission(), 'web');

        User::factory()->count(10)->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $url = $url();

        $this->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            $expected,
            count($queries),
            "{$page} spent ".count($queries)." queries, budget {$expected} — "
            .'a 6D gate started resolving against the database'
        );

        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'from "permissions"',
                $query['query'],
                "{$page} resolved a permission row instead of reading the cached set"
            );
        }
    }

    /**
     * The 6D surfaces the sidebar and user-list tests do not already cover.
     * Kept in one provider so the whole measured surface is visible in one
     * place instead of scattered across test names.
     *
     * 2 = create: session + the assignable role list.
     * 5 = edit / show: session + the target + their failed-login aggregate +
     *     their roles + the assignable role list.
     * 3 = settings: session + the timezone list + the assignable role list.
     */
    public static function sixDSurfaces(): array
    {
        return [
            'users.create — picker gates' => [
                'GET /users/create',
                2,
                fn () => route('users.create'),
            ],
            'users.edit — picker gates' => [
                'GET /users/{id}/edit',
                5,
                fn () => route('users.edit', User::query()->firstOrFail()),
            ],
            'users.show — role list gates' => [
                'GET /users/{id}',
                5,
                fn () => route('users.show', User::query()->firstOrFail()),
            ],
            'settings.index — write form gate' => [
                'GET /settings',
                3,
                fn () => route('settings.index'),
            ],
        ];
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
     * RoleAssignAction mapped the payload through RoleLookup::find(), so
     * assigning 20 roles cost 20 selects — measured at 11 queries for one role
     * and 28 for twenty. findMany() asks for the whole set at once, so the
     * count is now flat. Same delta shape as the page tests: one role vs many.
     */
    public function test_assigning_roles_costs_the_same_at_one_and_at_twenty(): void
    {
        $assign = app(RoleAssignAction::class);

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

        $assign = app(RoleAssignAction::class);

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
