<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\Role\CreateRoleAction;
use App\Actions\V1\Role\DeleteRoleAction;
use App\Actions\V1\Role\ForceDeleteRoleAction;
use App\Actions\V1\Role\IndexRoleAction;
use App\Actions\V1\Role\RestoreRoleAction;
use App\Actions\V1\Role\RoleBulkActionHandler;
use App\Actions\V1\Role\UpdateRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Role\BulkRoleRequest;
use App\Http\Requests\Role\DeleteRoleRequest;
use App\Http\Requests\Role\ForceDeleteRoleRequest;
use App\Http\Requests\Role\RestoreRoleRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Support\SystemRole;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Role management pages and writes (Phase 6, Group C).
 *
 * Thin by design: the controller resolves route data, hands off to an action,
 * and turns the result into a redirect. The rules live in the Form Requests and
 * the behaviour in the actions, so the same writes are reachable from the API
 * without a second copy.
 *
 * Route-level `can:` gates are still absent (P6-D1) — the write endpoints are
 * guarded by their Form Request `authorize()`, which checks the same
 * roles.create / roles.update / roles.delete permissions the gates will use.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly IndexRoleAction $indexAction,
        private readonly CreateRoleAction $createAction,
        private readonly UpdateRoleAction $updateAction,
        private readonly DeleteRoleAction $deleteAction,
        private readonly RestoreRoleAction $restoreAction,
        private readonly ForceDeleteRoleAction $forceDeleteAction,
        private readonly BulkActionProcessor $bulkProcessor,
        private readonly RoleBulkActionHandler $bulkHandler,
    ) {
    }

    /**
     * List roles with their user and permission counts.
     *
     * `?trashed=1` switches the listing to the trash. The scope is the whole
     * query rather than a per-row filter, so a live role can never appear on the
     * trash tab and offer a Restore that would 404.
     */
    public function index(Request $request): View
    {
        $trashed = $request->boolean('trashed');

        $roles = $this->indexAction->run(
            search: (string) $request->input('search', ''),
            sort: (string) $request->input('sort', 'name'),
            direction: (string) $request->input('direction', 'desc'),
            trashed: $trashed,
            viewer: $request->user(),
        );

        return view('pages.roles.index', [
            'title' => $trashed ? 'Role Trash' : 'Roles',
            'roles' => $roles,
            'search' => (string) $request->input('search', ''),
            'trashed' => $trashed,
            // Both counts are filtered the same way the listing is, or the badge
            // would say 4 over 3 rows and give the hidden role away.
            'trashedCount' => $this->visibleRoles($request, onlyTrashed: true)->count(),
            // The All Roles pill carries a count too, so both pills have the same
            // shape — a bare label beside a badged one reads as a different
            // component. Same reason users/index badges every tab.
            'liveCount' => $this->visibleRoles($request)->count(),
            // Echoed back unresolved: the view only needs them to mark the active
            // sortable column, and re-deriving the whitelist here would give the
            // header a second place to disagree with the query.
            'currentSort' => (string) $request->input('sort', 'name'),
            'currentDirection' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ]);
    }

    /**
     * The roles this viewer is allowed to see counted.
     *
     * Mirrors the filter IndexRoleAction applies, so the tab badges and the rows
     * under them always agree. A superadmin sees the superadmin role; nobody
     * else does, in the list or in the number beside it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Role>
     */
    private function visibleRoles(Request $request, bool $onlyTrashed = false): \Illuminate\Database\Eloquent\Builder
    {
        $query = $onlyTrashed ? Role::onlyTrashed() : Role::query();

        $query->where('guard_name', RoleLookup::guard());

        if (! RoleLookup::viewerIsSuperAdmin($request->user())) {
            $query->where('name', '!=', SystemRole::SUPERADMIN);
        }

        return $query;
    }

    /**
     * Apply one action to many roles from the index bulk bar.
     *
     * No bulkAudit() call, unlike UserController::bulkAction: every role action
     * already writes its own activity row (role.deleted carries revoked_users
     * and revoked_permissions), so an aggregate row here would duplicate the
     * subjects without adding the properties that make the log useful.
     */
    public function bulkAction(BulkRoleRequest $request): RedirectResponse
    {
        $result = $this->bulkProcessor->run(
            action: $request->validated('action'),
            ids: $request->validated('role_ids'),
            causer: $request->user(),
            handler: $this->bulkHandler,
        );

        return back()->with('status', "{$result['count']} selected roles have been successfully {$result['label']}.");
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
     * Persist a new role.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->createAction->run($request->validated(), $request->user());

        return redirect()->route('roles.index')
            ->with('status', 'Role created successfully.');
    }

    /**
     * Persist changes to an existing role.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->updateAction->run($role, $request->validated(), $request->user());

        return redirect()->route('roles.index')
            ->with('status', 'Role updated successfully.');
    }

    /**
     * Move a role to the trash, revoking it from everyone holding it.
     */
    public function destroy(DeleteRoleRequest $request, Role $role): RedirectResponse
    {
        $this->deleteAction->run($role, $request->user(), force: true);

        return redirect()->route('roles.index')
            ->with('status', "Role '{$role->name}' has been moved to trash. Its permissions no longer apply to any user.");
    }

    /**
     * Restore a trashed role. Does not re-assign it to anyone.
     */
    public function restore(RestoreRoleRequest $request, int $role): RedirectResponse
    {
        $trashed = $this->findTrashed($role);

        $this->restoreAction->run($trashed, $request->user());

        return redirect()->route('roles.index')
            ->with('status', "Role '{$trashed->name}' has been restored. Assign it to users again to grant its permissions.");
    }

    /**
     * Permanently delete a trashed role.
     */
    public function forceDelete(ForceDeleteRoleRequest $request, int $role): RedirectResponse
    {
        $trashed = $this->findTrashed($role);

        $this->forceDeleteAction->run($trashed, $request->user());

        return redirect()->route('roles.index')
            ->with('status', "Role '{$trashed->name}' has been permanently deleted.");
    }

    /**
     * Resolve a route id to a trashed role.
     *
     * `onlyTrashed()` rather than `withTrashed()` on purpose: these two endpoints
     * exist to act on the trash, and a live id reaching them is a caller mistake
     * (or a stale tab) that should 404 rather than silently no-op. The implicit
     * `{role}` binding cannot be used at all — it resolves through the global
     * scope, so it 404s every trashed role including the ones these routes are
     * for. Hence the int + explicit lookup.
     */
    private function findTrashed(int $id): Role
    {
        return Role::onlyTrashed()->findOrFail($id);
    }

    /**
     * Permissions on the guard Spatie actually uses, flat and grouped by resource.
     *
     * Grouping is `Str::before($name, '.')` so a new `resource.action`
     * permission needs no entry anywhere to appear under its own heading. The
     * groups hold Permission models, not names: the matrix partial posts
     * `$permission->id` as the checkbox value, and PermissionCatalog::grouped()
     * returns names — which would render a form that cannot save.
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
