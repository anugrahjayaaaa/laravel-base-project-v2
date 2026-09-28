<?php

namespace App\Http\Controllers\Web\V1;

use App\Http\Controllers\Controller;
use App\Models\RoleLookup;
use Illuminate\Contracts\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
     * Columns the query may order by. The value arrives in the query string and
     * goes straight into orderBy, so it is checked here rather than passed
     * through.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['name', 'roles_count'];

    /**
     * List every permission on the app's guard, with the roles holding it.
     */
    public function index(): View
    {
        $search = trim((string) request('search', ''));
        $sort = in_array(request('sort'), self::SORTABLE, true) ? request('sort') : 'name';
        $direction = request('direction') === 'desc' ? 'desc' : 'asc';

        // The table is the filtered view; the metrics describe the whole
        // catalogue. Counting the filtered rows would make the summary renumber
        // itself on every keystroke, at which point it is no longer a summary.
        $catalogue = Permission::query()->where('guard_name', RoleLookup::guard());

        $permissions = (clone $catalogue)
            // roles for the names in the Assigned Roles column, roles_count for
            // the Unused metric and the unassigned marker. Two statements, not
            // one per permission.
            ->withCount('roles')
            ->with('roles')
            // Matches the bare name and the resource on its own, so "users" and
            // "view" both find rows — people type the fragment, not the dotted
            // name they see in the table.
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', $search.'.%')
            ))
            ->orderBy($sort, $direction)
            // withQueryString, or page 2 drops the active search and sort. The
            // per-page value is the shared convention (design-system.md
            // §Pagination) — not a per-page decision.
            ->paginate(10)
            ->withQueryString();

        return view('pages.permissions.index', [
            'title' => 'Permissions',
            'permissions' => $permissions,
            'totalPermissions' => (clone $catalogue)->count(),
            // The resource is the part of the name before the dot, so this
            // cannot disagree with the badge rendered next to each permission.
            'totalResources' => (clone $catalogue)
                ->pluck('name')
                ->map(fn (string $name): string => str($name)->before('.')->value())
                ->unique()
                ->count(),
            // Counted rather than derived from the loaded rows: a role holding no
            // permission at all appears in none of them, and is exactly what this
            // number is for.
            'totalRoles' => Role::where('guard_name', RoleLookup::guard())->count(),
            'unusedPermissions' => (clone $catalogue)->whereDoesntHave('roles')->count(),
            'search' => $search,
            'currentSort' => $sort,
            'currentDirection' => $direction,
        ]);
    }
}
