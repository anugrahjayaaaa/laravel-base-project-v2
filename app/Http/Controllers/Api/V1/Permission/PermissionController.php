<?php

namespace App\Http\Controllers\Api\V1\Permission;

use App\Actions\V1\Permission\PermissionIndexAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Permission\PermissionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API permission catalogue (Phase 6 — the other half of the missing API).
 *
 * Index only. The catalogue is defined in code by PermissionCatalog and there is
 * no write path in the application, so exposing writes here would mean inventing
 * behaviour nobody asked for. P6-C7's read-only decision, carried to the API.
 */
class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionIndexAction $indexAction,
    ) {
    }

    /**
     * List the permission catalogue with the roles holding each permission.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $this->indexAction->run(
            search: (string) $request->query('search', ''),
            sort: $request->query('sort'),
            direction: (string) $request->query('direction', 'asc'),
            perPage: (int) $request->query('per_page', 10),
        );

        /** @var \\Illuminate\Contracts\\Pagination\\LengthAwarePaginator $permissions */
        $permissions = $data['permissions'];

        return $this->respond('', 200, [
            'permissions' => PermissionResource::collection($permissions),
            'pagination' => [
                'current_page' => $permissions->currentPage(),
                'last_page' => $permissions->lastPage(),
                'per_page' => $permissions->perPage(),
                'total' => $permissions->total(),
            ],
        ]);
    }
}
