<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subscription period for a member (Phase 18).
 *
 * `role_name` is copied at activation and never read through the tier, so a
 * later tier edit cannot make the expiry sweep revoke the wrong role.
 *
 * @property int $id
 * @property int $member_id
 * @property int $member_tier_id
 * @property string $status
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property string|null $role_name
 * @property \Illuminate\Support\Carbon|null $paused_at
 */
#[Fillable([
    'member_id',
    'member_tier_id',
    'status',
    'starts_at',
    'expires_at',
    'role_name',
    'paused_at',
])]
class MemberSubscription extends Model
{
    use Auditable;
    /** @use HasFactory<\Database\Factories\MemberSubscriptionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<MemberTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(MemberTier::class, 'member_tier_id');
    }

    /**
     * Rows that count as a live subscription today.
     *
     * `paused_at` is excluded on purpose: an admin-deactivated member stops
     * consuming the period they bought, so their row is neither an active
     * subscription nor an expired one.
     *
     * @param  Builder<MemberSubscription>  $query
     * @return Builder<MemberSubscription>
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNull('paused_at')
            ->where('expires_at', '>', now());
    }

    /** Whether the row has really lapsed — a sweep may not have run yet. */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->paused_at === null
            && $this->expires_at->isFuture();
    }
}
