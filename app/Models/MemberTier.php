<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\SystemRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A membership tier: price, duration, point multiplier and the role an
 * activation applies (Phase 18).
 *
 * `role_name` is a reference validated against `RoleLookup::guard()` by the
 * Form Request — Spatie owns the roles table per guard, so no FK here.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $role_name
 * @property string $price
 * @property int $duration_days
 * @property string $point_multiplier
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable([
    'name',
    'slug',
    'role_name',
    'price',
    'duration_days',
    'point_multiplier',
    'is_active',
    'sort_order',
])]
class MemberTier extends Model
{
    use Auditable;
    /** @use HasFactory<\Database\Factories\MemberTierFactory> */
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'point_multiplier' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<MemberSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MemberSubscription::class);
    }

    /**
     * The roles a tier may apply, as `name => name` picker options.
     *
     * `superadmin` is excluded for every viewer. A tier role is synced onto the
     * account when its subscription activates, so offering it would hand control
     * of the whole app to whoever buys the tier — and the buyer, not the
     * operator, decides who that is. Unlike `RoleLookup::visibleTo()` this is not
     * about who may SEE a role; the refusal is absolute, so it belongs to the
     * tier, not to the viewer.
     *
     * Both the picker and `MemberTierRequest` read this set — one filter, two
     * call sites, which is why it lives here and not in either of them.
     *
     * @return array<string, string>
     */
    public static function assignableRoleOptions(): array
    {
        return RoleLookup::assignable()
            ->where('name', '!=', SystemRole::SUPERADMIN)
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * @param  Builder<MemberTier>  $query
     * @return Builder<MemberTier>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
