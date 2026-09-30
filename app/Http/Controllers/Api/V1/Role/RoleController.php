<?php

namespace App\Http\Controllers\Api\V1\Role;

use App\Actions\V1\Role\CreateRoleAction;
use App\Actions\V1\Role\DeleteRoleAction;
use App\Actions\V1\Role\ForceDeleteRoleAction;
use App\Actions\V1\Role\IndexRoleAction;
use App\Actions\V1\Role\RestoreRoleAction;
use App\Actions\V1\Role\UpdateRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Role\DeleteRoleRequest;
use App\Http\Requests\Role\ForceDeleteRoleRequest;
use App\Http\Requests\Role\RestoreRoleRequest;
use App\Http\Requests\Role\RoleQueryRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\Api\V1\Role\RoleResource;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API role management (Phase 6 — closes the gap where roles could only be
 * managed from a browser).
 *
 * No logic lives here. The actions, the Form Requests and the authorization are
 * the ones the web controller already uses, so a second implementation of
 * "may this caller edit a role" is the one thing this class cannot contain. The
 * only difference from the web path is the shape of the answer: JSON instead of
 * a redirect.
 *
 * That reuse is also what makes the guards apply here without being restated:
 * P6-E3 (a system role's permission set is code-defined) and the
 * last-superadmin invariant both live in PersistsRole and the Role actions, so
 * this endpoint inherits them rather than re-implementing them.
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
    ) {
    }

    /**
     * List roles with their user and permission counts.
     *
     * @param  RoleQueryRequest  $request
     */
    public function index(RoleQueryRequest $request): JsonResponse
    {
        $roles = $this->indexAction->run(
            search: (string) $request->validated('search', ''),
            sort: (string) $request->validated('sort', 'name'),
            direction: (string) $request->validated('direction', 'desc'),
            perPage: (int) $request->validated('per_page', 10),
            trashed: (bool) $request->validated('trashed', false),
            viewer: $request->user(),
        );

        return $this->respond('', 200, [
            'roles' => RoleResource::collection($roles),
            'pagination' => [
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
            ],
        ]);
    }

    /**
     * Create a role.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->createAction->run($request->validated(), $request->user());

        return $this->respond('Role created successfully.', 201, [
            'role' => new RoleResource($role),
        ]);
    }

    /**
     * Show one role, with its permission names.
     */
    public function show(Request $request, Role $role): JsonResponse
    {
        // Same visibility filter as the listing. Without it this endpoint would
        // be a way to read the superadmin role by id for a caller who cannot
        // see it in the list — an id-guessable hole in a list-scoped rule.
        if (! $this->canSee($role, $request->user())) {
            abort(403);
        }

        $role->load('permissions')->loadCount(['users', 'permissions']);

        return $this->respond('', 200, ['role' => new RoleResource($role)]);
    }

    /**
     * Update a role, including its permission set.
     *
     * A system role is refused by the action and, for its permissions, by
     * PersistsRole — so the 422 below is the documented answer, not a surprise.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->updateAction->run($role, $request->validated(), $request->user());

        return $this->respond('Role updated successfully.', 200, [
            'role' => new RoleResource($role->fresh()->load('permissions')->loadCount(['users', 'permissions'])),
        ]);
    }

    /**
     * Move a role to the trash.
     */
    public function destroy(DeleteRoleRequest $request, Role $role): JsonResponse
    {
        $this->deleteAction->run($role, $request->user(), force: true);

        return $this->respond('Role moved to trash.');
    }

    /**
     * Restore a trashed role.
     */
    public function restore(RestoreRoleRequest $request, int $role): JsonResponse
    {
        $model = Role::onlyTrashed()->findOrFail($role);

        $this->restoreAction->run($model, $request->user());

        return $this->respond('Role restored successfully.', 200, [
            'role' => new RoleResource($model),
        ]);
    }

    /**
     * Permanently delete a trashed role.
     */
    public function forceDelete(ForceDeleteRoleRequest $request, int $role): JsonResponse
    {
        $model = Role::onlyTrashed()->findOrFail($role);

        $this->forceDeleteAction->run($model, $request->user());

        return $this->respond('Role permanently deleted.');
    }

    /**
     * Whether this viewer may see this role, per RoleLookup::visibleTo().
     */
    private function canSee(Role $role, ?User $viewer): bool
    {
        return RoleLookup::visibleTo($viewer)->contains('id', $role->id);
    }
}
