<?php

namespace Tests\Feature;

use App\Actions\V1\Permission\PermissionIndexAction;
use App\Models\Role;
use App\Models\RoleLookup;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The catalogue query, tested without an HTTP request.
 *
 * The point of the action is that these rules are reachable this way, so the
 * guard scoping and the sortable whitelist are asserted here rather than
 * inferred from a page rendering.
 */
class PermissionIndexActionTest extends TestCase
{
    use RefreshDatabase;

    private PermissionIndexAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->action = app(PermissionIndexAction::class);
    }

    public function test_it_returns_the_seeded_catalogue_paginated(): void
    {
        $result = $this->action->run();

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $result['permissions']);
        $this->assertSame(
            Permission::where('guard_name', RoleLookup::guard())->count(),
            $result['permissions']->total()
        );
    }

    public function test_it_only_returns_permissions_on_the_resolved_guard(): void
    {
        // Same name, other guard: present in the table, absent from the page.
        Permission::create(['name' => 'ghost.permission', 'guard_name' => 'api']);

        $names = $this->action->run()['permissions']->pluck('name');

        $this->assertNotContains('ghost.permission', $names);
    }

    public function test_it_eager_loads_the_role_count_and_roles(): void
    {
        $permission = Permission::where('name', 'users.view')->firstOrFail();
        RoleLookup::find('admin')->givePermissionTo($permission);

        // Page size large enough to hold the whole catalogue: the row under test
        // is not on page 1 of ten.
        $row = $this->action->run(perPage: 100)['permissions']->firstWhere('name', 'users.view');

        $this->assertNotNull($row);
        // Eager-loaded, not lazy: roles_count exists, and `roles` is already
        // loaded so the view does not issue a query per row.
        $this->assertTrue(isset($row->roles_count));
        $this->assertTrue($row->relationLoaded('roles'));
    }

    public function test_search_matches_the_full_name_and_the_bare_resource(): void
    {
        $byResource = $this->action->run(search: 'roles')['permissions']->pluck('name');
        $this->assertContains('roles.view', $byResource);

        // A bare verb still finds rows — people type the fragment, not the
        // dotted name they see in the table.
        $byVerb = $this->action->run(search: 'view')['permissions']->pluck('name');
        $this->assertContains('users.view', $byVerb);
    }

    public function test_an_unknown_sort_column_falls_back_instead_of_reaching_sql(): void
    {
        $result = $this->action->run(sort: 'name); DROP TABLE permissions;--');

        $this->assertSame('name', $result['currentSort']);
        // The table is still there.
        $this->assertGreaterThan(0, Permission::count());
    }

    public function test_it_reports_the_resolved_criteria_back_to_the_view(): void
    {
        $result = $this->action->run(search: '  users  ', sort: 'name', direction: 'desc');

        $this->assertSame('users', $result['search'], 'search should be trimmed');
        $this->assertSame('name', $result['currentSort']);
        $this->assertSame('desc', $result['currentDirection']);
    }

    public function test_it_sorts_descending(): void
    {
        // A big page: the catalogue is larger than the default 10, and reversing
        // two partial pages is not the same assertion.
        $asc = $this->action->run(perPage: 100)['permissions']->pluck('name')->all();
        $desc = $this->action->run(direction: 'desc', perPage: 100)['permissions']->pluck('name')->all();

        $this->assertGreaterThan(10, count($asc), 'precondition: the page holds the whole catalogue');
        $this->assertSame(array_reverse($asc), $desc);
    }
}
