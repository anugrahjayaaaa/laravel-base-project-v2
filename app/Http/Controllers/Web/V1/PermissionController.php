<?php

namespace App\Http\Controllers\Web\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RoleLookup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
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
     * Columns the query may order by. The value arrives in the query string and
     * goes straight into orderBy, so it is checked here rather than passed
     * through.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['name'];

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
            ->when($search !== '', fn($query) => $query->where(
                fn($inner) => $inner
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', $search . '.%')
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
            ...$this->stats(),
            'search' => $search,
            'currentSort' => $sort,
            'currentDirection' => $direction,
        ]);
    }

    /**
     * The four metric cards, behind a 30-second cache.
     *
     * The cards describe the whole catalogue, not the filtered page, so the
     * numbers only move when a permission or a role is written — never when
     * someone types in the search box. Measured on the 19-permission dev
     * catalogue, the four counts cost ~7ms of a ~25ms page render and are
     * identical for every visitor in the same 30s window, so they are read
     * once and reused.
     *
     * 30 seconds is the staleness budget, not a tuning knob: a permission
     * granted from another tab can take that long to show in this card. The
     * catalogue is read-only in Group A, so there is no write path to flush it
     * from yet — when Groups B-D add one, flush this key in the same place
     * the registrar is flushed.
     *
     * The resource count stays in PHP (`before('.')`) rather than becoming
     * `COUNT(DISTINCT SUBSTRING_INDEX(...))`: the whole aggregation measured
     * no faster than the four separate counts, and SUBSTRING_INDEX does not
     * exist on SQLite, which is what the test suite runs on.
     *
     * @return array{totalPermissions: int, totalResources: int, totalRoles: int, unusedPermissions: int}
     */
    private function stats(): array
    {
        $guard = RoleLookup::guard();

        return Cache::remember('permissions_page_stats.' . $guard, 30, function () use ($guard): array {
            $catalogue = Permission::query()->where('guard_name', $guard);

            return [
                'totalPermissions' => (clone $catalogue)->count(),
                // The resource is the part of the name before the dot, so this
                // cannot disagree with the badge rendered next to each permission.
                'totalResources' => (clone $catalogue)
                    ->pluck('name')
                    ->map(fn(string $name): string => str($name)->before('.')->value())
                    ->unique()
                    ->count(),
                // Counted rather than derived from the loaded rows: a role holding no
                // permission at all appears in none of them, and is exactly what this
                // number is for.
                'totalRoles' => Role::where('guard_name', $guard)->count(),
                'unusedPermissions' => (clone $catalogue)->whereDoesntHave('roles')->count(),
            ];
        });
    }
}
