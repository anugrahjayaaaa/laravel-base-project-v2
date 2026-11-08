<?php

namespace App\Http\Resources\Api\V1\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One membership tier.
 *
 * `price` and `point_multiplier` leave as the decimal strings the database
 * column holds — the web list runs them through `App\Support\Money` because a
 * reader needs the symbol, a client needs the amount.
 *
 * @property-read \App\Models\MemberTier $resource
 */
class MemberTierResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, slug: string, role_name: string|null, price: string, duration_days: int, point_multiplier: string, is_active: bool, sort_order: int, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'role_name' => $this->resource->role_name,
            'price' => $this->resource->price,
            'duration_days' => $this->resource->duration_days,
            'point_multiplier' => $this->resource->point_multiplier,
            'is_active' => $this->resource->is_active,
            'sort_order' => $this->resource->sort_order,
            'created_at' => $this->resource->created_at,
        ];
    }
}
