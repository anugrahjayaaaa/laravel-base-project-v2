<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The loyalty profile on an existing account (Phase 18, D-2).
 *
 * Not a second identity: `user_id` is unique and every authorisation decision
 * still resolves through the `User`. Tier roles are synced onto the User, not
 * onto this row.
 *
 * @property int $id
 * @property int $user_id
 * @property string $member_number
 * @property int $points_balance
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $joined_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
#[Fillable([
    'user_id',
    'member_number',
    'points_balance',
    'is_active',
    'joined_at',
    'notes',
])]
class Member extends Model
{
    use Auditable;
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'points_balance' => 'integer',
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * The next member number: `{prefix}-NNNNNN`, one above the highest already
     * issued under that prefix. The prefix is the `member_number_prefix` setting;
     * changing it starts a fresh series and leaves the old numbers alone.
     *
     * Call this inside `DB::transaction()` when the value is about to be written:
     * `lockForUpdate()` is what keeps two concurrent creates from issuing the
     * same number, and outside a transaction MySQL releases the lock immediately.
     */
    public static function nextMemberNumber(): string
    {
        $prefix = SystemSetting::getString('member_number_prefix', 'MBR');

        // Numbers in a series only ever go up, so the newest row carries the
        // highest suffix — no cross-database numeric cast needed to find it.
        $last = static::withTrashed()
            ->where('member_number', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('member_number');

        $sequence = $last === null ? 0 : (int) substr($last, strlen($prefix) + 1);

        return $prefix.'-'.str_pad((string) ($sequence + 1), 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MemberSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MemberSubscription::class)->latest('starts_at');
    }

    /** @return HasMany<MemberPointHistory, $this> */
    public function pointHistories(): HasMany
    {
        return $this->hasMany(MemberPointHistory::class)->latest();
    }
}
