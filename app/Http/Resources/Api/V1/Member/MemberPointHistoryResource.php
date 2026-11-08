<?php

namespace App\Http\Resources\Api\V1\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a member's points ledger.
 *
 * `balance_after` is the stored value, never a recomputed running total — the
 * append-only ledger is the audit record, so the API hands out exactly what it
 * holds.
 *
 * @property-read \App\Models\MemberPointHistory $resource
 */
class MemberPointHistoryResource extends JsonResource
{
    /**
     * @return array{id: int, member_id: int, delta: int, balance_after: int, reason: string, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'member_id' => $this->resource->member_id,
            'delta' => $this->resource->delta,
            'balance_after' => $this->resource->balance_after,
            'reason' => $this->resource->reason,
            'created_at' => $this->resource->created_at,
        ];
    }
}
