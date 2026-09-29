<?php

namespace App\Actions\V1\Role;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Role index listing with search, sort, and permission/user counts.
 *
 * Guard-scoped for the same reason every other role read is: Spatie keys a role
 * by (name, guard_name), so an unscoped query shows `superadmin` and `admin`
 * twice when both guards hold a row, and the duplicate belongs to a guard no
 * permission check ever reads.
 */
class IndexRoleAction
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
     * Paginated roles on the resolved guard.
     *
     * @param  string|null $search    Matched against the role name
     * @param  string      $sort      Whitelisted above; anything else falls back
     * @param  string      $direction 'asc' or anything else -> 'desc'
     * @param  int         $perPage
     * @param  bool        $trashed   List the trash instead of the live roles
     * @return LengthAwarePaginator<int, Role>
     */
    public function run(
        ?string $search = null,
        string $sort = 'name',
        string $direction = 'desc',
        int $perPage = 10,
        bool $trashed = false,
        ?User $viewer = null,
    ): LengthAwarePaginator {
        // withCount, not with: both counts are displayed, so eager loading the
        // permission and user collections would only fetch rows nothing reads.
        // A trashed role has no users left — DeleteRoleAction detaches them — so
        // its users_count is legitimately 0 rather than a broken relation.
        $query = ($trashed ? Role::onlyTrashed() : Role::query())
            ->where('guard_name', RoleLookup::guard())
            ->withCount(['permissions', 'users']);

        // The superadmin role is only listed for a viewer who is one. UI half of
        // the rule; AssignRolesAction is the half that actually refuses the grant.
        if (! RoleLookup::viewerIsSuperAdmin($viewer)) {
            $query->where('name', '!=', SystemRole::SUPERADMIN);
        }

        if ($search !== null && $search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'name';
        }

        return $query
            ->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }
}
