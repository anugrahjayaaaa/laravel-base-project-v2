<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 Group A gate: the roles + permissions views render, obey the design
 * system, and execute no query of their own.
 *
 * The "no query in a view" rule is asserted by counting queries around the
 * render call. A view that reaches for `Role::…` or `SystemSetting::…` is the
 * failure this catches — `ui-architecture.md` rule 1, and the reason the
 * controllers hand over plain collections instead.
 */
class RbacUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A role as the views see it: the `is_system` attribute is computed by
     * the Role model's accessor (App\Models\Role), or set explicitly here
     * for fixture objects that use the base Spatie class.
     */
    private function login(): self
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

        return $this;
    }

    /**
     * Render a view outside the HTTP kernel with an empty error bag.
     *
     * `@if ($errors->any())` is satisfied by ShareErrorsFromSession in a real
     * request; a bare `view()->render()` has no such variable and would fail on
     * a line every page shares.
     */
    private function render(string $view, array $data): string
    {
        return view($view, array_merge(['errors' => new ViewErrorBag()], $data))->render();
    }

    private function roleRow(string $name, bool $isSystem, int $permissions = 0, int $users = 0): Role
    {
        $role = new Role(['name' => $name, 'guard_name' => RoleLookup::guard()]);
        $role->id = crc32($name) % 10000;
        $role->is_system = $isSystem;
        $role->permissions_count = $permissions;
        $role->users_count = $users;
        $role->setRelation('permissions', new Collection());

        return $role;
    }

    private function paginator(Collection $rows): LengthAwarePaginator
    {
        return new LengthAwarePaginator($rows, $rows->count(), 10, 1, ['path' => url('/roles')]);
    }

    private function permissionRows(): Collection
    {
        return collect([
            new Permission(['name' => 'users.view', 'guard_name' => RoleLookup::guard()]),
            new Permission(['name' => 'users.update', 'guard_name' => RoleLookup::guard()]),
            new Permission(['name' => 'roles.view', 'guard_name' => RoleLookup::guard()]),
        ])->each(function (Permission $permission, int $index): void {
            $permission->id = $index + 1;
            $permission->roles_count = 1;
            // The permissions index renders role NAMES, so the fixture has to
            // carry the same preloaded relation the controller eager-loads.
            // Without it, `$permission->roles` would lazy-load on render — and
            // the "queries nothing" assertion would fail on the fixture rather
            // than on anything the view did wrong.
            $role = new Role(['name' => 'admin', 'guard_name' => RoleLookup::guard()]);
            $role->id = 1;
            $permission->setRelation('roles', new Collection([$role]));
        });
    }

    /**
     * The view data the permissions index needs, mirroring PermissionController.
     */
    private function permissionIndexData(): array
    {
        $permissions = $this->permissionRows();

        return [
            // A paginator, not the bare collection: the view calls
            // currentPage()/perPage()/firstItem()/links() on it, and a fixture
            // that skipped that would pass while the real page broke.
            'permissions' => new LengthAwarePaginator(
                $permissions,
                $permissions->count(),
                10,
                1,
                ['path' => url('/permissions')]
            ),
            'totalPermissions' => $permissions->count(),
            'totalResources' => $permissions->pluck('name')
                ->map(fn (string $name): string => str($name)->before('.')->value())
                ->unique()
                ->count(),
            'totalRoles' => 1,
            'unusedPermissions' => $permissions->where('roles_count', 0)->count(),
            'search' => '',
            'currentSort' => 'name',
            'currentDirection' => 'asc',
        ];
    }

    private function grouped(Collection $permissions): array
    {
        return $permissions
            ->groupBy(fn (Permission $permission): string => str($permission->name)->before('.')->value())
            ->all();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function views(): array
    {
        return [
            'roles index' => ['pages.roles.index', ['roles' => 'paginator', 'search' => '', 'currentSort' => 'name', 'currentDirection' => 'asc']],
            'roles create' => ['pages.roles.create', ['permissions' => 'permissions', 'permissionGroups' => 'grouped']],
            'permissions index' => ['pages.permissions.index', ['permissions' => 'paginator', 'totalPermissions' => 'int', 'totalResources' => 'int', 'totalRoles' => 'int', 'unusedPermissions' => 'int', 'search' => '', 'currentSort' => 'name', 'currentDirection' => 'asc']],
        ];
    }

    #[Test]
    #[DataProvider('views')]
    public function test_each_view_renders_and_queries_nothing(string $view, array $keys): void
    {
        $this->login();

        $permissions = $this->permissionRows();
        $shared = [
            'roles' => $this->paginator(collect([$this->roleRow('admin', true, 4, 2)])),
            'search' => '',
            'currentSort' => 'name',
            'currentDirection' => 'asc',
            'permissionGroups' => $this->grouped($permissions),
        ];

        // `+` keeps the LEFT operand, so one merged bag cannot give the roles
        // forms their raw Collection and the permissions index its
        // LengthAwarePaginator. Assign per view instead.
        $data = $view === 'pages.permissions.index'
            ? $shared + $this->permissionIndexData()
            : $shared + ['permissions' => $permissions];

        // First render warms Spatie's permission cache, which the Gate reads on
        // every @can. Measuring only that would blame the framework for the
        // view's own behaviour — and a Role::… left in Blade fires on the second
        // render too, so the delta is what actually measures the view.
        $this->render($view, array_intersect_key($data, $keys));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->render($view, array_intersect_key($data, $keys));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotSame('', $html, "{$view} rendered nothing");
        $this->assertSame([], $queries, "{$view} queried from inside the view");
    }

    #[Test]
    public function the_forbidden_classes_never_appear(): void
    {
        $this->login();

        $permissions = $this->permissionRows();

        $rendered = [
            'pages.roles.index' => $this->render('pages.roles.index', [
                'roles' => $this->paginator(collect([$this->roleRow('admin', true, 4, 2)])),
                'search' => '',
                'currentSort' => 'name',
                'currentDirection' => 'asc',
            ]),
            'pages.roles.create' => $this->render('pages.roles.create', [
                'permissions' => $permissions,
                'permissionGroups' => $this->grouped($permissions),
            ]),
            'pages.roles.edit' => $this->render('pages.roles.edit', [
                'role' => $this->roleRow('staff', false, 3, 1),
                'permissions' => $permissions,
                'permissionGroups' => $this->grouped($permissions),
            ]),
            'pages.permissions.index' => $this->render('pages.permissions.index', $this->permissionIndexData()),
        ];

        foreach ($rendered as $view => $html) {
            $this->assertDoesNotMatchRegularExpression('/\bbg-white\b/', $html, "{$view} uses bg-white");
            $this->assertDoesNotMatchRegularExpression('/\bbg-light\b/', $html, "{$view} uses bg-light");
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bcard-body\b(?! p-4)[^"]*"/', $html, "{$view} has a card-body without p-4");
        }
    }

    #[Test]
    public function a_system_role_shows_a_badge_and_no_delete_trigger(): void
    {
        $this->login();

        $html = $this->render('pages.roles.index', [
            'roles' => $this->paginator(collect([$this->roleRow('superadmin', true, 0, 1)])),
            'search' => '',
            'currentSort' => 'name',
            'currentDirection' => 'asc',
        ]);

        $this->assertStringContainsString('System', $html);
        $this->assertStringNotContainsString('data-action-type="delete_role"', $html);
    }

    #[Test]
    public function a_custom_role_gets_the_delete_trigger(): void
    {
        $this->login();

        $html = $this->render('pages.roles.index', [
            'roles' => $this->paginator(collect([$this->roleRow('staff', false, 3, 0)])),
            'search' => '',
            'currentSort' => 'name',
            'currentDirection' => 'asc',
        ]);

        $this->assertStringContainsString('data-action-type="delete_role"', $html);
        $this->assertStringContainsString('data-item-name="staff"', $html);
    }

    #[Test]
    public function the_matrix_posts_permission_ids_and_preserves_the_selection(): void
    {
        $this->login();

        $permissions = $this->permissionRows();

        $html = $this->render('pages.roles.edit', [
            'role' => tap($this->roleRow('staff', false, 2, 1), function (Role $role): void {
                $role->setRelation('permissions', $this->permissionRows()->take(2));
            }),
            'permissions' => $permissions,
            'permissionGroups' => $this->grouped($permissions),
        ]);

        $this->assertStringContainsString('name="permissions[]"', $html);
        // IDs, not names — syncPermissions resolves a string as a NAME and throws.
        $this->assertStringContainsString('value="1"', $html);
        // The two already on the role come back checked.
        $this->assertSame(2, substr_count($html, 'checked'), 'pre-checked selection was not preserved');
    }

    #[Test]
    public function an_empty_permission_set_renders_the_empty_state(): void
    {
        $this->login();

        $html = $this->render('pages.roles.create', [
            'permissions' => collect(),
            'permissionGroups' => [],
        ]);

        $this->assertStringContainsString('No permissions are defined.', $html);
        $this->assertStringNotContainsString('name="permissions[]"', $html);
    }

    #[Test]
    public function the_permission_catalogue_searches(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        // superadmin, not a plain user: once P6-D1 gates this route, a user with
        // zero permissions gets a 403 and the test stops being about search.
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(\App\Support\SystemRole::SUPERADMIN);
        $this->actingAs($user);

        // A bare fragment is what gets typed; the dotted name is what is shown.
        // `settings` is 2 rows, so page 1 holds all of them.
        $this->get(route('permissions.index', ['search' => 'settings']))
            ->assertOk()
            ->assertSee('settings.view')
            ->assertSee('settings.manage')
            ->assertDontSee('roles.view');

        $this->get(route('permissions.index', ['search' => 'nothing_matches_this']))
            ->assertOk()
            ->assertSee('No permission matches');

        // The metrics describe the catalogue, not the filtered view — otherwise
        // the summary renumbers itself on every keystroke.
        $filtered = $this->get(route('permissions.index', ['search' => 'settings']))
            ->viewData();
        $this->assertCount(2, $filtered['permissions']);
        $this->assertSame(19, $filtered['totalPermissions']);
    }

    #[Test]
    public function the_permission_catalogue_paginates(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(\App\Support\SystemRole::SUPERADMIN);
        $this->actingAs($user);

        $all = $this->get(route('permissions.index'))->viewData('permissions');
        $this->assertSame(19, $all->total());
        $this->assertSame(10, $all->perPage(), 'design-system.md §Pagination: 10 per page');
        $this->assertCount(10, $all);
        $this->assertSame(2, $all->lastPage());

        // 11 users.* rows at 10 per page puts users.view — which sorts last — on
        // page 2. Asserted on the data, not the markup: the name also appears in
        // the page-2 link, so a string check would pass or fail for the wrong
        // reason.
        $page1 = $this->get(route('permissions.index', ['search' => 'users']))->viewData('permissions');
        $this->assertCount(10, $page1);
        $this->assertNotContains('users.view', $page1->pluck('name')->all());

        $page2 = $this->get(route('permissions.index', ['search' => 'users', 'page' => 2]))
            ->viewData('permissions');
        $this->assertSame(['users.view'], $page2->pluck('name')->all());

        // Row numbers continue across pages instead of restarting at 1.
        $this->assertStringContainsString(
            'Showing 11 to 11 of 11 entries',
            $this->get(route('permissions.index', ['search' => 'users', 'page' => 2]))->getContent()
        );

        // withQueryString: the page-2 link must carry the search, or clicking it
        // silently drops the filter.
        $this->assertStringContainsString(
            'search=users',
            $this->get(route('permissions.index', ['search' => 'users']))->getContent()
        );
    }

    #[Test]
    public function the_permission_catalogue_sorts(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(\App\Support\SystemRole::SUPERADMIN);
        $this->actingAs($user);

        // Read each list out immediately, one request at a time. A LengthAwarePaginator
        // is a live object, and holding one across later requests made the
        // comparison compare a stale list against a fresh one.
        $asc = $this->get(route('permissions.index', ['sort' => 'name', 'direction' => 'asc']))
            ->viewData('permissions')->pluck('name')->all();
        $this->assertSame('permissions.view', $asc[0]);

        $desc = $this->get(route('permissions.index', ['sort' => 'name', 'direction' => 'desc']))
            ->viewData('permissions')->pluck('name')->all();

        // Compare against the full sorted set, not page 1 reversed: only 10 of
        // 19 rows fit on a page, so reversing the first page is not the second
        // page — it is a different 10 rows.
        $all = Permission::where('guard_name', RoleLookup::guard())
            ->orderBy('name')->pluck('name')->all();
        $this->assertSame(array_slice($all, 0, 10), $asc);
        $this->assertSame(array_slice(array_reverse($all), 0, 10), $desc);

        // ?sort= reaches orderBy, so an unknown column must not reach SQL and
        // must fall back to the default order — not merely avoid a 500.
        $injected = $this->get(route('permissions.index', ['sort' => 'name); DROP TABLE permissions;--']))
            ->assertOk()
            ->viewData('permissions')->pluck('name')->all();
        $this->assertSame(
            $this->get(route('permissions.index', ['sort' => 'name', 'direction' => 'asc']))
                ->viewData('permissions')->pluck('name')->all(),
            $injected
        );
    }

    #[Test]
    public function the_permission_catalogue_offers_no_write_controls(): void
    {
        $this->login();

        $html = $this->render('pages.permissions.index', $this->permissionIndexData());

        $this->assertStringContainsString('users.view', $html);
        // Read-only: no trigger, and no form posting anywhere under /roles or
        // /permissions. The app layout's own logout form is expected and is not
        // what this is about, hence the route-scoped check rather than `<form`.
        $this->assertStringNotContainsString('data-action-type=', $html);
        $this->assertDoesNotMatchRegularExpression('/action="[^"]*\/(roles|permissions)/', $html);
    }

    #[Test]
    public function the_index_offers_a_filter_and_a_create_button(): void
    {
        $this->login();

        $html = $this->render('pages.roles.index', [
            'roles' => $this->paginator(collect([$this->roleRow('staff', false, 3, 0)])),
            'search' => '',
            'currentSort' => 'name',
            'currentDirection' => 'asc',
        ]);

        $this->assertStringContainsString('name="search"', $html);
        // It is an anchor, not a form field.
        $this->assertStringContainsString('href="'.route('roles.create').'"', $html);
        $this->assertStringContainsString('Create Role', $html);
    }

    #[Test]
    public function the_index_headers_sort_and_an_unknown_column_is_ignored(): void
    {
        $this->login();

        $html = $this->render('pages.roles.index', [
            'roles' => $this->paginator(collect([$this->roleRow('staff', false, 3, 0)])),
            'search' => '',
            'currentSort' => 'name',
            'currentDirection' => 'asc',
        ]);

        // Name is sortable; #, Users, Permissions and Actions are not.
        $this->assertStringContainsString('sort=name', $html);
        $this->assertStringNotContainsString('sort=users_count', $html);
        $this->assertStringNotContainsString('sort=permissions_count', $html);
        $this->assertStringNotContainsString('sort=actions', $html);

        // The value reaches orderBy, so an unknown column must fall back to the
        // default rather than travel into SQL.
        Role::create(['name' => 'aaa', 'guard_name' => RoleLookup::guard()]);
        Role::create(['name' => 'zzz', 'guard_name' => RoleLookup::guard()]);

        $this->get(route('roles.index', ['sort' => 'name); DROP TABLE roles;--']))->assertOk();
        $this->get(route('roles.index', ['sort' => 'guard_name']))->assertOk();
        $this->assertSame(2, Role::where('guard_name', RoleLookup::guard())->count());
    }

    #[Test]
    public function sorting_preserves_the_active_filter_over_http(): void
    {
        $this->login();

        $html = $this->get(route('roles.index', ['search' => 'adm', 'sort' => 'name', 'direction' => 'desc']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/href="\?[^"]*search=adm[^"]*sort=name/', $html);
        $this->assertStringContainsString('value="adm"', $html);
    }

    #[Test]
    public function every_page_is_reachable_over_http(): void
    {
        $role = Role::create(['name' => 'staff', 'guard_name' => RoleLookup::guard()]);

        $this->login()->get(route('roles.index'))->assertOk();
        $this->login()->get(route('roles.create'))->assertOk();
        $this->login()->get(route('roles.edit', $role))->assertOk();
        $this->login()->get(route('permissions.index'))->assertOk();
    }
}
