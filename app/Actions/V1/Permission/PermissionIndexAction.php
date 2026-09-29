<?php

namespace App\Actions\V1\Permission;

use App\Models\RoleLookup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Permission\Models\Permission;

/**
 * Read the permission catalogue for the permissions index.
 *
 * Owns the query so the controller is left with request parsing and the view
 * call, and so the catalogue's rules — guard scoping, the sortable whitelist,
 * the search shape — are testable without an HTTP request.
 *
 * ## Why this does not return a grouped collection
 *
 * P6-C7 originally asked for the result grouped by resource prefix. That is
 * incompatible with what the page actually is: a searchable, sortable,
 * paginated table of ~20 rows. A paginator cannot be grouped — the page boundary
 * would fall inside an arbitrary group, and every search, sort and page link
 * would have to be re-implemented per group.
 *
 * The resource is still derived, and it is derived once: the view reads
 * `str($permission->name)->before('.')` per row (permissions/index.blade.php:101)
 * and renders it as the Resource badge. Grouping would be a second derivation of
 * a value the row already carries, in exchange for dropping search, sort and
 * pagination.
 *
 * ponytail: if the catalogue ever outgrows a flat table — hundreds of
 * permissions, or a page that genuinely wants sections — group here and drop
 * pagination at the same time. Doing both is the thing that does not work.
 */
class PermissionIndexAction
{
    /**
     * Columns the query may order by.
     *
     * The value arrives in the query string and goes straight into orderBy, so
     * it is checked here rather than passed through — an unchecked value is
     * arbitrary SQL.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['name'];

    /**
     * The catalogue, filtered, sorted and paginated.
     *
     * @param  string       $search    Free-text filter; '' for none
     * @param  string|null  $sort      Requested column, or null for the default
     * @param  string       $direction 'asc' or 'desc'
     * @param  int          $perPage
     * @return array{permissions: LengthAwarePaginator, search: string, currentSort: string, currentDirection: string}
     */
    public function run(
        string $search = '',
        ?string $sort = null,
        string $direction = 'asc',
        int $perPage = 10,
    ): array {
        $search = trim($search);
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'name';
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $permissions = Permission::query()
            ->where('guard_name', RoleLookup::guard())
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
            // withQueryString, or page 2 drops the active search and sort.
            ->paginate($perPage)
            ->withQueryString();

        return [
            'permissions' => $permissions,
            'search' => $search,
            'currentSort' => $sort,
            'currentDirection' => $direction,
        ];
    }
}
