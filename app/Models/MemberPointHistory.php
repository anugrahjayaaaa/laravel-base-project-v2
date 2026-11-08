<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One points event in a member's ledger (Phase 18).
 *
 * The ledger is append-only: a correction is a new signed row, never an
 * update, and `balance_after` is written with the delta in the same
 * transaction so the trail can be audited without recomputation.
 *
 * @property int $id
 * @property int $member_id
 * @property int $delta
 * @property int $balance_after
 * @property string $reason
 * @property int|null $actor_id
 */
#[Fillable([
    'member_id',
    'delta',
    'balance_after',
    'reason',
    'actor_id',
    'reference_type',
    'reference_id',
])]
class MemberPointHistory extends Model
{
    use Auditable;
    /** @use HasFactory<\Database\Factories\MemberPointHistoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
