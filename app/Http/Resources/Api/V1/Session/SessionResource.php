<?php

namespace App\Http\Resources\Api\V1\Session;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Sanctum token, as the client that owns it may see it.
 *
 * Deliberately no `token` and no `hash`: the plaintext token is returned once,
 * at login, and the hash is the credential itself. A session list is a list of
 * *places you are signed in*, not a way to recover a working token.
 *
 * `abilities` is the raw array Sanctum stored (`['*']` for a full-scope token),
 * not a reshaped map — the client that needs to show "this device can read your
 * data" applies its own meaning to the same strings the write path accepts.
 *
 * @property-read \Laravel\Sanctum\PersonalAccessToken $resource
 */
class SessionResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array{id: int, name: string, abilities: array<int, string>, last_used_at: string|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
