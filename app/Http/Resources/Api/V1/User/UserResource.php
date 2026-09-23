<?php

namespace App\Http\Resources\Api\V1\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the user model into an API response array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
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