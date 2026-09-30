<?php

namespace App\Http\Resources\Api\V1\Permission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Permission representation for API clients.
 *
 * Read-only by design (P6-C7): the catalogue is defined in code by
 * PermissionCatalog, so there is no write path to expose here — not a gap, a
 * decision. `roles_count` is the one aggregate the catalogue page shows, and
 * `whereDoesntHave('roles')` is what marks a permission as unused, so both are
 * computed by PermissionIndexAction in two statements rather than per row.
 *
 * No group or description column: the permissions table carries name and
 * guard_name only, and PermissionCatalog::grouped() splits on the dot in code
 * rather than storing a second writer for it. Emitting them here as nulls would
 * be inventing a shape the web view does not have either.
 *
 * @property-read \Spatie\Permission\Models\Permission $resource
 */
class PermissionResource extends JsonResource
{
    /**
     * Transform the permission into an API response array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'roles_count'  => $this->whenCounted('roles'),
            'roles'        => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values(), null),
        ];
    }
}
