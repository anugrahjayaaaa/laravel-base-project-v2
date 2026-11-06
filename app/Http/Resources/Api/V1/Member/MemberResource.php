<?php

namespace App\Http\Resources\Api\V1\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One loyalty profile, as the API exposes it.
 *
 * Columns only — no badge labels, no derived tier or expiry. Those are the
 * web table's presentation of the same rows, and a client reads
 * `subscriptions` instead of this class re-implementing the pick-one-current
 * rule a second time.
 *
 * @property-read \App\Models\Member $resource
 */
class MemberResource extends JsonResource
{
    /**
     * @return array{id: int, member_number: string, user_id: int, user_name: string|null, is_active: bool, points_balance: int, joined_at: string, notes: string|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'member_number' => $this->resource->member_number,
            'user_id' => $this->resource->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->resource->user?->name),
            'is_active' => $this->resource->is_active,
            'points_balance' => $this->resource->points_balance,
            'joined_at' => $this->resource->joined_at,
            'notes' => $this->resource->notes,
            'created_at' => $this->resource->created_at,
        ];
    }
}
