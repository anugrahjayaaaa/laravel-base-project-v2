<?php

namespace App\Http\Controllers\Web\V1;

use App\Http\Controllers\Controller;
use App\Models\RoleLookup;
use Illuminate\Contracts\View\View;
use Spatie\Permission\Models\Permission;

/**
 * Permission catalogue page (Phase 6, Group A).
 *
 * Read-only on purpose: a permission row that no `can()` call ever references
 * grants nothing, and letting an admin type one in only makes it look real.
 * Permissions are seeded from `App\Support\PermissionCatalog` (P6-B3).
 */
class PermissionController extends Controller
{
    /**
     * List every permission on the app's guard, grouped by resource.
     */
    public function index(): View
    {
        $permissions = Permission::query()
            ->where('guard_name', RoleLookup::guard())
            ->withCount('roles')
            ->orderBy('name')
            ->get();

        $permissionGroups = $permissions
            ->groupBy(fn (Permission $permission): string => str($permission->name)->before('.')->value())
            ->all();

        return view('pages.permissions.index', [
            'title' => 'Permissions',
            'permissions' => $permissions,
            'permissionGroups' => $permissionGroups,
        ]);
    }
}
