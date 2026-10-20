<?php

namespace App\Http\Resources\Api\V1\Role;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Role representation for API clients.
 *
 * `permissions` is a flat list of NAMES, matching what a client sends back on
 * update — the write path takes ids because the matrix partial posts them, but a
 * client that has just read this payload should be able to round-trip it without
 * a second lookup.
 *
 * `is_system` is included on purpose: it is the client's only way to know which
 * roles it must not attempt to edit or delete, and finding out by getting a 422
 * back is a worse way to learn the same thing.
 *
 * @property-read \App\Models\Role $resource
 */
class RoleResource extends JsonResource
{
    /**
     * Transform the role into an API response array.
     *
     * `users_count` / `permissions_count` are absent, not zero, when the caller
     * did not ask for counts (`whenCounted`) — a zero would be a claim about the
     * role that this response never verified.
     *
     * @param  Request  $request
     * @return array{id: int, name: string, is_system: bool, users_count?: int, permissions_count?: int, permissions: array<int, string>|null, created_at: string|null, updated_at: string|null, deleted_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'is_system'   => $this->is_system,
            'users_count' => $this->whenCounted('users'),
            'permissions_count' => $this->whenCounted('permissions'),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name')->values(),
                null
            ),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
            'deleted_at'  => $this->deleted_at,
        ];
    }
}
