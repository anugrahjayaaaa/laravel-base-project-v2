<?php

namespace App\Http\Controllers\Web\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RoleLookup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Role management pages (Phase 6, Group A).
 *
 * Group A is UI-only: these methods assemble the view data and render. The
 * permission checks, Form Requests, save/delete actions and route gates land in
 * Groups B–D, so a `user` with zero permissions can still reach these pages
 * until then — that window closes at P6-D1.
 */
class RoleController extends Controller
{
    /**
     * Columns the index may be sorted by.
     *
     * The values reach an `orderBy`, so this list is the whitelist that keeps a
     * `?sort=` from becoming SQL — same reason `UserIndexAction::applySort`
     * hardcodes its own.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['name', 'created_at'];

    /**
     * List roles with their user and permission counts.
     */
    public function index(Request $request): View
    {
        $sort = (string) $request->input('sort', 'name');
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'name';
        }

        $query = Role::query()
            ->where('guard_name', RoleLookup::guard())
            ->withCount(['permissions', 'users']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $roles = $query->orderBy($sort, $direction)->paginate(10)->withQueryString();

        return view('pages.roles.index', [
            'title' => 'Roles',
            'roles' => $roles,
            'search' => (string) $request->input('search', ''),
            'currentSort' => $sort,
            'currentDirection' => $direction,
        ]);
    }

    /**
     * Show the create role form.
     */
    public function create(): View
    {
        [$permissions, $permissionGroups] = $this->permissionData();

        return view('pages.roles.create', [
            'title' => 'Create Role',
            'permissions' => $permissions,
            'permissionGroups' => $permissionGroups,
        ]);
    }

    /**
     * Show the edit role form.
     */
    public function edit(Role $role): View
    {
        $role->load('permissions');
        $role->loadCount('users');

        [$permissions, $permissionGroups] = $this->permissionData();

        return view('pages.roles.edit', [
            'title' => 'Edit Role',
            'role' => $role,
            'permissions' => $permissions,
            'permissionGroups' => $permissionGroups,
        ]);
    }

    /**
     * Permissions on the guard Spatie actually uses, flat and grouped by resource.
     *
     * Grouping is `Str::before($name, '.')` so a new `resource.action`
     * permission needs no entry anywhere to appear under its own heading.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array<string, \Illuminate\Support\Collection>}
     */
    private function permissionData(): array
    {
        $permissions = Permission::query()
            ->where('guard_name', RoleLookup::guard())
            ->orderBy('name')
            ->get();

        $groups = $permissions->groupBy(fn (Permission $permission): string => str($permission->name)->before('.')->value());

        return [$permissions, $groups->all()];
    }
}
