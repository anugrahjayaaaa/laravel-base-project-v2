<?php

namespace App\Http\Resources\Api\V1\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One membership period.
 *
 * `role_name` is the role THIS row applied, copied at activation — not the
 * tier's current role, which is the fact the cancel path depends on.
 *
 * @property-read \App\Models\MemberSubscription $resource
 */
class MemberSubscriptionResource extends JsonResource
{
    /**
     * @return array{id: int, member_id: int, tier_id: int, tier_name: string|null, status: string, role_name: string|null, starts_at: string, expires_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'member_id' => $this->resource->member_id,
            'tier_id' => $this->resource->member_tier_id,
            'tier_name' => $this->whenLoaded('tier', fn () => $this->resource->tier?->name),
            'status' => $this->resource->status,
            'role_name' => $this->resource->role_name,
            'starts_at' => $this->resource->starts_at,
            'expires_at' => $this->resource->expires_at,
        ];
    }
}
