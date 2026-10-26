<?php

namespace App\Http\Resources\Api\V1\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One user, as the API exposes it.
 *
 * No relations, no roles, no permissions: a client that needs them asks the
 * endpoints that own them. The keys are the columns a caller can filter on or
 * must branch on (`is_locked`, `must_change_password`), not a dump of the row.
 *
 * @property-read \App\Models\User $resource
 */
class UserResource extends JsonResource
{
    /**
     * Transform the user model into an API response array.
     *
     * @param  Request  $request
     * @return array{id: int, name: string, email: string, username: string, is_active: bool, is_locked: bool, email_verified_at: string|null, must_change_password: bool, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'username'   => $this->username,
            'is_active'  => $this->is_active,
            'is_locked'  => $this->is_locked,
            'email_verified_at' => $this->email_verified_at,
            'must_change_password' => $this->must_change_password,
            'created_at' => $this->created_at,
        ];
    }
}
